<?php

namespace App\Services\Social;

use App\Enums\PostCategory;
use App\Models\Bookmark;
use App\Models\CommunityMember;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\User;
use App\Models\UserHiddenPost;
use Illuminate\Support\Collection;

class RecommendedFeedService
{
    public function __construct(
        private readonly PostingPolicyConfigService $config,
    ) {}

    /**
     * Build a personalized + fair-distribution Recommended feed slice with author & category diversity.
     *
     * @return array{
     *     data: list<array<string, mixed>>,
     *     next_cursor: string|null,
     *     has_more: bool
     * }
     */
    public function buildFeed(
        FeedService $feedService,
        ?User $viewer,
        ?string $cursor = null,
        int $perPage = 10,
    ): array {
        $offset = $this->decodeOffsetCursor($cursor);
        $perPage = max(1, min(30, $perPage));
        $needed = $offset + $perPage + 1;

        $hiddenIds = $viewer !== null
            ? UserHiddenPost::query()
                ->where('user_id', $viewer->id)
                ->pluck('post_id')
                ->all()
            : [];

        $windowHours = $this->config->candidateWindowHours();
        $windowCutoff = now()->subHours($windowHours);
        $candidateLimit = max(120, min(320, $needed * 4));

        // 1. Pull ranked candidates inside window (plus a fallback wider scan if sparse).
        $rankedPoolQuery = $feedService->baseVisibleQuery($viewer)
            ->where('published_at', '>=', $windowCutoff);

        if ($hiddenIds !== []) {
            $rankedPoolQuery->whereNotIn('id', $hiddenIds);
        }

        $rankedCandidates = $rankedPoolQuery
            ->orderByDesc('trending_score')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($candidateLimit)
            ->get();

        // Fallback if recent window has very few posts (early community / quiet periods).
        if ($rankedCandidates->count() < min(20, $needed)) {
            $fallbackQuery = $feedService->baseVisibleQuery($viewer);

            if ($hiddenIds !== []) {
                $fallbackQuery->whereNotIn('id', $hiddenIds);
            }

            if ($rankedCandidates->isNotEmpty()) {
                $fallbackQuery->whereNotIn('id', $rankedCandidates->pluck('id')->all());
            }

            $extra = $fallbackQuery
                ->orderByDesc('trending_score')
                ->orderByDesc('published_at')
                ->limit(80)
                ->get();

            $rankedCandidates = $rankedCandidates->concat($extra);
        }

        // 2. Pull fresh low-exposure candidates for Cold Start Fair Distribution (last 24h).
        $freshCandidates = collect();
        if ($this->config->freshDiscoveryEnabled()) {
            $freshQuery = $feedService->baseVisibleQuery($viewer)
                ->where('published_at', '>=', now()->subHours(24));

            if ($hiddenIds !== []) {
                $freshQuery->whereNotIn('id', $hiddenIds);
            }

            $freshCandidates = $freshQuery
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(60)
                ->get();
        }

        // Deduplicate full pool keyed by post ID.
        /** @var Collection<int, Post> $poolById */
        $poolById = $rankedCandidates->concat($freshCandidates)->unique('id')->values();

        if ($poolById->isEmpty()) {
            return [
                'data' => [],
                'next_cursor' => null,
                'has_more' => false,
            ];
        }

        $signals = $this->resolveViewerSignals($viewer);
        $ordered = $this->rankAndBlendCandidates($poolById, $signals, $needed, $perPage);

        $slice = $ordered->slice($offset, $perPage + 1)->values();
        $hasMore = $slice->count() > $perPage;
        $pageItems = $slice->take($perPage)->values();

        return [
            'data' => $feedService->serializeCollection($pageItems, $viewer),
            'next_cursor' => $hasMore ? $this->encodeOffsetCursor($offset + $perPage) : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array{
     *     followed_author_ids: list<int>,
     *     joined_community_ids: list<int>,
     *     preferred_categories: list<string>,
     *     preferred_locale: string
     * }
     */
    private function resolveViewerSignals(?User $viewer): array
    {
        $locale = $viewer !== null
            ? $viewer->preferredLocale()
            : app()->getLocale();

        if ($viewer === null) {
            return [
                'followed_author_ids' => [],
                'joined_community_ids' => [],
                'preferred_categories' => [],
                'preferred_locale' => $locale,
            ];
        }

        $followedAuthorIds = Follow::query()
            ->where('follower_id', $viewer->id)
            ->pluck('following_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $joinedCommunityIds = CommunityMember::query()
            ->where('user_id', $viewer->id)
            ->pluck('community_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $reactedPostIds = PostReaction::query()
            ->where('user_id', $viewer->id)
            ->orderByDesc('id')
            ->limit(25)
            ->pluck('post_id');

        $bookmarkedPostIds = Bookmark::query()
            ->where('user_id', $viewer->id)
            ->orderByDesc('id')
            ->limit(15)
            ->pluck('post_id');

        $engagedPostIds = $reactedPostIds->concat($bookmarkedPostIds)->unique()->all();

        $preferredCategories = $engagedPostIds !== []
            ? Post::query()
                ->whereIn('id', $engagedPostIds)
                ->pluck('category')
                ->map(static fn (mixed $cat): string => $cat instanceof PostCategory ? $cat->value : (string) $cat)
                ->countBy()
                ->sortDesc()
                ->keys()
                ->map(static fn (mixed $cat): string => (string) $cat)
                ->take(4)
                ->values()
                ->all()
            : [];

        return [
            'followed_author_ids' => array_values($followedAuthorIds),
            'joined_community_ids' => array_values($joinedCommunityIds),
            'preferred_categories' => array_values($preferredCategories),
            'preferred_locale' => $locale,
        ];
    }

    /**
     * Blend Ranking / Fresh / Discovery slots and enforce author & category diversity.
     *
     * @param  Collection<int, Post>  $pool
     * @param  array{
     *     followed_author_ids: list<int>,
     *     joined_community_ids: list<int>,
     *     preferred_categories: list<string>,
     *     preferred_locale: string
     * }  $signals
     * @return Collection<int, Post>
     */
    private function rankAndBlendCandidates(Collection $pool, array $signals, int $targetCount, int $perPage = 10): Collection
    {
        $nowTimestamp = now()->getTimestamp();

        $scored = $pool->map(function (Post $post) use ($signals, $nowTimestamp): array {
            $baseTrending = (float) ($post->trending_score ?? 0.0);
            $score = $baseTrending * 10.0;

            $ageHours = $post->published_at !== null
                ? max(0.0, ($nowTimestamp - $post->published_at->getTimestamp()) / 3600.0)
                : 48.0;

            // Freshness baseline so early helpful posts participate cleanly alongside older popular items.
            if ($ageHours <= 12.0) {
                $score += max(0.0, 12.0 - $ageHours);
            }

            if (in_array($post->user_id, $signals['followed_author_ids'], true)) {
                $score += 24.0;
            }

            if ($post->community_id !== null && in_array($post->community_id, $signals['joined_community_ids'], true)) {
                $score += 14.0;
            }

            $categoryValue = $post->category->value;

            if (in_array($categoryValue, $signals['preferred_categories'], true)) {
                $score += 9.0;
            }

            if ($post->language_code === $signals['preferred_locale']) {
                $score += 6.0;
            }

            $engagements = (int) ($post->valid_reactions_count ?? 0)
                + (int) ($post->valid_comments_count ?? 0)
                + (int) ($post->valid_bookmarks_count ?? 0)
                + (int) ($post->valid_shares_count ?? 0);

            $isFreshCandidate = $ageHours <= 24.0 && $engagements <= 15 && $post->spam_risk_score < 30;
            $isDiscoveryCandidate = ! in_array($post->user_id, $signals['followed_author_ids'], true)
                && ! in_array($categoryValue, $signals['preferred_categories'], true)
                && $post->spam_risk_score < 30;

            return [
                'post' => $post,
                'score' => $score,
                'age_hours' => $ageHours,
                'category' => $categoryValue,
                'is_fresh' => $isFreshCandidate,
                'is_discovery' => $isDiscoveryCandidate,
            ];
        });

        $rankingQueue = $scored
            ->sort(static function (array $a, array $b): int {
                $cmp = $b['score'] <=> $a['score'];

                return $cmp !== 0 ? $cmp : ($b['post']->id <=> $a['post']->id);
            })
            ->values();

        if (! $this->config->freshDiscoveryEnabled()) {
            return $this->applyDiversityPass($rankingQueue, $targetCount, $perPage);
        }

        $freshQueue = $scored
            ->where('is_fresh', true)
            ->sort(static function (array $a, array $b): int {
                $aTime = $a['post']->published_at?->getTimestamp() ?? 0;
                $bTime = $b['post']->published_at?->getTimestamp() ?? 0;

                return $bTime <=> $aTime;
            })
            ->values();

        $discoveryQueue = $scored
            ->where('is_discovery', true)
            ->sort(static function (array $a, array $b): int {
                $cmp = $b['score'] <=> $a['score'];

                return $cmp !== 0 ? $cmp : ($b['post']->id <=> $a['post']->id);
            })
            ->values();

        // Build proportional 10-slot pattern from admin config (default 70 / 20 / 10 -> 7 ranking, 2 fresh, 1 discovery).
        $alloc = $this->config->recommendationAllocation();
        $totalAlloc = max(1, $alloc['ranking'] + $alloc['fresh'] + $alloc['discovery']);
        $rankingSlots = max(1, (int) round(($alloc['ranking'] / $totalAlloc) * 10));
        $freshSlots = max(0, (int) round(($alloc['fresh'] / $totalAlloc) * 10));
        $discoverySlots = max(0, 10 - $rankingSlots - $freshSlots);

        $pattern = array_merge(
            array_fill(0, max(1, min(10, $rankingSlots)), 'ranking'),
            array_fill(0, min(8, $freshSlots), 'fresh'),
            array_fill(0, min(5, $discoverySlots), 'discovery'),
        );

        // Interleave fresh/discovery cleanly rather than stacking all fresh slots at the bottom.
        $blended = collect();
        $usedIds = [];

        $pickNext = static function (Collection $queue) use (&$usedIds): ?array {
            foreach ($queue as $item) {
                if (! isset($usedIds[$item['post']->id])) {
                    $usedIds[$item['post']->id] = true;

                    return $item;
                }
            }

            return null;
        };

        $cycleCount = max(1, (int) ceil(($pool->count() + 10) / count($pattern)));

        for ($cycle = 0; $cycle < $cycleCount && $blended->count() < $pool->count(); $cycle++) {
            $slotPattern = ['ranking', 'ranking', 'fresh', 'ranking', 'ranking', 'discovery', 'ranking', 'ranking', 'fresh', 'ranking'];
            if ($freshSlots === 0 && $discoverySlots === 0) {
                $slotPattern = ['ranking'];
            } elseif ($freshSlots !== 2 || $discoverySlots !== 1) {
                $slotPattern = $pattern;
            }

            foreach ($slotPattern as $bucket) {
                $picked = match ($bucket) {
                    'fresh' => $pickNext($freshQueue) ?? $pickNext($rankingQueue),
                    'discovery' => $pickNext($discoveryQueue) ?? $pickNext($rankingQueue),
                    default => $pickNext($rankingQueue),
                };

                if ($picked !== null) {
                    $blended->push($picked);
                }
            }
        }

        // Append any untouched candidates cleanly.
        foreach ($rankingQueue as $item) {
            if (! isset($usedIds[$item['post']->id])) {
                $usedIds[$item['post']->id] = true;
                $blended->push($item);
            }
        }

        return $this->applyDiversityPass($blended, $targetCount, $perPage);
    }

    /**
     * Enforce Author Diversity (max posts per author per window) and Category Diversity.
     *
     * @param  Collection<int, array{post: Post, score: float, age_hours: float, category: string, is_fresh: bool, is_discovery: bool}>  $items
     * @return Collection<int, Post>
     */
    private function applyDiversityPass(Collection $items, int $targetCount, int $perPage = 10): Collection
    {
        $maxPerAuthor = $this->config->authorDiversityMax();
        $categoryDiversity = $this->config->categoryDiversityEnabled();
        $windowSize = max(3, min($perPage, $this->config->feedPageSize()));

        $remaining = $items->values()->all();
        $result = [];
        $authorCountInWindow = [];
        $recentCategories = [];

        while ($remaining !== [] && count($result) < max($targetCount, count($items))) {
            $windowIndex = count($result) % $windowSize;
            if ($windowIndex === 0) {
                $authorCountInWindow = [];
            }

            $selectedIdx = null;
            $fallbackIdx = null;

            foreach ($remaining as $idx => $entry) {
                /** @var Post $post */
                $post = $entry['post'];
                $authorId = $post->user_id;
                $category = $entry['category'];

                $authorCount = $authorCountInWindow[$authorId] ?? 0;
                $authorAllowed = $authorCount < $maxPerAuthor;

                $prev1 = $recentCategories[count($recentCategories) - 1] ?? null;
                $prev2 = $recentCategories[count($recentCategories) - 2] ?? null;
                $categoryAllowed = ! $categoryDiversity || ! ($prev1 === $category && $prev2 === $category);

                if ($authorAllowed && $categoryAllowed) {
                    $selectedIdx = $idx;
                    break;
                }

                if ($fallbackIdx === null && $authorAllowed) {
                    $fallbackIdx = $idx;
                }
            }

            $chosenIdx = $selectedIdx ?? $fallbackIdx ?? 0;
            $chosen = $remaining[$chosenIdx];
            array_splice($remaining, $chosenIdx, 1);

            /** @var Post $chosenPost */
            $chosenPost = $chosen['post'];
            $result[] = $chosenPost;
            $authorCountInWindow[$chosenPost->user_id] = ($authorCountInWindow[$chosenPost->user_id] ?? 0) + 1;
            $recentCategories[] = $chosen['category'];
        }

        return collect($result);
    }

    private function encodeOffsetCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['o' => $offset])), '+/', '-_'), '=');
    }

    private function decodeOffsetCursor(?string $cursor): int
    {
        if (! is_string($cursor) || trim($cursor) === '') {
            return 0;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! is_string($decoded)) {
            return 0;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload) || ! isset($payload['o'])) {
            return 0;
        }

        return max(0, (int) $payload['o']);
    }
}
