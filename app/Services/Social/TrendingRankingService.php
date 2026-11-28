<?php

namespace App\Services\Social;

use App\Enums\AccountType;
use App\Enums\ModerationStatus;
use App\Enums\PostLifecycleStatus;
use App\Enums\UserStatus;
use App\Models\Bookmark;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\PostShare;
use App\Models\PublishingQuotaEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class TrendingRankingService
{
    public function __construct(
        private readonly PostingPolicyConfigService $config,
    ) {}

    /**
     * Pure, side-effect-free simulation for Admin Ranking Simulator & unit verification.
     *
     * @param  array{
     *     human_views?: int|float,
     *     reactions?: int|float,
     *     comments?: int|float,
     *     bookmarks?: int|float,
     *     shares?: int|float,
     *     age_hours?: int|float
     * }  $metrics
     * @param  array<string, int|float>|null  $overrideWeights
     * @return array{
     *     effective_human_views: float,
     *     dampening_applied: bool,
     *     age_hours: float,
     *     decay_factor: float,
     *     raw_score: float,
     *     trending_score: float,
     *     breakdown: array{
     *         human_views_score: float,
     *         reactions_score: float,
     *         comments_score: float,
     *         bookmarks_score: float,
     *         shares_score: float
     *     },
     *     weights_used: array{
     *         human_view: float,
     *         reaction: float,
     *         comment: float,
     *         bookmark: float,
     *         share: float,
     *         decay_base_hours: float,
     *         decay_exponent: float,
     *         per_user_cap: int,
     *         view_engagement_ratio: int
     *     }
     * }
     */
    public function simulate(array $metrics, ?array $overrideWeights = null): array
    {
        $weights = $this->config->trendingWeights();

        if ($overrideWeights !== null) {
            $weights = [
                'human_view' => max(0.0, min(5.0, (float) ($overrideWeights['human_view'] ?? $weights['human_view']))),
                'reaction' => max(0.0, min(20.0, (float) ($overrideWeights['reaction'] ?? $weights['reaction']))),
                'comment' => max(0.0, min(30.0, (float) ($overrideWeights['comment'] ?? $weights['comment']))),
                'bookmark' => max(0.0, min(30.0, (float) ($overrideWeights['bookmark'] ?? $weights['bookmark']))),
                'share' => max(0.0, min(30.0, (float) ($overrideWeights['share'] ?? $weights['share']))),
                'decay_base_hours' => max(1.0, min(168.0, (float) ($overrideWeights['decay_base_hours'] ?? $weights['decay_base_hours']))),
                'decay_exponent' => max(0.1, min(4.0, (float) ($overrideWeights['decay_exponent'] ?? $weights['decay_exponent']))),
                'per_user_cap' => max(1, min(100, (int) ($overrideWeights['per_user_cap'] ?? $weights['per_user_cap']))),
                'view_engagement_ratio' => max(0, min(5_000, (int) ($overrideWeights['view_engagement_ratio'] ?? $weights['view_engagement_ratio']))),
            ];
        }

        $humanViews = max(0.0, (float) ($metrics['human_views'] ?? 0));
        $reactions = max(0.0, (float) ($metrics['reactions'] ?? 0));
        $comments = max(0.0, (float) ($metrics['comments'] ?? 0));
        $bookmarks = max(0.0, (float) ($metrics['bookmarks'] ?? 0));
        $shares = max(0.0, (float) ($metrics['shares'] ?? 0));
        $ageHours = max(0.0, (float) ($metrics['age_hours'] ?? 0));

        [$effectiveViews, $dampeningApplied] = $this->applyViewEngagementDampening(
            views: $humanViews,
            reactions: $reactions,
            comments: $comments,
            bookmarks: $bookmarks,
            shares: $shares,
            ratio: $weights['view_engagement_ratio'],
        );

        $humanViewsScore = $effectiveViews * $weights['human_view'];
        $reactionsScore = $reactions * $weights['reaction'];
        $commentsScore = $comments * $weights['comment'];
        $bookmarksScore = $bookmarks * $weights['bookmark'];
        $sharesScore = $shares * $weights['share'];

        $rawScore = $humanViewsScore
            + $reactionsScore
            + $commentsScore
            + $bookmarksScore
            + $sharesScore;

        $decayFactor = pow(
            1.0 + ($ageHours / $weights['decay_base_hours']),
            $weights['decay_exponent'],
        );

        $trendingScore = $decayFactor > 0.0
            ? round($rawScore / $decayFactor, 6)
            : 0.0;

        return [
            'effective_human_views' => round($effectiveViews, 2),
            'dampening_applied' => $dampeningApplied,
            'age_hours' => round($ageHours, 4),
            'decay_factor' => round($decayFactor, 6),
            'raw_score' => round($rawScore, 4),
            'trending_score' => $trendingScore,
            'breakdown' => [
                'human_views_score' => round($humanViewsScore, 4),
                'reactions_score' => round($reactionsScore, 4),
                'comments_score' => round($commentsScore, 4),
                'bookmarks_score' => round($bookmarksScore, 4),
                'shares_score' => round($sharesScore, 4),
            ],
            'weights_used' => $weights,
        ];
    }

    /**
     * Check whether an actor is eligible to contribute organic ranking signals
     * (strictly excludes author self-engagement, AI agents, test/system/inactive accounts).
     */
    public function isEligibleOrganicActor(?User $actor, int $postAuthorId): bool
    {
        if ($actor === null) {
            return false;
        }

        if ($actor->id === $postAuthorId) {
            return false;
        }

        if ((bool) $actor->is_test_account || $actor->isAiAgent() || $actor->account_type === AccountType::SystemOfficial) {
            return false;
        }

        return $actor->status === UserStatus::Active;
    }

    /**
     * Enforce per-user rolling 24h contribution cap so a single account or bot ring
     * cannot infinitely inflate ranking metrics across many posts.
     */
    public function canUserContributeRankingSignal(User $actor): bool
    {
        $weights = $this->config->trendingWeights();
        $cap = $weights['per_user_cap'];

        $recentSignals = PublishingQuotaEvent::query()
            ->where('user_id', $actor->id)
            ->where('action_type', 'ranking_signal')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        return $recentSignals < $cap;
    }

    public function recordRankingSignal(User $actor, int $postId): void
    {
        PublishingQuotaEvent::query()->create([
            'user_id' => $actor->id,
            'action_type' => 'ranking_signal',
            'subject_id' => $postId,
            'trust_tier' => 'human_signal',
            'created_at' => now(),
        ]);
    }

    /**
     * Recalculate a post's Trending Score from its current counters and persist it.
     */
    public function recalculatePost(Post $post, bool $syncFromRelations = false): float
    {
        if (
            $post->trashed()
            || $post->is_spam
            || $post->moderation_status !== ModerationStatus::Approved
            || ($post->lifecycle_status !== null && $post->lifecycle_status !== PostLifecycleStatus::Published)
        ) {
            Post::query()->whereKey($post->id)->update([
                'trending_raw_score' => 0,
                'trending_score' => 0,
                'trending_calculated_at' => now(),
                'needs_rerank' => false,
            ]);

            $post->trending_raw_score = 0.0;
            $post->trending_score = 0.0;
            $post->needs_rerank = false;

            return 0.0;
        }

        if ($syncFromRelations) {
            $validCounts = $this->countAuthoritativeValidSignals($post);
            $post->valid_reactions_count = $validCounts['valid_reactions_count'];
            $post->valid_comments_count = $validCounts['valid_comments_count'];
            $post->valid_bookmarks_count = $validCounts['valid_bookmarks_count'];
            $post->valid_shares_count = $validCounts['valid_shares_count'];
        }

        $organicViews = $this->resolveOrganicHumanViews($post);
        $publishedAt = $post->published_at ?? $post->created_at ?? now();
        $ageHours = $this->resolveAgeHours($publishedAt);

        $result = $this->simulate([
            'human_views' => $organicViews,
            'reactions' => (int) ($post->valid_reactions_count ?? 0),
            'comments' => (int) ($post->valid_comments_count ?? 0),
            'bookmarks' => (int) ($post->valid_bookmarks_count ?? 0),
            'shares' => (int) ($post->valid_shares_count ?? 0),
            'age_hours' => $ageHours,
        ]);

        $now = now();
        $updates = [
            'trending_raw_score' => $result['raw_score'],
            'trending_score' => $result['trending_score'],
            'trending_calculated_at' => $now,
            'needs_rerank' => false,
        ];

        if ($syncFromRelations) {
            $updates['valid_reactions_count'] = (int) $post->valid_reactions_count;
            $updates['valid_comments_count'] = (int) $post->valid_comments_count;
            $updates['valid_bookmarks_count'] = (int) $post->valid_bookmarks_count;
            $updates['valid_shares_count'] = (int) $post->valid_shares_count;
        }

        Post::query()->whereKey($post->id)->update($updates);

        $post->trending_raw_score = $result['raw_score'];
        $post->trending_score = $result['trending_score'];
        $post->trending_calculated_at = $now;
        $post->needs_rerank = false;

        return $result['trending_score'];
    }

    /**
     * Batch-recalculate candidate window and flagged posts (Hostinger shared-hosting friendly).
     *
     * @return array{processed: int, zeroed: int}
     */
    public function recalculateCandidateWindow(int $batchSize = 250): array
    {
        $now = now();
        $windowCutoff = $now->copy()->subHours($this->config->candidateWindowHours());
        $refreshCutoff = $now->copy()->subMinutes(max(1, $this->config->refreshIntervalMinutes()));

        // Zero out any non-published/spam/unapproved posts that still carry a stale trending score.
        $zeroed = Post::withTrashed()
            ->where('trending_score', '>', 0)
            ->where(static function ($query): void {
                $query->whereNotNull('deleted_at')
                    ->orWhere('is_spam', true)
                    ->orWhere('moderation_status', '!=', ModerationStatus::Approved->value)
                    ->orWhere('lifecycle_status', '!=', PostLifecycleStatus::Published->value);
            })
            ->update([
                'trending_raw_score' => 0,
                'trending_score' => 0,
                'trending_calculated_at' => $now,
                'needs_rerank' => false,
            ]);

        $candidateIds = Post::query()
            ->publicVisible()
            ->where(static function ($query) use ($windowCutoff, $refreshCutoff): void {
                $query->where('needs_rerank', true)
                    ->orWhere(static function ($recent) use ($windowCutoff, $refreshCutoff): void {
                        $recent->where('published_at', '>=', $windowCutoff)
                            ->where(static function ($stale) use ($refreshCutoff): void {
                                $stale->whereNull('trending_calculated_at')
                                    ->orWhere('trending_calculated_at', '<=', $refreshCutoff);
                            });
                    })
                    ->orWhere(static function ($decaying) use ($refreshCutoff): void {
                        $decaying->where('trending_score', '>', 0.01)
                            ->where(static function ($stale) use ($refreshCutoff): void {
                                $stale->whereNull('trending_calculated_at')
                                    ->orWhere('trending_calculated_at', '<=', $refreshCutoff);
                            });
                    });
            })
            ->orderByDesc('needs_rerank')
            ->orderByDesc('published_at')
            ->limit(max(20, $batchSize))
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            return ['processed' => 0, 'zeroed' => (int) $zeroed];
        }

        $processed = 0;

        foreach ($candidateIds->chunk(50) as $idChunk) {
            $posts = Post::query()->whereIn('id', $idChunk->all())->get();

            foreach ($posts as $post) {
                $this->recalculatePost($post, false);
                $processed++;
            }
        }

        return [
            'processed' => $processed,
            'zeroed' => (int) $zeroed,
        ];
    }

    /**
     * Resolve genuine organic human/guest views excluding promoted ad/boost views.
     */
    public function resolveOrganicHumanViews(Post $post): int
    {
        $human = (int) ($post->human_views_count ?? 0);
        $guest = (int) ($post->guest_views_count ?? 0);
        $legacy = (int) ($post->legacy_views_count ?? 0);
        $promoted = (int) ($post->promoted_views_count ?? 0);

        return max(0, $human + $guest + $legacy - $promoted);
    }

    /**
     * @return array{
     *     valid_reactions_count: int,
     *     valid_comments_count: int,
     *     valid_bookmarks_count: int,
     *     valid_shares_count: int
     * }
     */
    public function countAuthoritativeValidSignals(Post $post): array
    {
        $cap = $this->config->trendingWeights()['per_user_cap'];

        $validReactions = PostReaction::query()
            ->where('post_id', $post->id)
            ->where('user_id', '!=', $post->user_id)
            ->whereHas('user', static function ($query): void {
                $query->where('account_type', AccountType::Human->value)
                    ->where('is_test_account', false)
                    ->where('status', UserStatus::Active->value);
            })
            ->count();

        $validComments = Comment::query()
            ->where('post_id', $post->id)
            ->where('user_id', '!=', $post->user_id)
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->where('is_spam', false)
            ->whereHas('author', static function ($query): void {
                $query->where('account_type', AccountType::Human->value)
                    ->where('is_test_account', false)
                    ->where('status', UserStatus::Active->value);
            })
            ->count();

        $validBookmarks = Bookmark::query()
            ->where('post_id', $post->id)
            ->where('user_id', '!=', $post->user_id)
            ->whereHas('user', static function ($query): void {
                $query->where('account_type', AccountType::Human->value)
                    ->where('is_test_account', false)
                    ->where('status', UserStatus::Active->value);
            })
            ->count();

        $validShares = PostShare::query()
            ->where('post_id', $post->id)
            ->where('is_valid', true)
            ->where(static function ($query) use ($post): void {
                $query->whereNull('user_id')
                    ->orWhere('user_id', '!=', $post->user_id);
            })
            ->count();

        // Respect already-capped live values if an upstream spammer hit the per-user 24h cap.
        $existingReactions = (int) ($post->valid_reactions_count ?? 0);
        $existingBookmarks = (int) ($post->valid_bookmarks_count ?? 0);
        $existingShares = (int) ($post->valid_shares_count ?? 0);

        return [
            'valid_reactions_count' => $cap > 0 && $existingReactions > 0 && $existingReactions < $validReactions
                ? $existingReactions
                : $validReactions,
            'valid_comments_count' => $validComments,
            'valid_bookmarks_count' => $cap > 0 && $existingBookmarks > 0 && $existingBookmarks < $validBookmarks
                ? $existingBookmarks
                : $validBookmarks,
            'valid_shares_count' => $cap > 0 && $existingShares > 0 && $existingShares < $validShares
                ? $existingShares
                : $validShares,
        ];
    }

    /**
     * @return array{0: float, 1: bool}
     */
    private function applyViewEngagementDampening(
        float $views,
        float $reactions,
        float $comments,
        float $bookmarks,
        float $shares,
        int $ratio,
    ): array {
        if ($ratio <= 0 || $views <= 0.0) {
            return [$views, false];
        }

        $totalEngagements = $reactions + $comments + $bookmarks + $shares;
        $threshold = $totalEngagements > 0.0
            ? ($totalEngagements * (float) $ratio)
            : max(25.0, (float) $ratio / 4.0);

        if ($views <= $threshold) {
            return [$views, false];
        }

        $excess = $views - $threshold;
        $dampened = $threshold + sqrt($excess);

        return [$dampened, true];
    }

    private function resolveAgeHours(CarbonInterface $publishedAt): float
    {
        $seconds = max(0, now()->getTimestamp() - $publishedAt->getTimestamp());

        return $seconds / 3600.0;
    }

    /**
     * Flag a post for asynchronous re-ranking cleanly without overwriting views or timestamps.
     */
    public static function markNeedsRerank(int $postId): void
    {
        DB::table('posts')
            ->where('id', $postId)
            ->update(['needs_rerank' => true]);
    }
}
