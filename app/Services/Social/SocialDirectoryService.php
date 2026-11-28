<?php

namespace App\Services\Social;

use App\Enums\ReactionType;
use App\Enums\UserStatus;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SocialDirectoryService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 30;

    public function __construct(
        private readonly FeedService $feedService,
        private readonly UserBadgeResolverService $badgeResolver,
    ) {}

    /**
     * Paginated list of active users who reacted to a post, with reaction type filter,
     * live counts by reaction type, search, and viewer relationship status (zero N+1).
     *
     * @return array{
     *     total_count: int,
     *     counts_by_type: array<string, int>,
     *     active_type: string|null,
     *     items: list<array<string, mixed>>,
     *     pagination: array{current_page: int, per_page: int, has_more: bool, next_page: int|null}
     * }
     */
    public function reactionUsers(
        Post $post,
        ?User $viewer,
        ?string $type = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): array {
        $safePage = max(1, $page);
        $safePerPage = max(5, min(self::MAX_PER_PAGE, $perPage));
        $validTypes = array_map(static fn (ReactionType $r): string => $r->value, ReactionType::cases());
        $activeType = $type !== null && in_array($type, $validTypes, true) ? $type : null;
        $blockedIds = $viewer !== null ? $this->feedService->mutualBlockedUserIds($viewer) : [];

        // 1. Accurate per-type counts across active, non-blocked users in one grouped query
        $countsQuery = DB::table('post_reactions')
            ->join('users', 'users.id', '=', 'post_reactions.user_id')
            ->where('post_reactions.post_id', $post->id)
            ->where('users.status', UserStatus::Active->value)
            ->whereNull('users.deleted_at')
            ->whereNull('users.deactivated_at');

        if ($blockedIds !== []) {
            $countsQuery->whereNotIn('post_reactions.user_id', $blockedIds);
        }

        $grouped = $countsQuery
            ->selectRaw('post_reactions.type as reaction_type, COUNT(*) as total')
            ->groupBy('post_reactions.type')
            ->pluck('total', 'reaction_type');

        $countsByType = [];
        $totalCount = 0;
        foreach ($validTypes as $rxType) {
            $count = (int) ($grouped[$rxType] ?? 0);
            $countsByType[$rxType] = $count;
            $totalCount += $count;
        }

        // 2. Paginated reaction users query (fetch perPage + 1 to detect has_more without extra COUNT)
        $query = PostReaction::query()
            ->where('post_reactions.post_id', $post->id)
            ->whereHas('user', function (Builder $userQuery) use ($blockedIds, $search): void {
                $this->applyActiveUserConstraints($userQuery, $blockedIds, $search);
            })
            ->with([
                'user:id,name,username,avatar_url,role,rbac_role_id,status,premium_until,premium_suspended_at,account_type,ai_disclosure',
                'user.rbacRole:id,slug,name',
                'user.profile:id,user_id,headline,bio,location,avatar_disk,avatar_path,is_verified_badge',
            ])
            ->orderByDesc('post_reactions.created_at')
            ->orderByDesc('post_reactions.id');

        if ($activeType !== null) {
            $query->where('post_reactions.type', $activeType);
        }

        $rows = $query
            ->offset(($safePage - 1) * $safePerPage)
            ->limit($safePerPage + 1)
            ->get();

        $hasMore = $rows->count() > $safePerPage;
        $pageRows = $hasMore ? $rows->slice(0, $safePerPage)->values() : $rows->values();

        /** @var Collection<int, User> $users */
        $users = $pageRows
            ->map(static fn (PostReaction $reaction): ?User => $reaction->user)
            ->filter(static fn (?User $u): bool => $u !== null)
            ->values();

        $meta = $this->resolveRelationMeta($users, $viewer);

        $items = [];
        foreach ($pageRows as $reaction) {
            $user = $reaction->user;
            if ($user === null) {
                continue;
            }

            $item = $this->serializeUserRow($user, $viewer, $meta['following_ids'], $meta['badges']);
            $item['reaction_type'] = $reaction->type->value;
            $item['reacted_at'] = $reaction->created_at->toIso8601String();
            $items[] = $item;
        }

        return [
            'total_count' => $totalCount,
            'counts_by_type' => $countsByType,
            'active_type' => $activeType,
            'items' => $items,
            'pagination' => [
                'current_page' => $safePage,
                'per_page' => $safePerPage,
                'has_more' => $hasMore,
                'next_page' => $hasMore ? $safePage + 1 : null,
            ],
        ];
    }

    /**
     * Paginated list of followers or following accounts for a profile,
     * with search, counts, block/moderation filtering, and live viewer follow status.
     *
     * @return array{
     *     tab: 'followers'|'following',
     *     counts: array{followers: int, following: int},
     *     restricted: bool,
     *     items: list<array<string, mixed>>,
     *     pagination: array{current_page: int, per_page: int, has_more: bool, next_page: int|null}
     * }
     */
    public function followList(
        User $targetUser,
        string $tab,
        ?User $viewer,
        ?string $search = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): array {
        $normalizedTab = $tab === 'following' ? 'following' : 'followers';
        $safePage = max(1, $page);
        $safePerPage = max(5, min(self::MAX_PER_PAGE, $perPage));
        $blockedIds = $viewer !== null ? $this->feedService->mutualBlockedUserIds($viewer) : [];

        // Respect mutual blocks between viewer and profile owner
        if ($viewer !== null && $viewer->id !== $targetUser->id && in_array($targetUser->id, $blockedIds, true)) {
            return [
                'tab' => $normalizedTab,
                'counts' => ['followers' => 0, 'following' => 0],
                'restricted' => true,
                'items' => [],
                'pagination' => [
                    'current_page' => $safePage,
                    'per_page' => $safePerPage,
                    'has_more' => false,
                    'next_page' => null,
                ],
            ];
        }

        $counts = $this->visibleFollowCounts($targetUser, $blockedIds);

        $relationName = $normalizedTab === 'followers' ? 'follower' : 'following';
        $whereColumn = $normalizedTab === 'followers' ? 'follows.following_id' : 'follows.follower_id';

        $rows = Follow::query()
            ->where($whereColumn, $targetUser->id)
            ->whereHas($relationName, function (Builder $userQuery) use ($blockedIds, $search): void {
                $this->applyActiveUserConstraints($userQuery, $blockedIds, $search, includeHeadline: true);
            })
            ->with([
                "{$relationName}:id,name,username,avatar_url,role,rbac_role_id,status,premium_until,premium_suspended_at,account_type,ai_disclosure",
                "{$relationName}.rbacRole:id,slug,name",
                "{$relationName}.profile:id,user_id,headline,bio,location,avatar_disk,avatar_path,is_verified_badge",
            ])
            ->orderByDesc('follows.created_at')
            ->orderByDesc('follows.id')
            ->offset(($safePage - 1) * $safePerPage)
            ->limit($safePerPage + 1)
            ->get();

        $hasMore = $rows->count() > $safePerPage;
        $pageRows = $hasMore ? $rows->slice(0, $safePerPage)->values() : $rows->values();

        /** @var Collection<int, User> $users */
        $users = $pageRows
            ->map(static fn (Follow $follow): ?User => $normalizedTab === 'followers' ? $follow->follower : $follow->following)
            ->filter(static fn (?User $u): bool => $u !== null)
            ->values();

        $meta = $this->resolveRelationMeta($users, $viewer);

        $items = [];
        foreach ($pageRows as $follow) {
            $member = $normalizedTab === 'followers' ? $follow->follower : $follow->following;
            if ($member === null) {
                continue;
            }

            $item = $this->serializeUserRow($member, $viewer, $meta['following_ids'], $meta['badges']);
            $item['followed_at'] = $follow->created_at->toIso8601String();
            $items[] = $item;
        }

        return [
            'tab' => $normalizedTab,
            'counts' => $counts,
            'restricted' => false,
            'items' => $items,
            'pagination' => [
                'current_page' => $safePage,
                'per_page' => $safePerPage,
                'has_more' => $hasMore,
                'next_page' => $hasMore ? $safePage + 1 : null,
            ],
        ];
    }

    /**
     * @param  list<int>  $blockedIds
     * @return array{followers: int, following: int}
     */
    private function visibleFollowCounts(User $targetUser, array $blockedIds): array
    {
        $followersQuery = DB::table('follows')
            ->join('users', 'users.id', '=', 'follows.follower_id')
            ->where('follows.following_id', $targetUser->id)
            ->where('users.status', UserStatus::Active->value)
            ->whereNull('users.deleted_at')
            ->whereNull('users.deactivated_at');

        $followingQuery = DB::table('follows')
            ->join('users', 'users.id', '=', 'follows.following_id')
            ->where('follows.follower_id', $targetUser->id)
            ->where('users.status', UserStatus::Active->value)
            ->whereNull('users.deleted_at')
            ->whereNull('users.deactivated_at');

        if ($blockedIds !== []) {
            $followersQuery->whereNotIn('follows.follower_id', $blockedIds);
            $followingQuery->whereNotIn('follows.following_id', $blockedIds);
        }

        return [
            'followers' => (int) $followersQuery->count(),
            'following' => (int) $followingQuery->count(),
        ];
    }

    /**
     * @param  Builder<User>  $query
     * @param  list<int>  $blockedIds
     */
    private function applyActiveUserConstraints(
        Builder $query,
        array $blockedIds,
        ?string $search,
        bool $includeHeadline = false,
    ): void {
        $query->where('status', UserStatus::Active->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at');

        if ($blockedIds !== []) {
            $query->whereNotIn('id', $blockedIds);
        }

        $trimmed = $search !== null ? trim($search) : '';
        if ($trimmed !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($trimmed));
            $like = "%{$escaped}%";

            $query->where(static function (Builder $sub) use ($like, $includeHeadline): void {
                $sub->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(username) LIKE ? ESCAPE '!'", [$like]);

                if ($includeHeadline) {
                    $sub->orWhereHas('profile', static function (Builder $profileQuery) use ($like): void {
                        $profileQuery->whereRaw("LOWER(headline) LIKE ? ESCAPE '!'", [$like]);
                    });
                }
            });
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{following_ids: array<int, true>, badges: array<int, list<array<string, mixed>>>}
     */
    private function resolveRelationMeta(Collection $users, ?User $viewer): array
    {
        $userIds = array_values(array_unique(
            $users->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        ));

        if ($userIds === []) {
            return ['following_ids' => [], 'badges' => []];
        }

        /** @var array<int, true> $followingIds */
        $followingIds = [];
        if ($viewer !== null) {
            foreach (
                Follow::query()
                    ->where('follower_id', $viewer->id)
                    ->whereIn('following_id', $userIds)
                    ->pluck('following_id') as $followedId
            ) {
                $followingIds[(int) $followedId] = true;
            }
        }

        $badges = $this->badgeResolver->compactForUsers($users);

        return [
            'following_ids' => $followingIds,
            'badges' => $badges,
        ];
    }

    /**
     * @param  array<int, true>  $viewerFollowingIds
     * @param  array<int, list<array<string, mixed>>>  $badgesByUser
     * @return array<string, mixed>
     */
    private function serializeUserRow(
        User $member,
        ?User $viewer,
        array $viewerFollowingIds,
        array $badgesByUser,
    ): array {
        $profile = $member->profile;
        $isSelf = $viewer !== null && $viewer->id === $member->id;
        $compact = $badgesByUser[$member->id] ?? [];

        return [
            'id' => $member->id,
            'name' => $member->name,
            'username' => $member->username,
            'role' => $member->role->value,
            'role_label' => $member->role->label(),
            'headline' => $profile?->headline,
            'bio' => $profile?->bio,
            'location' => $profile?->location,
            'avatar' => $member->avatarUrl(),
            'is_verified' => $profile !== null && $profile->is_verified_badge,
            'is_premium' => $member->isPremium(),
            'is_ai_agent' => $member->isAiAgent(),
            'ai_disclosure' => $member->ai_disclosure,
            'compact_badges' => $compact,
            'badges' => $compact,
            'is_self' => $isSelf,
            'is_following' => ! $isSelf && isset($viewerFollowingIds[$member->id]),
            'can_follow' => ! $isSelf,
        ];
    }
}
