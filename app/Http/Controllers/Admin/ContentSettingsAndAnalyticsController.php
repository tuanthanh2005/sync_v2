<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Social\RecalculateTrendingScoresJob;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\SpamEvent;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\Social\PostingPolicyConfigService;
use App\Services\Social\TrendingRankingService;
use App\Services\System\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentSettingsAndAnalyticsController extends Controller
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly PostingPolicyConfigService $policyConfig,
        private readonly TrendingRankingService $rankingService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User|null $actor */
        $actor = $request->user();
        abort_if(
            $actor === null || (
                ! $actor->isAdmin()
                && ! $actor->hasPermission('posts.settings.view')
                && ! $actor->hasPermission('feed.settings.view')
                && ! $actor->hasPermission('spam_events.view')
                && ! $actor->hasPermission('settings.view')
            ),
            403,
        );

        $eventType = trim($request->string('event_type')->toString());
        $riskLevel = trim($request->string('risk_level')->toString());
        $search = trim($request->string('search')->toString());

        $eventsQuery = SpamEvent::query()
            ->with(['user:id,name,username', 'post:id,title,content']);

        if ($eventType !== '' && $eventType !== 'all') {
            $eventsQuery->where('event_type', $eventType);
        }

        if ($riskLevel !== '' && $riskLevel !== 'all') {
            $eventsQuery->where('risk_level', $riskLevel);
        }

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $eventsQuery->whereHas('user', static function ($userQuery) use ($pattern): void {
                $userQuery->where('username', 'like', $pattern)
                    ->orWhere('name', 'like', $pattern);
            });
        }

        $paginator = $eventsQuery->latest('id')->paginate(15)->withQueryString();
        $since24h = now()->subHours(24);

        $stats = [
            'published_24h' => Post::query()
                ->where('moderation_status', ModerationStatus::Approved->value)
                ->where('published_at', '>=', $since24h)
                ->count(),
            'pending_review_count' => Post::query()
                ->where('moderation_status', ModerationStatus::Pending->value)
                ->count(),
            'rejected_count' => Post::query()
                ->where('moderation_status', ModerationStatus::Rejected->value)
                ->count(),
            'hidden_count' => Post::query()
                ->where('moderation_status', ModerationStatus::Hidden->value)
                ->count(),
            'spam_events_24h' => SpamEvent::query()
                ->where('created_at', '>=', $since24h)
                ->count(),
            'rate_limited_24h' => SpamEvent::query()
                ->where('event_type', 'rate_limited')
                ->where('created_at', '>=', $since24h)
                ->count(),
            'duplicate_blocked_24h' => SpamEvent::query()
                ->whereIn('event_type', ['duplicate_blocked', 'near_duplicate', 'coordinated_burst'])
                ->where('created_at', '>=', $since24h)
                ->count(),
            'ranked_active_count' => Post::query()
                ->publicVisible()
                ->where('trending_score', '>', 0)
                ->count(),
        ];

        $defaults = [];
        $allDefaults = SystemSettingService::defaults();
        foreach ($allDefaults as $key => $meta) {
            if (
                in_array($meta['group'], PostingPolicyConfigService::managedGroups(), true)
                || in_array($key, ['feature_posting', 'content_post_max_length', 'content_comment_max_length'], true)
            ) {
                $defaults[$key] = $meta['value'];
            }
        }

        $recentAuditLogs = AuditLog::query()
            ->with(['actor:id,name,username'])
            ->where(static function ($q): void {
                $q->where('action', 'system.settings_updated')
                    ->orWhere('action', 'like', 'admin.settings%')
                    ->orWhere('action', 'like', 'admin.post.%');
            })
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_username' => $log->actor?->username,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Content/PostFeedSettings', [
            'settings' => $this->policyConfig->allManagedValues(),
            'defaults' => $defaults,
            'stats' => $stats,
            'spamEvents' => [
                'data' => array_values(collect($paginator->items())->map(static fn (SpamEvent $ev): array => [
                    'id' => $ev->id,
                    'event_type' => $ev->event_type,
                    'action_context' => $ev->action_context,
                    'risk_score' => $ev->risk_score,
                    'risk_level' => $ev->risk_level,
                    'decision' => $ev->decision,
                    'trust_tier' => $ev->trust_tier,
                    'signals' => $ev->signals ?? [],
                    'user' => $ev->user === null ? null : [
                        'id' => $ev->user->id,
                        'name' => $ev->user->name,
                        'username' => $ev->user->username,
                    ],
                    'post_id' => $ev->post_id,
                    'created_at' => $ev->created_at->toIso8601String(),
                ])->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'event_type' => $eventType !== '' ? $eventType : 'all',
                'risk_level' => $riskLevel !== '' ? $riskLevel : 'all',
                'search' => $search,
            ],
            'recentAuditLogs' => $recentAuditLogs,
            'canManage' => $actor->isAdmin()
                || $actor->hasPermission('posts.settings.manage')
                || $actor->hasPermission('feed.settings.manage')
                || $actor->hasPermission('settings.update'),
            'canSimulate' => $actor->isAdmin()
                || $actor->hasPermission('ranking.analytics.view')
                || $actor->hasPermission('posts.settings.view')
                || $actor->hasPermission('analytics.view'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();
        abort_if(
            $actor === null || (
                ! $actor->isAdmin()
                && ! $actor->hasPermission('posts.settings.manage')
                && ! $actor->hasPermission('feed.settings.manage')
                && ! $actor->hasPermission('settings.update')
            ),
            403,
        );

        $validated = $request->validate([
            'feature_posting' => ['sometimes', 'boolean'],
            'content_post_max_length' => ['sometimes', 'integer', 'min:100', 'max:10000'],
            'content_comment_max_length' => ['sometimes', 'integer', 'min:100', 'max:5000'],
            'post_edits_per_day_limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'post_edit_window_minutes' => ['sometimes', 'integer', 'min:0', 'max:525600'],
            'posting_new_member_age_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'posting_limit_24h_new' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'posting_limit_24h_standard' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'posting_limit_24h_premium' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'posting_limit_60m_new' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'posting_limit_60m_standard' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'posting_limit_60m_premium' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'posting_min_interval_new' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'posting_min_interval_standard' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'posting_min_interval_premium' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'comment_limit_24h_new' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'comment_limit_24h_standard' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'comment_limit_24h_premium' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'post_max_images_new' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'post_max_images_standard' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'post_max_images_premium' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'antispam_enabled' => ['sometimes', 'boolean'],
            'duplicate_detection_enabled' => ['sometimes', 'boolean'],
            'duplicate_detection_window_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'duplicate_similarity_threshold' => ['sometimes', 'integer', 'min:50', 'max:100'],
            'antispam_medium_threshold' => ['sometimes', 'integer', 'min:10', 'max:90'],
            'antispam_high_threshold' => ['sometimes', 'integer', 'min:20', 'max:95'],
            'antispam_violation_threshold' => ['sometimes', 'integer', 'min:30', 'max:100'],
            'antispam_max_links_per_post' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'antispam_max_mentions_per_post' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'antispam_max_hashtags_per_post' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'antispam_temp_restriction_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'moderation_risk_based_enabled' => ['sometimes', 'boolean'],
            'moderation_require_manual_review' => ['sometimes', 'boolean'],
            'moderation_review_threshold' => ['sometimes', 'integer', 'min:20', 'max:95'],
            'moderation_ai_assist_enabled' => ['sometimes', 'boolean'],
            'moderation_suspicious_link_action' => ['sometimes', 'string', 'in:allow,flag,reject'],
            'moderation_violation_action' => ['sometimes', 'string', 'in:pending_review,reject'],
            'feed_enable_trending' => ['sometimes', 'boolean'],
            'feed_enable_recommended' => ['sometimes', 'boolean'],
            'feed_enable_fresh_discovery' => ['sometimes', 'boolean'],
            'feed_candidate_window_hours' => ['sometimes', 'integer', 'min:12', 'max:720'],
            'feed_refresh_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'feed_page_size' => ['sometimes', 'integer', 'min:5', 'max:30'],
            'feed_author_diversity_max' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'feed_category_diversity_enabled' => ['sometimes', 'boolean'],
            'feed_allocation_ranking_pct' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'feed_allocation_fresh_pct' => ['sometimes', 'integer', 'min:0', 'max:80'],
            'feed_allocation_discovery_pct' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'feed_ad_spacing_posts' => ['sometimes', 'integer', 'min:3', 'max:20'],
            'trending_weight_human_view' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'trending_weight_reaction' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'trending_weight_comment' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'trending_weight_bookmark' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'trending_weight_share' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'trending_decay_base_hours' => ['sometimes', 'numeric', 'min:1', 'max:168'],
            'trending_decay_exponent' => ['sometimes', 'numeric', 'min:0.1', 'max:4'],
            'trending_per_user_contribution_cap' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'trending_view_engagement_ratio' => ['sometimes', 'integer', 'min:0', 'max:5000'],
        ]);

        $this->settings->updateMany($validated, $actor, $this->auditLogger);

        $trendingTouched = false;
        foreach (array_keys($validated) as $key) {
            if (is_string($key) && str_starts_with($key, 'trending_')) {
                $trendingTouched = true;
                break;
            }
        }

        if ($trendingTouched) {
            RecalculateTrendingScoresJob::dispatch()->afterCommit();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Post publishing, anti-spam, and feed settings saved.'),
        ]);

        return back();
    }

    public function reset(Request $request): RedirectResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();
        abort_if(
            $actor === null || (
                ! $actor->isAdmin()
                && ! $actor->hasPermission('posts.settings.manage')
                && ! $actor->hasPermission('feed.settings.manage')
                && ! $actor->hasPermission('settings.update')
            ),
            403,
        );

        $validated = $request->validate([
            'section' => ['required', 'string', 'in:all,publishing,antispam,moderation,feed,trending'],
        ]);

        $section = (string) $validated['section'];
        $this->policyConfig->resetSectionToDefaults($section, $actor, $this->auditLogger);

        if ($section === 'all' || $section === 'trending') {
            RecalculateTrendingScoresJob::dispatch()->afterCommit();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Selected settings section has been restored to safe defaults.'),
        ]);

        return back();
    }

    public function simulate(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();
        abort_if(
            $actor === null || (
                ! $actor->isAdmin()
                && ! $actor->hasPermission('ranking.analytics.view')
                && ! $actor->hasPermission('posts.settings.view')
                && ! $actor->hasPermission('analytics.view')
                && ! $actor->hasPermission('settings.view')
            ),
            403,
        );

        $validated = $request->validate([
            'human_views' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'reactions' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'comments' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'bookmarks' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'shares' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'age_hours' => ['required', 'numeric', 'min:0', 'max:8760'],
            'weights' => ['nullable', 'array'],
            'weights.human_view' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'weights.reaction' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'weights.comment' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'weights.bookmark' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'weights.share' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'weights.decay_base_hours' => ['sometimes', 'numeric', 'min:1', 'max:168'],
            'weights.decay_exponent' => ['sometimes', 'numeric', 'min:0.1', 'max:4'],
            'weights.per_user_cap' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'weights.view_engagement_ratio' => ['sometimes', 'integer', 'min:0', 'max:5000'],
        ]);

        $overrideWeights = isset($validated['weights']) && is_array($validated['weights'])
            ? $validated['weights']
            : null;

        return response()->json([
            'simulation' => $this->rankingService->simulate($validated, $overrideWeights),
        ]);
    }
}
