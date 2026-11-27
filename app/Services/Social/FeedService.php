<?php

namespace App\Services\Social;

use App\Enums\AdCampaignStatus;
use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use App\Enums\UserStatus;
use App\Models\AdCampaign;
use App\Models\Bookmark;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostPoll;
use App\Models\PostPollOption;
use App\Models\PostPollVote;
use App\Models\PostReaction;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Growth\EarlyMemberAndBadgeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeedService
{
    /**
     * Fetch paginated public News Feed posts with viewer-specific state and zero N+1 queries.
     *
     * @return array{
     *     data: list<array<string, mixed>>,
     *     next_cursor: string|null,
     *     has_more: bool
     * }
     */
    public function publicFeed(?User $viewer, string $tab = 'trending', ?string $cursor = null, int $perPage = 10): array
    {
        $query = $this->baseVisibleQuery($viewer);

        if (in_array($tab, array_map(static fn (PostCategory $c): string => $c->value, PostCategory::cases()), true)) {
            $query->where('category', $tab)
                ->orderByDesc('published_at')
                ->orderByDesc('id');
        } elseif ($tab === 'latest') {
            $query->orderByDesc('published_at')
                ->orderByDesc('id');
        } else {
            $query->orderByDesc('reactions_count')
                ->orderByDesc('comments_count')
                ->orderByDesc('published_at')
                ->orderByDesc('id');
        }

        $paginator = $query->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
        $items = collect($paginator->items());

        return [
            'data' => $this->serializeCollection($items, $viewer),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }

    /**
     * Build base visible public post query excluding blocked and inactive accounts.
     *
     * @return Builder<Post>
     */
    public function baseVisibleQuery(?User $viewer): Builder
    {
        $query = Post::query()
            ->publicVisible()
            ->with([
                'author:id,name,username,status,premium_until,premium_suspended_at,account_type,ai_disclosure',
                'author.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
                'community:id,name,slug',
                'hashtags:id,name,slug',
                'media',
                'poll.options',
            ])
            ->whereHas('author', static function (Builder $authorQuery): void {
                $authorQuery->where('status', UserStatus::Active->value);
            });

        if ($viewer !== null) {
            $blockedIds = $this->mutualBlockedUserIds($viewer);

            if ($blockedIds !== []) {
                $query->whereNotIn('user_id', $blockedIds);
            }
        }

        return $query;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    public function serializeCollection(Collection $posts, ?User $viewer): array
    {
        if ($posts->isEmpty()) {
            return [];
        }

        $postIds = $posts->pluck('id')->all();
        $pollIds = $posts
            ->map(static fn (Post $post): ?int => $post->poll?->id)
            ->filter(static fn (?int $id): bool => $id !== null)
            ->values()
            ->all();

        /** @var array<int, string> $viewerReactions */
        $viewerReactions = [];
        /** @var array<int, true> $viewerBookmarks */
        $viewerBookmarks = [];
        /** @var array<int, list<int>> $viewerVotes */
        $viewerVotes = [];
        /** @var array<int, array<string, mixed>> $ownerBoosts */
        $ownerBoosts = [];

        if ($viewer !== null) {
            $viewerReactions = PostReaction::query()
                ->where('user_id', $viewer->id)
                ->whereIn('post_id', $postIds)
                ->pluck('type', 'post_id')
                ->map(static fn (mixed $type): string => is_object($type) && property_exists($type, 'value') ? (string) $type->value : (string) $type)
                ->all();

            foreach (Bookmark::query()->where('user_id', $viewer->id)->whereIn('post_id', $postIds)->pluck('post_id') as $bookmarkedPostId) {
                $viewerBookmarks[(int) $bookmarkedPostId] = true;
            }

            if ($pollIds !== []) {
                $votes = PostPollVote::query()
                    ->where('user_id', $viewer->id)
                    ->whereIn('poll_id', $pollIds)
                    ->get(['poll_id', 'option_id']);

                foreach ($votes as $vote) {
                    $viewerVotes[(int) $vote->poll_id][] = (int) $vote->option_id;
                }
            }

            $boostRows = AdCampaign::query()
                ->where('user_id', $viewer->id)
                ->whereIn('post_id', $postIds)
                ->whereIn('status', [
                    AdCampaignStatus::PendingPayment->value,
                    AdCampaignStatus::PendingReview->value,
                    AdCampaignStatus::Active->value,
                    AdCampaignStatus::Paused->value,
                ])
                ->latest('id')
                ->get(['id', 'post_id', 'status', 'impressions_count', 'clicks_count', 'ends_at', 'is_featured_slot']);

            foreach ($boostRows as $b) {
                if (! isset($ownerBoosts[$b->post_id])) {
                    $ownerBoosts[$b->post_id] = [
                        'id' => $b->id,
                        'status' => $b->status->value,
                        'status_label' => $b->status->label(),
                        'impressions_count' => $b->impressions_count,
                        'clicks_count' => $b->clicks_count,
                        'ends_at' => $b->ends_at?->toIso8601String(),
                        'is_featured_slot' => $b->is_featured_slot,
                    ];
                }
            }
        }

        $authorIds = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $posts->pluck('user_id')->all())));
        $badgesByUser = app(EarlyMemberAndBadgeService::class)->activeBadgesForUsers($authorIds);

        /** @var array<int, array<string, int>> $reactionSummaries */
        $reactionSummaries = [];
        $reactionCountRows = DB::table('post_reactions')
            ->whereIn('post_id', $postIds)
            ->selectRaw('post_id, type, COUNT(*) as total')
            ->groupBy('post_id', 'type')
            ->get();

        foreach ($reactionCountRows as $row) {
            $pid = (int) $row->post_id;
            $rxType = (string) $row->type;
            $reactionSummaries[$pid][$rxType] = (int) $row->total;
        }

        return array_values(
            $posts->map(function (Post $post) use ($viewer, $viewerReactions, $viewerBookmarks, $viewerVotes, $ownerBoosts, $badgesByUser, $reactionSummaries): array {
                $item = $this->serializeOne(
                    $post,
                    $viewer,
                    $viewerReactions[$post->id] ?? null,
                    isset($viewerBookmarks[$post->id]),
                    $post->poll !== null ? ($viewerVotes[$post->poll->id] ?? []) : [],
                    $reactionSummaries[$post->id] ?? [],
                );
                if (is_array($item['author'] ?? null)) {
                    $item['author']['badges'] = $badgesByUser[$post->user_id] ?? [];
                }
                $item['boost_summary'] = $ownerBoosts[$post->id] ?? null;

                return $item;
            })->all(),
        );
    }

    /**
     * Serialize a single post cleanly for Inertia payloads.
     *
     * @param  list<int>  $votedOptionIds
     * @param  array<string, int>|null  $reactionSummary
     * @return array<string, mixed>
     */
    public function serializeOne(
        Post $post,
        ?User $viewer = null,
        ?string $viewerReaction = null,
        bool $isBookmarked = false,
        array $votedOptionIds = [],
        ?array $reactionSummary = null,
    ): array {
        $author = $post->author;
        $profile = $author?->profile;

        if ($reactionSummary === null && $post->reactions_count > 0) {
            $reactionSummary = [];
            $rows = DB::table('post_reactions')
                ->where('post_id', $post->id)
                ->selectRaw('type, COUNT(*) as total')
                ->groupBy('type')
                ->get();
            foreach ($rows as $row) {
                $reactionSummary[(string) $row->type] = (int) $row->total;
            }
        }

        if ($viewer !== null && $viewerReaction === null && $votedOptionIds === []) {
            $reactionRecord = PostReaction::query()
                ->where('post_id', $post->id)
                ->where('user_id', $viewer->id)
                ->first();
            $viewerReaction = $reactionRecord?->type->value;

            $isBookmarked = Bookmark::query()
                ->where('post_id', $post->id)
                ->where('user_id', $viewer->id)
                ->exists();

            if ($post->poll !== null) {
                $votedOptionIds = array_values(
                    PostPollVote::query()
                        ->where('poll_id', $post->poll->id)
                        ->where('user_id', $viewer->id)
                        ->pluck('option_id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all(),
                );
            }
        }

        return [
            'id' => $post->id,
            'category' => $post->category->value,
            'category_label' => $post->category->label(),
            'title' => $post->title,
            'content' => $post->content,
            'link_url' => $post->link_url,
            'link_meta' => $post->link_meta,
            'visibility' => $post->visibility->value,
            'is_pinned' => $post->is_pinned,
            'moderation_status' => $post->moderation_status->value,
            'reactions_count' => $post->reactions_count,
            'reaction_summary' => $reactionSummary ?? [],
            'comments_count' => $post->comments_count,
            'shares_count' => $post->shares_count,
            'views_count' => (int) ($post->views_count ?? 0),
            'organic_views_count' => (int) ($post->organic_views_count ?? 0),
            'promoted_views_count' => (int) ($post->promoted_views_count ?? 0),
            'unique_viewers_count' => (int) ($post->unique_viewers_count ?? 0),
            'view_analytics' => $viewer !== null && ($viewer->id === $post->user_id || $viewer->isStaff())
                ? [
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
                ]
                : null,
            'published_at' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
            'content_origin' => $post->content_origin->value,
            'is_ai_assisted' => (bool) $post->is_ai_assisted,
            'viewer_reaction' => $viewerReaction,
            'is_bookmarked' => $isBookmarked,
            'can_edit' => $viewer !== null && $viewer->isActive() && $viewer->id === $post->user_id,
            'can_delete' => $viewer !== null && $viewer->isActive() && ($viewer->id === $post->user_id || $viewer->isStaff()),
            'can_boost' => $viewer !== null
                && $viewer->isActive()
                && ! $viewer->isRestrictedFrom('advertise')
                && $viewer->id === $post->user_id
                && $post->visibility === PostVisibility::Public
                && $post->moderation_status === ModerationStatus::Approved,
            'author' => $author === null ? null : [
                'id' => $author->id,
                'name' => $author->name,
                'username' => $author->username,
                'headline' => $profile?->headline,
                'avatar' => $profile?->avatarUrl(),
                'is_verified' => $profile !== null && $profile->is_verified_badge,
                'is_premium' => $author->isPremium(),
                'is_ai_agent' => $author->isAiAgent(),
                'ai_disclosure' => $author->ai_disclosure,
            ],
            'community' => $post->community === null ? null : [
                'id' => $post->community->id,
                'name' => $post->community->name,
                'slug' => $post->community->slug,
            ],
            'hashtags' => $post->relationLoaded('hashtags') ? array_values(
                $post->hashtags->map(static fn (Hashtag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ])->all(),
            ) : [],
            'media' => array_values(
                $post->media->map(static fn (PostMedia $media): array => [
                    'id' => $media->id,
                    'url' => $media->url(),
                    'mime_type' => $media->mime_type,
                    'width' => $media->width,
                    'height' => $media->height,
                    'alt_text' => $media->alt_text,
                ])->all(),
            ),
            'poll' => $this->serializePoll($post->poll, $votedOptionIds),
        ];
    }

    /**
     * @return list<int>
     */
    public function mutualBlockedUserIds(User $viewer): array
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

    /**
     * @param  list<int>  $votedOptionIds
     * @return array<string, mixed>|null
     */
    private function serializePoll(?PostPoll $poll, array $votedOptionIds): ?array
    {
        if ($poll === null) {
            return null;
        }

        $totalVotes = max(0, $poll->total_votes);

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'ends_at' => $poll->ends_at?->toIso8601String(),
            'is_expired' => $poll->isExpired(),
            'allows_multiple' => $poll->allows_multiple,
            'total_votes' => $totalVotes,
            'voted_option_ids' => $votedOptionIds,
            'options' => array_values(
                $poll->options->map(static function (PostPollOption $option) use ($totalVotes): array {
                    $percentage = $totalVotes > 0
                        ? (int) round(($option->votes_count / $totalVotes) * 100)
                        : 0;

                    return [
                        'id' => $option->id,
                        'label' => $option->label,
                        'votes_count' => $option->votes_count,
                        'percentage' => $percentage,
                    ];
                })->all(),
            ),
        ];
    }

    public function canViewerSeePost(Post $post, ?User $viewer): bool
    {
        if ($post->moderation_status !== ModerationStatus::Approved) {
            return $viewer !== null && ($viewer->id === $post->user_id || $viewer->isStaff());
        }

        if ($post->visibility === PostVisibility::Private) {
            return $viewer !== null && $viewer->id === $post->user_id;
        }

        if ($viewer !== null && in_array($post->user_id, $this->mutualBlockedUserIds($viewer), true)) {
            return false;
        }

        return true;
    }
}
