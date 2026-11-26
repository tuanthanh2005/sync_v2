<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AiPromptType;
use App\Enums\PostCategory;
use App\Http\Controllers\Controller;
use App\Jobs\Ai\GenerateAiContentJob;
use App\Models\AiAgent;
use App\Models\AiContentDraft;
use App\Models\AiContentPlan;
use App\Models\AiContentSchedule;
use App\Models\AiContentSource;
use App\Models\AiPromptTemplate;
use App\Models\Community;
use App\Models\User;
use App\Services\Ai\AiAutomationControlService;
use App\Services\Ai\AiContentGeneratorService;
use App\Services\Ai\AiPublishingAndSchedulerService;
use App\Services\Ai\AiSourceResearchService;
use App\Services\System\LanguageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiContentWorkflowController extends Controller
{
    public function __construct(
        private readonly AiAutomationControlService $control,
        private readonly AiSourceResearchService $sourceService,
        private readonly AiContentGeneratorService $generator,
        private readonly AiPublishingAndSchedulerService $publisher,
        private readonly LanguageService $languages,
    ) {}

    public function index(Request $request): Response
    {
        $statusFilter = trim($request->string('status')->toString());
        $languageFilter = trim($request->string('language')->toString());
        $search = trim($request->string('search')->toString());

        $draftsQuery = AiContentDraft::query()
            ->with(['agent:id,display_name,username', 'model:id,model_id,display_name', 'reviewer:id,username', 'community:id,name'])
            ->latest('id');

        if ($statusFilter !== '' && AiDraftStatus::tryFrom($statusFilter) !== null) {
            $draftsQuery->where('status', $statusFilter);
        }

        if ($languageFilter !== '') {
            $draftsQuery->where('language_code', $languageFilter);
        }

        if ($search !== '') {
            $escaped = addcslashes($search, '\\%_');
            $draftsQuery->where(static function ($q) use ($escaped): void {
                $q->where('title', 'like', "%{$escaped}%")
                    ->orWhere('body', 'like', "%{$escaped}%");
            });
        }

        $drafts = $draftsQuery->limit(50)->get()->map(static fn (AiContentDraft $d): array => [
            'id' => $d->id,
            'title' => $d->title,
            'body' => $d->body,
            'summary' => $d->summary,
            'language_code' => $d->language_code,
            'content_type' => $d->content_type,
            'category' => $d->category,
            'suggested_tags' => $d->suggested_tags ?? [],
            'seo_title' => $d->seo_title,
            'seo_description' => $d->seo_description,
            'source_references' => $d->source_references ?? [],
            'factual_claims' => $d->factual_claims ?? [],
            'status' => $d->status->value,
            'status_label' => $d->status->label(),
            'quality_status' => $d->quality_status,
            'quality_score' => $d->quality_score,
            'quality_report' => $d->quality_report,
            'agent_id' => $d->agent_id,
            'agent_name' => $d->agent?->display_name,
            'community_name' => $d->community?->name,
            'model_name' => $d->model?->display_name,
            'reviewer_username' => $d->reviewer?->username,
            'review_notes' => $d->review_notes,
            'published_post_id' => $d->published_post_id,
            'published_post_url' => $d->published_post_id !== null ? route('posts.show', $d->published_post_id, false) : null,
            'scheduled_for' => $d->scheduled_for?->toIso8601String(),
            'input_tokens' => $d->input_tokens,
            'output_tokens' => $d->output_tokens,
            'error_message' => $d->error_message,
            'created_at' => $d->created_at?->toIso8601String(),
        ])->values()->all();

        $plans = AiContentPlan::query()
            ->with(['agent:id,display_name', 'community:id,name'])
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(static fn (AiContentPlan $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'agent_name' => $p->agent?->display_name,
                'community_name' => $p->community?->name,
                'target_language' => $p->target_language,
                'content_type' => $p->content_type,
                'category' => $p->category,
                'topic' => $p->topic,
                'posts_per_day' => $p->posts_per_day,
                'min_interval_minutes' => $p->min_interval_minutes,
                'timezone' => $p->timezone,
                'is_active' => $p->is_active,
                'last_generated_at' => $p->last_generated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $sources = AiContentSource::query()
            ->latest('id')
            ->limit(30)
            ->get(['id', 'title', 'url', 'publisher', 'source_type', 'verification_status', 'retrieved_at'])
            ->map(static fn (AiContentSource $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'url' => $s->url,
                'publisher' => $s->publisher,
                'source_type' => $s->source_type,
                'verification_status' => $s->verification_status,
                'retrieved_at' => $s->retrieved_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $schedules = AiContentSchedule::query()
            ->with(['draft:id,title,language_code', 'agent:id,display_name'])
            ->latest('scheduled_for')
            ->limit(40)
            ->get()
            ->map(static fn (AiContentSchedule $s): array => [
                'id' => $s->id,
                'draft_id' => $s->draft_id,
                'draft_title' => $s->draft?->title,
                'language_code' => $s->draft?->language_code,
                'agent_name' => $s->agent?->display_name,
                'scheduled_for' => $s->scheduled_for->toIso8601String(),
                'timezone' => $s->timezone,
                'status' => $s->status,
                'published_post_id' => $s->published_post_id,
                'last_error' => $s->last_error,
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Ai/ContentWorkflow', [
            'filters' => [
                'status' => $statusFilter,
                'language' => $languageFilter,
                'search' => $search,
            ],
            'emergencyStopped' => $this->control->isEmergencyStopped(),
            'drafts' => $drafts,
            'plans' => $plans,
            'sources' => $sources,
            'schedules' => $schedules,
            'agents' => AiAgent::query()->where('is_enabled', true)->orderBy('display_name')->get(['id', 'display_name', 'username', 'default_language']),
            'prompts' => AiPromptTemplate::query()->where('prompt_type', AiPromptType::GeneratePost->value)->where('is_enabled', true)->get(['id', 'name']),
            'communities' => Community::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'languages' => $this->languages->publicEnabledLanguages(),
            'categories' => PostCategory::options(),
            'statuses' => array_map(static fn (AiDraftStatus $s): array => [
                'value' => $s->value,
                'label' => $s->label(),
            ], AiDraftStatus::cases()),
        ]);
    }

    public function storeSource(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:240'],
            'url' => ['required', 'url', 'max:1000'],
            'publisher' => ['nullable', 'string', 'max:120'],
            'source_type' => ['required', Rule::in(['documentation', 'official_blog', 'research', 'rss', 'reference'])],
            'excerpt' => ['nullable', 'string', 'max:5000'],
            'fetch_excerpt' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->sourceService->storeSource($validated, $actor);

        return back()->with('status', __('Verified reference source saved cleanly (SSRF-checked).'));
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:140'],
            'agent_id' => ['required', 'integer', 'exists:ai_agents,id'],
            'community_id' => ['nullable', 'integer', 'exists:communities,id'],
            'target_language' => ['required', 'string', 'max:12'],
            'content_type' => ['required', Rule::in(['knowledge', 'guide', 'discussion', 'explainer', 'news_summary', 'comparison'])],
            'category' => ['required', 'string', 'max:32'],
            'topic' => ['required', 'string', 'min:5', 'max:240'],
            'posts_per_day' => ['required', 'integer', 'min:1', 'max:5'],
            'min_interval_minutes' => ['sometimes', 'integer', 'min:60', 'max:10080'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $plan = AiContentPlan::query()->create([
            'name' => $validated['name'],
            'agent_id' => (int) $validated['agent_id'],
            'community_id' => $validated['community_id'] ?? null,
            'target_language' => $validated['target_language'],
            'content_type' => $validated['content_type'],
            'category' => $validated['category'],
            'topic' => $validated['topic'],
            'posts_per_day' => (int) $validated['posts_per_day'],
            'min_interval_minutes' => (int) ($validated['min_interval_minutes'] ?? 360),
            'timezone' => $validated['timezone'] ?? 'UTC',
            'is_active' => true,
            'created_by' => $actor->id,
        ]);

        $this->control->logActivity('ai.plan.created', $actor, $plan->agent_id, AiContentPlan::class, $plan->id, [
            'name' => $plan->name,
            'posts_per_day' => $plan->posts_per_day,
        ]);

        return back()->with('status', __('AI Content Plan created. Generated drafts will still pass Quality Gate & Human Review.'));
    }

    public function togglePlan(Request $request, AiContentPlan $plan): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $plan->forceFill(['is_active' => ! $plan->is_active])->save();

        $this->control->logActivity('ai.plan.toggled', $actor, $plan->agent_id, AiContentPlan::class, $plan->id, [
            'is_active' => $plan->is_active,
        ]);

        return back()->with('status', __('AI Content Plan status updated.'));
    }

    public function generateNow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'integer', 'exists:ai_agents,id'],
            'topic' => ['required', 'string', 'min:5', 'max:240'],
            'language_code' => ['required', 'string', 'max:12'],
            'content_type' => ['required', Rule::in(['knowledge', 'guide', 'discussion', 'explainer', 'news_summary', 'comparison'])],
            'category' => ['required', 'string', 'max:32'],
            'community_id' => ['nullable', 'integer', 'exists:communities,id'],
            'source_id' => ['nullable', 'integer', 'exists:ai_content_sources,id'],
            'prompt_template_id' => ['nullable', 'integer', 'exists:ai_prompt_templates,id'],
            'seo_context' => ['nullable', 'string', 'max:500'],
            'async' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $genPayload = [
            'agent_id' => (int) $validated['agent_id'],
            'topic' => (string) $validated['topic'],
            'language_code' => (string) $validated['language_code'],
            'content_type' => (string) $validated['content_type'],
            'category' => (string) $validated['category'],
            'community_id' => isset($validated['community_id']) ? (int) $validated['community_id'] : null,
            'source_id' => isset($validated['source_id']) ? (int) $validated['source_id'] : null,
            'prompt_template_id' => isset($validated['prompt_template_id']) ? (int) $validated['prompt_template_id'] : null,
            'seo_context' => isset($validated['seo_context']) ? (string) $validated['seo_context'] : null,
            'manual_trigger' => true,
        ];

        if (! empty($validated['async'])) {
            GenerateAiContentJob::dispatch($genPayload);

            return back()->with('status', __('AI draft generation job queued cleanly.'));
        }

        $draft = $this->generator->generateDraft($genPayload, $actor);

        if ($draft->status === AiDraftStatus::Failed || $draft->status === AiDraftStatus::WaitingForQuota) {
            return back()->withErrors([
                'topic' => $draft->error_message ?: __('AI draft generation could not complete.'),
            ]);
        }

        return back()->with('status', __('AI draft generated and sent to Human Review Queue (Quality Score: :score/100).', [
            'score' => $draft->quality_score,
        ]));
    }

    public function updateDraft(Request $request, AiContentDraft $draft): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:240'],
            'body' => ['required', 'string', 'min:30', 'max:15000'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:300'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->publisher->updateDraftContent(
            $draft,
            $validated['title'],
            $validated['body'],
            $validated['seo_title'] ?? null,
            $validated['seo_description'] ?? null,
            $actor,
        );

        return back()->with('status', __('AI draft updated and re-evaluated by Quality Gate.'));
    }

    public function reviewDraft(Request $request, AiContentDraft $draft): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'archived'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->publisher->reviewDraft($draft, $validated['decision'], $validated['notes'] ?? null, $actor);

        return back()->with('status', __('AI draft review recorded (:decision).', ['decision' => $validated['decision']]));
    }

    public function bulkReview(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'exists:ai_content_drafts,id'],
            'decision' => ['required', Rule::in(['approved', 'rejected', 'archived'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $drafts = AiContentDraft::query()->whereIn('id', $validated['ids'])->get();
        $processed = 0;

        foreach ($drafts as $draft) {
            if ($validated['decision'] === 'approved' && $draft->quality_status === 'failed') {
                continue;
            }
            $this->publisher->reviewDraft($draft, $validated['decision'], $validated['notes'] ?? null, $actor);
            $processed++;
        }

        return back()->with('status', __('Bulk review applied to :count drafts.', ['count' => $processed]));
    }

    public function scheduleDraft(Request $request, AiContentDraft $draft): RedirectResponse
    {
        $validated = $request->validate([
            'scheduled_for' => ['required', 'date', 'after:now'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->publisher->scheduleApprovedDraft(
            $draft,
            CarbonImmutable::parse($validated['scheduled_for']),
            $validated['timezone'] ?? 'UTC',
            $actor,
        );

        return back()->with('status', __('Approved AI draft scheduled for automated publication.'));
    }

    public function publishDraft(Request $request, AiContentDraft $draft): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $post = $this->publisher->publishApprovedDraft($draft, $actor, false);

        return back()->with('status', __('Approved AI draft published as Post #:id and queued for SEO indexing.', ['id' => $post->id]));
    }

    public function exportDraftsCsv(Request $request): StreamedResponse
    {
        $drafts = AiContentDraft::query()
            ->with(['agent:id,username'])
            ->latest('id')
            ->limit(500)
            ->get();

        $safeCell = static function (string $value): string {
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'".$value;
            }

            return $value;
        };

        return response()->streamDownload(static function () use ($drafts, $safeCell): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['ID', 'Agent', 'Language', 'Category', 'Status', 'QualityScore', 'QualityStatus', 'Title', 'PublishedPostID', 'CreatedAt']);
            foreach ($drafts as $d) {
                fputcsv($out, [
                    $d->id,
                    $safeCell($d->agent !== null ? $d->agent->username : ''),
                    $d->language_code,
                    $d->category,
                    $d->status->value,
                    $d->quality_score,
                    $d->quality_status,
                    $safeCell($d->title),
                    $d->published_post_id ?? '',
                    $d->created_at?->toIso8601String() ?? '',
                ]);
            }
            fclose($out);
        }, 'syncviet-ai-drafts-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
