<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\ContentOrigin;
use App\Http\Controllers\Controller;
use App\Models\AiActivityLog;
use App\Models\AiAgent;
use App\Models\AiContentDraft;
use App\Models\AiCredential;
use App\Models\AiModerationAssessment;
use App\Models\AiReplyTask;
use App\Models\AiUsageRecord;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\User;
use App\Services\Ai\AiAutomationControlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiOverviewController extends Controller
{
    public function __construct(
        private readonly AiAutomationControlService $control,
    ) {}

    public function index(Request $request): Response
    {
        $days = max(7, min(90, (int) $request->integer('days', 30)));
        $since = now()->subDays($days);

        $totalCreds = AiCredential::query()->count();
        $enabledCreds = AiCredential::query()->where('is_enabled', true)->count();
        $healthyCreds = AiCredential::query()->where('is_enabled', true)->where('health_status', 'healthy')->count();
        $errorCreds = AiCredential::query()->where('is_enabled', true)->whereIn('health_status', ['auth_error', 'degraded'])->count();

        $connectionStatus = 'setup_required';
        if ($enabledCreds > 0) {
            if ($healthyCreds > 0) {
                $connectionStatus = 'connected';
            } elseif ($errorCreds > 0) {
                $connectionStatus = 'degraded';
            } else {
                $connectionStatus = 'untested';
            }
        }

        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('payload', 'like', '%App\\\\Jobs\\\\Ai\\\\%')->count()
            : 0;

        $usageByModel = DB::table('ai_usage_records')
            ->leftJoin('ai_models', 'ai_models.id', '=', 'ai_usage_records.model_id')
            ->where('ai_usage_records.created_at', '>=', $since)
            ->select(
                DB::raw("COALESCE(ai_models.model_id, 'unknown') as model_name"),
                DB::raw('COUNT(*) as requests'),
                DB::raw('SUM(CASE WHEN ai_usage_records.status = "success" THEN 1 ELSE 0 END) as successes'),
                DB::raw('COALESCE(SUM(ai_usage_records.input_tokens), 0) as input_tokens'),
                DB::raw('COALESCE(SUM(ai_usage_records.output_tokens), 0) as output_tokens'),
                DB::raw('COALESCE(SUM(ai_usage_records.thinking_tokens), 0) as thinking_tokens'),
                DB::raw('COALESCE(SUM(ai_usage_records.estimated_cost_micro_usd), 0) as estimated_cost_micro_usd'),
                DB::raw('MIN(ai_usage_records.pricing_verified) as pricing_verified'),
            )
            ->groupBy('ai_models.model_id')
            ->orderByDesc('requests')
            ->get()
            ->map(static fn (\stdClass $r): array => [
                'model_name' => (string) $r->model_name,
                'requests' => (int) $r->requests,
                'successes' => (int) $r->successes,
                'input_tokens' => (int) $r->input_tokens,
                'output_tokens' => (int) $r->output_tokens,
                'thinking_tokens' => (int) $r->thinking_tokens,
                'estimated_cost_micro_usd' => (int) $r->estimated_cost_micro_usd,
                'pricing_verified' => (bool) $r->pricing_verified,
            ])
            ->values()
            ->all();

        $usageByLanguage = DB::table('ai_usage_records')
            ->where('created_at', '>=', $since)
            ->whereNotNull('language_code')
            ->select(
                'language_code',
                DB::raw('COUNT(*) as requests'),
                DB::raw('COALESCE(SUM(input_tokens + output_tokens + thinking_tokens), 0) as tokens'),
            )
            ->groupBy('language_code')
            ->orderByDesc('requests')
            ->limit(15)
            ->get()
            ->map(static fn (\stdClass $r): array => [
                'language_code' => (string) $r->language_code,
                'requests' => (int) $r->requests,
                'tokens' => (int) $r->tokens,
            ])
            ->values()
            ->all();

        $recentActivity = AiActivityLog::query()
            ->with(['actor:id,username', 'agent:id,display_name,username'])
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(static fn (AiActivityLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_username' => $log->actor?->username,
                'agent_name' => $log->agent?->display_name,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        $recentUsage = AiUsageRecord::query()
            ->with(['agent:id,display_name', 'model:id,model_id'])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(static fn (AiUsageRecord $u): array => [
                'id' => $u->id,
                'task_type' => $u->task_type,
                'model_name' => $u->model !== null ? $u->model->model_id : 'unknown',
                'project_id' => $u->project_id,
                'agent_name' => $u->agent?->display_name,
                'language_code' => $u->language_code,
                'status' => $u->status,
                'http_status' => $u->http_status,
                'input_tokens' => $u->input_tokens,
                'output_tokens' => $u->output_tokens,
                'thinking_tokens' => $u->thinking_tokens,
                'estimated_cost_micro_usd' => $u->estimated_cost_micro_usd,
                'pricing_verified' => $u->pricing_verified,
                'duration_ms' => $u->duration_ms,
                'error_code' => $u->error_code,
                'created_at' => $u->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Ai/Overview', [
            'days' => $days,
            'connectionStatus' => $connectionStatus,
            'control' => $this->control->summaryPayload(),
            'kpis' => [
                'total_credentials' => $totalCreds,
                'enabled_credentials' => $enabledCreds,
                'healthy_credentials' => $healthyCreds,
                'active_agents' => AiAgent::query()->where('is_enabled', true)->count(),
                'total_agents' => AiAgent::query()->count(),
                'drafts_pending_review' => AiContentDraft::query()->where('status', AiDraftStatus::PendingReview->value)->count(),
                'drafts_approved' => AiContentDraft::query()->where('status', AiDraftStatus::Approved->value)->count(),
                'drafts_scheduled' => AiContentDraft::query()->where('status', AiDraftStatus::Scheduled->value)->count(),
                'drafts_waiting_quota' => AiContentDraft::query()->where('status', AiDraftStatus::WaitingForQuota->value)->count(),
                'published_ai_posts' => Post::query()->where('content_origin', ContentOrigin::AiGenerated->value)->count(),
                'ai_translations' => SeoContentTranslation::query()->where('is_ai_assisted', true)->count(),
                'replies_pending' => AiReplyTask::query()->where('status', 'pending_review')->count(),
                'moderation_pending' => AiModerationAssessment::query()->where('status', 'pending_review')->count(),
                'failed_jobs' => $failedJobs,
            ],
            'usageByModel' => $usageByModel,
            'usageByLanguage' => $usageByLanguage,
            'recentActivity' => $recentActivity,
            'recentUsage' => $recentUsage,
        ]);
    }

    public function toggleEmergencyStop(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stop' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:240'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $stop = (bool) $validated['stop'];
        $this->control->triggerEmergencyStop($stop, $actor, $validated['reason'] ?? null);

        return back()->with('status', $stop
            ? __('AI Emergency Stop ACTIVATED. All AI generation, publishing, translation, and reply tasks are halted immediately.')
            : __('AI Emergency Stop released. Individual feature switches remain under your control.'));
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ai_generation_enabled' => ['required', 'boolean'],
            'ai_publishing_enabled' => ['required', 'boolean'],
            'ai_translation_enabled' => ['required', 'boolean'],
            'ai_replies_enabled' => ['required', 'boolean'],
            'ai_moderation_enabled' => ['required', 'boolean'],
            'ai_research_enabled' => ['required', 'boolean'],
            'compliance_user_facing_verified' => ['required', 'boolean'],
            'compliance_eea_paid_tier_verified' => ['required', 'boolean'],
            'require_human_review_before_publish' => ['required', 'boolean'],
            'allow_paid_model_fallback' => ['required', 'boolean'],
            'max_generated_drafts_per_day' => ['required', 'integer', 'min:1', 'max:100'],
            'max_published_posts_per_day' => ['required', 'integer', 'min:1', 'max:50'],
            'max_translations_per_day' => ['required', 'integer', 'min:1', 'max:150'],
            'max_ai_replies_per_day' => ['required', 'integer', 'min:0', 'max:100'],
            'max_ai_replies_per_post' => ['required', 'integer', 'min:1', 'max:5'],
            'max_daily_tokens' => ['required', 'integer', 'min:10000', 'max:50000000'],
            'max_daily_cost_cents' => ['required', 'integer', 'min:0', 'max:500000'],
            'max_monthly_cost_cents' => ['required', 'integer', 'min:0', 'max:5000000'],
            'max_ai_feed_share_percent' => ['required', 'integer', 'min:5', 'max:50'],
        ]);

        if ((bool) $validated['ai_replies_enabled'] && ! (bool) $validated['compliance_user_facing_verified']) {
            throw ValidationException::withMessages([
                'ai_replies_enabled' => [__('You must confirm the User-Facing AI Compliance Review before enabling automated AI comment replies.')],
            ]);
        }

        /** @var User $actor */
        $actor = $request->user();

        $boolKeys = [
            'ai_generation_enabled' => 'switches',
            'ai_publishing_enabled' => 'switches',
            'ai_translation_enabled' => 'switches',
            'ai_replies_enabled' => 'switches',
            'ai_moderation_enabled' => 'switches',
            'ai_research_enabled' => 'switches',
            'compliance_user_facing_verified' => 'compliance',
            'compliance_eea_paid_tier_verified' => 'compliance',
            'require_human_review_before_publish' => 'compliance',
            'allow_paid_model_fallback' => 'compliance',
        ];

        foreach ($boolKeys as $key => $group) {
            $this->control->set($key, (bool) $validated[$key], $group, 'boolean', $actor);
        }

        $intKeys = [
            'max_generated_drafts_per_day',
            'max_published_posts_per_day',
            'max_translations_per_day',
            'max_ai_replies_per_day',
            'max_ai_replies_per_post',
            'max_daily_tokens',
            'max_daily_cost_cents',
            'max_monthly_cost_cents',
            'max_ai_feed_share_percent',
        ];

        foreach ($intKeys as $key) {
            $this->control->set($key, (int) $validated[$key], 'limits', 'integer', $actor);
        }

        $this->control->logActivity('ai.settings.updated', $actor, null, null, null, [
            'ai_generation_enabled' => (bool) $validated['ai_generation_enabled'],
            'ai_publishing_enabled' => (bool) $validated['ai_publishing_enabled'],
            'ai_replies_enabled' => (bool) $validated['ai_replies_enabled'],
            'allow_paid_model_fallback' => (bool) $validated['allow_paid_model_fallback'],
        ]);

        return back()->with('status', __('AI automation guardrails and compliance settings saved.'));
    }
}
