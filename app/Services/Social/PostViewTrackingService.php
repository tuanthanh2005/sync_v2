<?php

namespace App\Services\Social;

use App\Enums\AccountType;
use App\Enums\ModerationStatus;
use App\Enums\PostVisibility;
use App\Models\AiAgent;
use App\Models\Post;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\System\SystemSettingService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PostViewTrackingService
{
    public const MAX_BATCH_SIZE = 25;

    public const DEFAULT_MIN_DURATION_MS = 500;

    public const DEFAULT_MIN_VISIBLE_RATIO = 50;

    public const DEFAULT_DEDUP_WINDOW_SECONDS = 300;

    public const DEFAULT_RETENTION_DAYS = 30;

    public const VIEWER_HUMAN = 'human';

    public const VIEWER_GUEST = 'guest';

    public const VIEWER_AI_WEB = 'ai_view';

    public const VIEWER_AI_CONTENT_ACCESS = 'ai_content_access';

    public const VIEWER_LEGACY = 'legacy';

    /**
     * @var list<string>
     */
    private const VALID_SOURCES = [
        'feed',
        'detail',
        'profile',
        'community',
        'search',
        'topic',
    ];

    /**
     * @var list<string>
     */
    private const VALID_AI_TASKS = [
        'ai_reply_draft',
        'ai_translation',
        'ai_moderation_assess',
        'ai_content_access',
    ];

    /**
     * @var list<string>
     */
    private const BOT_SIGNATURES = [
        'googlebot',
        'bingbot',
        'slurp',
        'duckduckbot',
        'baiduspider',
        'yandexbot',
        'ahrefsbot',
        'semrushbot',
        'mj12bot',
        'dotbot',
        'applebot',
        'facebookexternalhit',
        'twitterbot',
        'linkedinbot',
        'whatsapp',
        'telegrambot',
        'discordbot',
        'pinterestbot',
        'petalbot',
        'bytespider',
        'gptbot',
        'claudebot',
        'curl/',
        'wget/',
        'python-requests',
        'scrapy',
        'headlesschrome',
        'lighthouse',
        'pagespeed',
        'bot',
        'crawler',
        'spider',
    ];

    public function __construct(
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Resolve active view tracking policy thresholds with safe server-side bounds.
     *
     * @return array{
     *     min_duration_ms: int,
     *     min_visible_ratio: int,
     *     dedup_window_seconds: int,
     *     track_guests: bool,
     *     track_ai: bool,
     *     track_author_self: bool,
     *     bot_filtering: bool,
     *     retention_days: int
     * }
     */
    public function activeConfig(): array
    {
        return [
            'min_duration_ms' => max(
                100,
                min(
                    10_000,
                    (int) $this->settings->get(
                        'views_min_duration_ms',
                        (int) config('syncviet.views.min_duration_ms', self::DEFAULT_MIN_DURATION_MS),
                    ),
                ),
            ),
            'min_visible_ratio' => max(
                10,
                min(
                    100,
                    (int) $this->settings->get(
                        'views_min_visible_ratio',
                        (int) config('syncviet.views.min_visible_ratio', self::DEFAULT_MIN_VISIBLE_RATIO),
                    ),
                ),
            ),
            'dedup_window_seconds' => max(
                30,
                min(
                    86_400,
                    (int) $this->settings->get(
                        'views_dedup_window_seconds',
                        (int) config('syncviet.views.dedup_window_seconds', self::DEFAULT_DEDUP_WINDOW_SECONDS),
                    ),
                ),
            ),
            'track_guests' => (bool) $this->settings->get(
                'views_track_guests',
                (bool) config('syncviet.views.track_guests', true),
            ),
            'track_ai' => (bool) $this->settings->get(
                'views_track_ai',
                (bool) config('syncviet.views.track_ai', true),
            ),
            'track_author_self' => (bool) $this->settings->get(
                'views_track_author_self',
                (bool) config('syncviet.views.track_author_self', false),
            ),
            'bot_filtering' => (bool) $this->settings->get(
                'views_bot_filtering',
                (bool) config('syncviet.views.bot_filtering', true),
            ),
            'retention_days' => max(
                7,
                min(
                    180,
                    (int) $this->settings->get(
                        'views_retention_days',
                        (int) config('syncviet.views.retention_days', self::DEFAULT_RETENTION_DAYS),
                    ),
                ),
            ),
        ];
    }

    public function isBotOrPrefetch(Request $request): bool
    {
        $purposeHeaders = [
            strtolower((string) $request->header('Sec-Purpose', '')),
            strtolower((string) $request->header('Purpose', '')),
            strtolower((string) $request->header('X-Purpose', '')),
            strtolower((string) $request->header('X-Moz', '')),
        ];

        foreach ($purposeHeaders as $purpose) {
            if ($purpose !== '' && (str_contains($purpose, 'prefetch') || str_contains($purpose, 'preview'))) {
                return true;
            }
        }

        if ($request->headers->has('X-Background-Job') || $request->headers->has('X-SyncViet-Job')) {
            return true;
        }

        $userAgent = strtolower(trim((string) $request->userAgent()));
        if ($userAgent === '') {
            return true;
        }

        foreach (self::BOT_SIGNATURES as $signature) {
            if (str_contains($userAgent, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a deduplicated batch of viewport-qualified web post views (500ms / 50% / 5-minute window).
     * Returns backend-confirmed live view counts for every post in the batch so UI updates synchronously without reload.
     *
     * @param  list<array{
     *     post_id: int,
     *     source?: string,
     *     is_promoted?: bool,
     *     visible_ms?: int|null,
     *     visible_ratio?: int|null,
     *     is_tall_post_probe?: bool|null,
     *     is_tab_visible?: bool|null
     * }>  $items
     * @return array{
     *     recorded: int,
     *     skipped: int,
     *     invalid_traffic: bool,
     *     dedup_window_seconds: int,
     *     counts: array<int, array<string, mixed>>
     * }
     */
    public function recordBatch(Request $request, ?User $viewer, array $items): array
    {
        $cfg = $this->activeConfig();

        if (! Schema::hasTable('post_view_events')) {
            return [
                'recorded' => 0,
                'skipped' => count($items),
                'invalid_traffic' => false,
                'dedup_window_seconds' => $cfg['dedup_window_seconds'],
                'counts' => [],
            ];
        }

        $normalized = [];
        foreach (array_slice($items, 0, self::MAX_BATCH_SIZE) as $item) {
            $postId = (int) $item['post_id'];
            if ($postId <= 0 || isset($normalized[$postId])) {
                continue;
            }

            $source = isset($item['source']) && in_array($item['source'], self::VALID_SOURCES, true)
                ? $item['source']
                : 'feed';

            $normalized[$postId] = [
                'post_id' => $postId,
                'source' => $source,
                'is_promoted' => (bool) ($item['is_promoted'] ?? false),
                'visible_ms' => isset($item['visible_ms']) ? max(0, (int) $item['visible_ms']) : 0,
                'visible_ratio' => isset($item['visible_ratio']) ? max(0, min(100, (int) $item['visible_ratio'])) : 0,
                'is_tall_post_probe' => (bool) ($item['is_tall_post_probe'] ?? false),
                'is_tab_visible' => ! array_key_exists('is_tab_visible', $item) || $item['is_tab_visible'] !== false,
            ];
        }

        if ($normalized === []) {
            return [
                'recorded' => 0,
                'skipped' => 0,
                'invalid_traffic' => false,
                'dedup_window_seconds' => $cfg['dedup_window_seconds'],
                'counts' => [],
            ];
        }

        $postIds = array_keys($normalized);
        $posts = Post::query()
            ->whereIn('id', $postIds)
            ->get([
                'id',
                'user_id',
                'visibility',
                'moderation_status',
                'published_at',
            ])
            ->keyBy('id');

        /** @var list<int> $existingPostIds */
        $existingPostIds = array_values(
            $posts->keys()->map(static fn (mixed $id): int => (int) $id)->all(),
        );

        // 1. Bot / Crawler / Prefetch / Background job filtering
        if ($cfg['bot_filtering'] && $this->isBotOrPrefetch($request)) {
            $this->recordInvalidViews($existingPostIds);

            return [
                'recorded' => 0,
                'skipped' => count($normalized),
                'invalid_traffic' => true,
                'dedup_window_seconds' => $cfg['dedup_window_seconds'],
                'counts' => $this->freshCountsForPosts($existingPostIds, $viewer),
            ];
        }

        // 2. Classify viewer (Human / Guest / AI Agent Web View / Excluded)
        $classification = $this->classifyWebViewer($request, $viewer, $cfg);
        if (! $classification['eligible']) {
            if ($classification['mark_invalid']) {
                $this->recordInvalidViews($existingPostIds);
            }

            return [
                'recorded' => 0,
                'skipped' => count($normalized),
                'invalid_traffic' => $classification['mark_invalid'],
                'dedup_window_seconds' => $cfg['dedup_window_seconds'],
                'counts' => $this->freshCountsForPosts($existingPostIds, $viewer),
            ];
        }

        $viewerType = $classification['viewer_type'];
        $viewerHash = $classification['viewer_hash'];
        $aiAgentId = $classification['ai_agent_id'];
        $blockedAuthorIds = $viewer !== null ? $this->mutualBlockedAuthorIds($viewer) : [];
        $now = now()->utc();

        $recorded = 0;
        $skipped = 0;

        foreach ($normalized as $postId => $entry) {
            /** @var Post|null $post */
            $post = $posts->get($postId);

            if ($post === null || ! $this->isTrackableForViewer($post, $viewer, $cfg['track_author_self'], $blockedAuthorIds)) {
                $skipped++;

                continue;
            }

            // Enforce active tab, >= min duration (500ms default), and >= min visible ratio (50% default, or representative tall-post probe).
            if (! $entry['is_tab_visible']) {
                $skipped++;

                continue;
            }

            if ($entry['visible_ms'] < $cfg['min_duration_ms']) {
                $skipped++;

                continue;
            }

            $requiredRatio = $entry['is_tall_post_probe']
                ? max(10, min(20, $cfg['min_visible_ratio']))
                : $cfg['min_visible_ratio'];

            if ($entry['visible_ratio'] < $requiredRatio) {
                $skipped++;

                continue;
            }

            $saved = $this->persistDeduplicatedView(
                post: $post,
                viewerUserId: $viewer?->id,
                aiAgentId: $aiAgentId,
                viewerHash: $viewerHash,
                viewerType: $viewerType,
                source: $entry['source'],
                isPromoted: $entry['is_promoted'],
                visibleMs: $entry['visible_ms'],
                visibleRatio: $entry['visible_ratio'],
                windowSeconds: $cfg['dedup_window_seconds'],
                now: $now,
            );

            if ($saved) {
                $recorded++;
            } else {
                $skipped++;
            }
        }

        return [
            'recorded' => $recorded,
            'skipped' => $skipped,
            'invalid_traffic' => false,
            'dedup_window_seconds' => $cfg['dedup_window_seconds'],
            'counts' => $this->freshCountsForPosts($existingPostIds, $viewer),
        ];
    }

    /**
     * Record a verified backend AI Content Access when an enabled AI Agent genuinely reads
     * and processes a post's content in an authorized AI task (reply draft, translation, moderation assist).
     * Never simulates browser viewport; strictly excluded from human unique viewers, DAU/MAU, and paid ad reach.
     */
    public function recordAiContentAccess(Post $post, AiAgent $agent, string $taskSource = 'ai_content_access'): bool
    {
        if (! Schema::hasTable('post_view_events')) {
            return false;
        }

        $cfg = $this->activeConfig();
        if (! $cfg['track_ai'] || ! $agent->is_enabled) {
            return false;
        }

        if ($post->moderation_status !== ModerationStatus::Approved || $post->published_at === null) {
            return false;
        }

        if ($post->visibility === PostVisibility::Private) {
            return false;
        }

        // Respect author self-view exclusion even for AI Agents
        if ($agent->user_id === $post->user_id && ! $cfg['track_author_self']) {
            return false;
        }

        // Respect mutual blocks between author and AI Agent
        $blocked = UserBlock::query()
            ->where(static function ($q) use ($post, $agent): void {
                $q->where(static function ($inner) use ($post, $agent): void {
                    $inner->where('blocker_id', $post->user_id)->where('blocked_id', $agent->user_id);
                })->orWhere(static function ($inner) use ($post, $agent): void {
                    $inner->where('blocker_id', $agent->user_id)->where('blocked_id', $post->user_id);
                });
            })
            ->exists();

        if ($blocked) {
            return false;
        }

        $secret = (string) config('app.key', 'syncviet');
        $viewerHash = hash_hmac('sha256', 'ai_agent:'.$agent->id, $secret);
        $source = in_array($taskSource, self::VALID_AI_TASKS, true) ? $taskSource : 'ai_content_access';

        return $this->persistDeduplicatedView(
            post: $post,
            viewerUserId: $agent->user_id,
            aiAgentId: $agent->id,
            viewerHash: $viewerHash,
            viewerType: self::VIEWER_AI_CONTENT_ACCESS,
            source: $source,
            isPromoted: false,
            visibleMs: null,
            visibleRatio: null,
            windowSeconds: $cfg['dedup_window_seconds'],
            now: now()->utc(),
        );
    }

    /**
     * Build post-level analytics breakdown for Post Author & Staff (zero viewer PII exposed).
     *
     * @return array{
     *     total_views: int,
     *     human_views: int,
     *     guest_views: int,
     *     ai_views: int,
     *     ai_content_accesses: int,
     *     unique_human_viewers: int,
     *     organic_views: int,
     *     promoted_human_views: int,
     *     invalid_views: int,
     *     legacy_views: int
     * }
     */
    public function analyticsForPost(Post $post): array
    {
        return [
            'total_views' => (int) ($post->views_count ?? 0),
            'human_views' => (int) ($post->human_views_count ?? 0),
            'guest_views' => (int) ($post->guest_views_count ?? 0),
            'ai_views' => (int) ($post->ai_views_count ?? 0),
            'ai_content_accesses' => (int) ($post->ai_content_accesses_count ?? 0),
            'unique_human_viewers' => (int) ($post->unique_human_viewers_count ?? 0),
            'organic_views' => (int) ($post->organic_views_count ?? 0),
            'promoted_human_views' => (int) ($post->promoted_views_count ?? 0),
            'invalid_views' => (int) ($post->invalid_views_count ?? 0),
            'legacy_views' => (int) ($post->legacy_views_count ?? 0),
        ];
    }

    /**
     * Single indexed aggregate summary for Admin Analytics & Overview (Hostinger-safe, zero COUNT(*) over raw logs).
     *
     * @return array{
     *     total_views: int,
     *     human_views: int,
     *     guest_views: int,
     *     ai_views: int,
     *     ai_content_accesses: int,
     *     unique_human_viewers: int,
     *     organic_views: int,
     *     promoted_human_views: int,
     *     invalid_views: int,
     *     legacy_views: int
     * }
     */
    public function summaryForAdmin(): array
    {
        $row = DB::table('posts')
            ->whereNull('deleted_at')
            ->selectRaw('
                COALESCE(SUM(views_count), 0) as total_views,
                COALESCE(SUM(human_views_count), 0) as human_views,
                COALESCE(SUM(guest_views_count), 0) as guest_views,
                COALESCE(SUM(ai_views_count), 0) as ai_views,
                COALESCE(SUM(ai_content_accesses_count), 0) as ai_content_accesses,
                COALESCE(SUM(unique_human_viewers_count), 0) as unique_human_viewers,
                COALESCE(SUM(organic_views_count), 0) as organic_views,
                COALESCE(SUM(promoted_views_count), 0) as promoted_human_views,
                COALESCE(SUM(invalid_views_count), 0) as invalid_views,
                COALESCE(SUM(legacy_views_count), 0) as legacy_views
            ')
            ->first();

        return [
            'total_views' => (int) ($row->total_views ?? 0),
            'human_views' => (int) ($row->human_views ?? 0),
            'guest_views' => (int) ($row->guest_views ?? 0),
            'ai_views' => (int) ($row->ai_views ?? 0),
            'ai_content_accesses' => (int) ($row->ai_content_accesses ?? 0),
            'unique_human_viewers' => (int) ($row->unique_human_viewers ?? 0),
            'organic_views' => (int) ($row->organic_views ?? 0),
            'promoted_human_views' => (int) ($row->promoted_human_views ?? 0),
            'invalid_views' => (int) ($row->invalid_views ?? 0),
            'legacy_views' => (int) ($row->legacy_views ?? 0),
        ];
    }

    /**
     * Prune raw view tracking rows older than retention policy while keeping aggregated counters intact.
     */
    public function pruneOldEvents(?int $retentionDays = null): int
    {
        if (! Schema::hasTable('post_view_events')) {
            return 0;
        }

        $days = $retentionDays ?? $this->activeConfig()['retention_days'];
        $cutoff = now()->subDays(max(7, min(180, $days)));
        $deletedTotal = 0;

        do {
            $deleted = DB::table('post_view_events')
                ->where('created_at', '<', $cutoff)
                ->limit(2000)
                ->delete();
            $deletedTotal += $deleted;
        } while ($deleted === 2000);

        return $deletedTotal;
    }

    /**
     * Race-condition-safe sliding + bucketed deduplication write & atomic counter update.
     */
    private function persistDeduplicatedView(
        Post $post,
        ?int $viewerUserId,
        ?int $aiAgentId,
        string $viewerHash,
        string $viewerType,
        string $source,
        bool $isPromoted,
        ?int $visibleMs,
        ?int $visibleRatio,
        int $windowSeconds,
        CarbonInterface $now,
    ): bool {
        $execute = function () use (
            $post,
            $viewerUserId,
            $aiAgentId,
            $viewerHash,
            $viewerType,
            $source,
            $isPromoted,
            $visibleMs,
            $visibleRatio,
            $windowSeconds,
            $now,
        ): bool {
            $cutoff = $now->copy()->subSeconds($windowSeconds);

            // Sliding 5-minute deduplication prevents clock-bucket boundary double-counts.
            $recentExists = DB::table('post_view_events')
                ->where('post_id', $post->id)
                ->where('viewer_hash', $viewerHash)
                ->where('created_at', '>', $cutoff)
                ->exists();

            if ($recentExists) {
                return false;
            }

            $isHumanOrGuest = $viewerType === self::VIEWER_HUMAN || $viewerType === self::VIEWER_GUEST;
            $alreadyViewedEver = $isHumanOrGuest && DB::table('post_view_events')
                ->where('post_id', $post->id)
                ->where('viewer_hash', $viewerHash)
                ->exists();

            $bucket = sprintf('W%d-%d', $windowSeconds, intdiv($now->getTimestamp(), $windowSeconds));

            // Atomic DB UNIQUE(post_id, viewer_hash, viewed_bucket) guarantees zero race-condition duplicates.
            $inserted = DB::table('post_view_events')->insertOrIgnore([
                'post_id' => $post->id,
                'viewer_user_id' => $viewerUserId,
                'ai_agent_id' => $aiAgentId,
                'viewer_hash' => $viewerHash,
                'viewer_type' => $viewerType,
                'source' => $source,
                'visible_ms' => $visibleMs,
                'visible_ratio' => $visibleRatio,
                'is_promoted' => $isHumanOrGuest && $isPromoted,
                'is_valid' => true,
                'viewed_bucket' => $bucket,
                'created_at' => $now,
            ]);

            if ($inserted !== 1) {
                return false;
            }

            $updates = [
                'views_count' => DB::raw('views_count + 1'),
            ];

            if ($viewerType === self::VIEWER_HUMAN) {
                $updates['human_views_count'] = DB::raw('human_views_count + 1');
            } elseif ($viewerType === self::VIEWER_GUEST) {
                $updates['guest_views_count'] = DB::raw('guest_views_count + 1');
            } elseif ($viewerType === self::VIEWER_AI_WEB) {
                $updates['ai_views_count'] = DB::raw('ai_views_count + 1');
            } elseif ($viewerType === self::VIEWER_AI_CONTENT_ACCESS) {
                $updates['ai_content_accesses_count'] = DB::raw('ai_content_accesses_count + 1');
            }

            // Strictly exclude AI views from Organic Human Traffic, Promoted Human Reach, and Unique Human Viewers.
            if ($isHumanOrGuest) {
                if ($isPromoted) {
                    $updates['promoted_views_count'] = DB::raw('promoted_views_count + 1');
                } else {
                    $updates['organic_views_count'] = DB::raw('organic_views_count + 1');
                }

                if (! $alreadyViewedEver) {
                    $updates['unique_viewers_count'] = DB::raw('unique_viewers_count + 1');
                    if ($viewerType === self::VIEWER_HUMAN) {
                        $updates['unique_human_viewers_count'] = DB::raw('unique_human_viewers_count + 1');
                    }
                }
            }

            DB::table('posts')->where('id', $post->id)->update($updates);

            return true;
        };

        try {
            $lock = Cache::lock("sv:view:{$post->id}:{$viewerHash}", 3);
            if ($lock->get()) {
                try {
                    return $execute();
                } finally {
                    $lock->release();
                }
            }

            // Another concurrent worker is actively recording this exact (post, viewer) right now.
            return false;
        } catch (\Throwable) {
            // Fallback to DB unique constraint protection when cache store lacks lock support.
            return $execute();
        }
    }

    /**
     * @param  array{track_guests: bool, track_ai: bool}  $cfg
     * @return array{
     *     eligible: bool,
     *     mark_invalid: bool,
     *     viewer_type: string,
     *     viewer_hash: string,
     *     ai_agent_id: int|null
     * }
     */
    private function classifyWebViewer(Request $request, ?User $viewer, array $cfg): array
    {
        $secret = (string) config('app.key', 'syncviet');

        if ($viewer !== null) {
            // Strictly reject automated test accounts and system official accounts.
            if ((bool) $viewer->is_test_account || $viewer->account_type === AccountType::SystemOfficial) {
                return [
                    'eligible' => false,
                    'mark_invalid' => true,
                    'viewer_type' => '',
                    'viewer_hash' => '',
                    'ai_agent_id' => null,
                ];
            }

            if ($viewer->isAiAgent()) {
                if (! $cfg['track_ai']) {
                    return [
                        'eligible' => false,
                        'mark_invalid' => false,
                        'viewer_type' => '',
                        'viewer_hash' => '',
                        'ai_agent_id' => null,
                    ];
                }

                $agentId = Schema::hasTable('ai_agents')
                    ? AiAgent::query()->where('user_id', $viewer->id)->value('id')
                    : null;
                $resolvedAgentId = $agentId !== null ? (int) $agentId : null;
                $hashKey = $resolvedAgentId !== null
                    ? 'ai_agent:'.$resolvedAgentId
                    : 'ai_agent:user:'.$viewer->id;

                return [
                    'eligible' => true,
                    'mark_invalid' => false,
                    'viewer_type' => self::VIEWER_AI_WEB,
                    'viewer_hash' => hash_hmac('sha256', $hashKey, $secret),
                    'ai_agent_id' => $resolvedAgentId,
                ];
            }

            return [
                'eligible' => true,
                'mark_invalid' => false,
                'viewer_type' => self::VIEWER_HUMAN,
                'viewer_hash' => hash_hmac('sha256', 'user:'.$viewer->id, $secret),
                'ai_agent_id' => null,
            ];
        }

        // Anonymous Guest viewer
        if (! $cfg['track_guests']) {
            return [
                'eligible' => false,
                'mark_invalid' => false,
                'viewer_type' => '',
                'viewer_hash' => '',
                'ai_agent_id' => null,
            ];
        }

        if ($request->hasSession()) {
            $sessionId = $request->session()->getId();
            if ($sessionId !== '') {
                return [
                    'eligible' => true,
                    'mark_invalid' => false,
                    'viewer_type' => self::VIEWER_GUEST,
                    'viewer_hash' => hash_hmac('sha256', 'session:'.$sessionId, $secret),
                    'ai_agent_id' => null,
                ];
            }
        }

        return [
            'eligible' => false,
            'mark_invalid' => true,
            'viewer_type' => '',
            'viewer_hash' => '',
            'ai_agent_id' => null,
        ];
    }

    /**
     * @param  list<int>  $blockedAuthorIds
     */
    private function isTrackableForViewer(Post $post, ?User $viewer, bool $trackAuthorSelf, array $blockedAuthorIds): bool
    {
        if ($post->moderation_status !== ModerationStatus::Approved || $post->published_at === null) {
            return false;
        }

        if ($viewer !== null && $viewer->id === $post->user_id) {
            return $trackAuthorSelf;
        }

        if ($post->visibility === PostVisibility::Private) {
            return false;
        }

        if ($blockedAuthorIds !== [] && in_array($post->user_id, $blockedAuthorIds, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<int>  $postIds
     */
    private function recordInvalidViews(array $postIds): void
    {
        if ($postIds === []) {
            return;
        }

        DB::table('posts')
            ->whereIn('id', $postIds)
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->where('visibility', '!=', PostVisibility::Private->value)
            ->whereNotNull('published_at')
            ->update([
                'invalid_views_count' => DB::raw('invalid_views_count + 1'),
            ]);
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, array<string, mixed>>
     */
    private function freshCountsForPosts(array $postIds, ?User $viewer): array
    {
        if ($postIds === []) {
            return [];
        }

        $freshPosts = Post::query()
            ->whereIn('id', $postIds)
            ->get([
                'id',
                'user_id',
                'visibility',
                'moderation_status',
                'published_at',
                'views_count',
                'human_views_count',
                'guest_views_count',
                'ai_views_count',
                'ai_content_accesses_count',
                'unique_human_viewers_count',
                'organic_views_count',
                'promoted_views_count',
                'invalid_views_count',
                'legacy_views_count',
            ]);

        $counts = [];
        foreach ($freshPosts as $post) {
            $isOwnerOrStaff = $viewer !== null && ($viewer->id === $post->user_id || $viewer->isStaff());
            $isPubliclyViewable = $post->moderation_status === ModerationStatus::Approved
                && $post->published_at !== null
                && $post->visibility !== PostVisibility::Private;

            if (! $isPubliclyViewable && ! $isOwnerOrStaff) {
                continue;
            }

            $counts[$post->id] = [
                'views_count' => (int) $post->views_count,
                'view_analytics' => $isOwnerOrStaff ? $this->analyticsForPost($post) : null,
            ];
        }

        return $counts;
    }

    /**
     * @return list<int>
     */
    private function mutualBlockedAuthorIds(User $viewer): array
    {
        $blocked = UserBlock::query()
            ->where('blocker_id', $viewer->id)
            ->pluck('blocked_id')
            ->all();

        $blockedBy = UserBlock::query()
            ->where('blocked_id', $viewer->id)
            ->pluck('blocker_id')
            ->all();

        return array_values(array_unique(array_map('intval', [...$blocked, ...$blockedBy])));
    }
}
