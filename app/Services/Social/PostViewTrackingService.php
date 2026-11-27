<?php

namespace App\Services\Social;

use App\Enums\ModerationStatus;
use App\Enums\PostVisibility;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PostViewTrackingService
{
    public const MAX_BATCH_SIZE = 25;

    public const DEFAULT_DEDUP_WINDOW_HOURS = 6;

    public const DEFAULT_RETENTION_DAYS = 30;

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
     * Record a deduplicated batch of genuine viewport-qualified post views.
     * Excludes bots, prefetch requests, AI Agents, System Official, and Test accounts.
     *
     * @param  list<array{post_id: int, source?: string, is_promoted?: bool}>  $items
     * @return array{recorded: int, skipped: int, invalid_traffic: bool}
     */
    public function recordBatch(Request $request, ?User $viewer, array $items): array
    {
        if (! Schema::hasTable('post_view_events')) {
            return ['recorded' => 0, 'skipped' => count($items), 'invalid_traffic' => false];
        }

        if ($this->isBotOrPrefetch($request)) {
            return ['recorded' => 0, 'skipped' => count($items), 'invalid_traffic' => true];
        }

        // Strictly exclude AI Agents, System Official, and Automated Test accounts from view metrics.
        if ($viewer !== null && ! $viewer->isHumanMember()) {
            return ['recorded' => 0, 'skipped' => count($items), 'invalid_traffic' => true];
        }

        $viewerHash = $this->resolveViewerHash($request, $viewer);
        if ($viewerHash === null) {
            return ['recorded' => 0, 'skipped' => count($items), 'invalid_traffic' => true];
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
            ];
        }

        if ($normalized === []) {
            return ['recorded' => 0, 'skipped' => 0, 'invalid_traffic' => false];
        }

        $postIds = array_keys($normalized);
        $posts = Post::query()
            ->whereIn('id', $postIds)
            ->get(['id', 'user_id', 'visibility', 'moderation_status', 'published_at'])
            ->keyBy('id');

        $bucket = $this->currentDedupBucket();

        /** @var array<int, true> $alreadyViewedAnyTime */
        $alreadyViewedAnyTime = array_fill_keys(
            DB::table('post_view_events')
                ->where('viewer_hash', $viewerHash)
                ->whereIn('post_id', $postIds)
                ->distinct()
                ->pluck('post_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            true,
        );

        $recorded = 0;
        $skipped = 0;
        $now = now();

        foreach ($normalized as $postId => $entry) {
            /** @var Post|null $post */
            $post = $posts->get($postId);

            if ($post === null || ! $this->isTrackableForViewer($post, $viewer)) {
                $skipped++;

                continue;
            }

            $inserted = DB::table('post_view_events')->insertOrIgnore([
                'post_id' => $post->id,
                'viewer_user_id' => $viewer?->id,
                'viewer_hash' => $viewerHash,
                'source' => $entry['source'],
                'is_promoted' => $entry['is_promoted'],
                'viewed_bucket' => $bucket,
                'created_at' => $now,
            ]);

            if ($inserted !== 1) {
                $skipped++;

                continue;
            }

            $isUnique = ! isset($alreadyViewedAnyTime[$post->id]);
            $alreadyViewedAnyTime[$post->id] = true;

            $updates = [
                'views_count' => DB::raw('views_count + 1'),
                $entry['is_promoted'] ? 'promoted_views_count' : 'organic_views_count' => DB::raw(
                    ($entry['is_promoted'] ? 'promoted_views_count' : 'organic_views_count').' + 1',
                ),
            ];

            if ($isUnique) {
                $updates['unique_viewers_count'] = DB::raw('unique_viewers_count + 1');
            }

            DB::table('posts')->where('id', $post->id)->update($updates);
            $recorded++;
        }

        return [
            'recorded' => $recorded,
            'skipped' => $skipped,
            'invalid_traffic' => false,
        ];
    }

    /**
     * Prune raw view tracking rows older than retention policy while keeping aggregated counters intact.
     */
    public function pruneOldEvents(int $retentionDays = self::DEFAULT_RETENTION_DAYS): int
    {
        if (! Schema::hasTable('post_view_events')) {
            return 0;
        }

        $cutoff = now()->subDays(max(7, min(180, $retentionDays)));
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

    private function isTrackableForViewer(Post $post, ?User $viewer): bool
    {
        if ($post->moderation_status !== ModerationStatus::Approved || $post->published_at === null) {
            return false;
        }

        if ($post->visibility === PostVisibility::Private) {
            return $viewer !== null && $viewer->id === $post->user_id;
        }

        return true;
    }

    private function resolveViewerHash(Request $request, ?User $viewer): ?string
    {
        $secret = (string) config('app.key', 'syncviet');

        if ($viewer !== null) {
            return hash_hmac('sha256', 'user:'.$viewer->id, $secret);
        }

        if ($request->hasSession()) {
            $sessionId = $request->session()->getId();
            if ($sessionId !== '') {
                return hash_hmac('sha256', 'session:'.$sessionId, $secret);
            }
        }

        return null;
    }

    private function currentDedupBucket(): string
    {
        $windowHours = max(1, min(24, (int) config('syncviet.views.dedup_window_hours', self::DEFAULT_DEDUP_WINDOW_HOURS)));
        $now = now()->utc();
        $slot = (int) floor($now->hour / $windowHours);

        return sprintf('%s-B%d', $now->format('Y-m-d'), $slot);
    }
}
