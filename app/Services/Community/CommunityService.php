<?php

namespace App\Services\Community;

use App\Enums\CommunityRole;
use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\UserStatus;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\SafeImageUploadService;
use App\Services\Security\AuditLogger;
use App\Services\Social\FeedService;
use App\Services\Social\HashtagService;
use App\Services\Social\UserBadgeResolverService;
use App\Services\System\SystemSettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunityService
{
    public function __construct(
        private readonly SafeImageUploadService $imageUploadService,
        private readonly FeedService $feedService,
        private readonly HashtagService $hashtagService,
        private readonly AuditLogger $auditLogger,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * @return array{
     *     communities: list<array<string, mixed>>,
     *     filter: string,
     *     search: string,
     *     categories: list<array{value: string, label: string}>
     * }
     */
    public function directoryPayload(?User $viewer, string $filter = 'all', ?string $search = null): array
    {
        $query = Community::query()
            ->where('visibility', 'public')
            ->where('status', 'active');

        if ($viewer !== null && $filter === 'joined') {
            $query->whereHas('members', static function (Builder $memberQuery) use ($viewer): void {
                $memberQuery->where('user_id', $viewer->id);
            });
        }

        $cleanSearch = trim((string) $search);

        if ($cleanSearch !== '') {
            $escaped = addcslashes($cleanSearch, '\\%_');
            $pattern = "%{$escaped}%";
            $query->where(static function (Builder $sub) use ($pattern): void {
                $sub->where('name', 'like', $pattern)
                    ->orWhere('description', 'like', $pattern);
            });
        }

        $communities = $query
            ->orderByDesc('members_count')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $joinedIds = $this->viewerJoinedCommunityIds($viewer);

        return [
            'communities' => array_values(
                $communities->map(fn (Community $community): array => $this->serializeSummary(
                    $community,
                    isset($joinedIds[$community->id]),
                ))->all(),
            ),
            'filter' => $filter === 'joined' ? 'joined' : 'all',
            'search' => $cleanSearch,
            'categories' => PostCategory::options(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function featuredForSidebar(?User $viewer, int $limit = 3): array
    {
        $communities = Community::query()
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->orderByDesc('is_featured')
            ->orderByDesc('members_count')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $joinedIds = $this->viewerJoinedCommunityIds($viewer);

        return array_values(
            $communities->map(fn (Community $community): array => $this->serializeSummary(
                $community,
                isset($joinedIds[$community->id]),
            ))->all(),
        );
    }

    /**
     * @param  array{
     *     name: string,
     *     category: string,
     *     description?: string|null,
     *     visibility?: string|null,
     *     rules?: list<string>|null
     * }  $data
     */
    public function create(
        User $owner,
        array $data,
        ?UploadedFile $avatar = null,
        ?UploadedFile $cover = null,
    ): Community {
        if (! $this->settings->featureEnabled('feature_communities')) {
            throw ValidationException::withMessages([
                'name' => [__('Creating new communities is currently disabled.')],
            ]);
        }

        if ($owner->isRestrictedFrom('community')) {
            throw ValidationException::withMessages([
                'name' => [__('Your account is currently restricted from creating communities.')],
            ]);
        }
        $storedAvatar = $avatar !== null ? $this->imageUploadService->storeImage($avatar, 'communities/avatars', 'community_avatar', 'avatar') : null;
        $storedCover = $cover !== null ? $this->imageUploadService->storeImage($cover, 'communities/covers', 'community_cover', 'cover') : null;

        try {
            $community = DB::transaction(function () use ($owner, $data, $storedAvatar, $storedCover): Community {
                $baseSlug = Str::slug($data['name']) ?: 'community';
                $slug = $baseSlug;
                $suffix = 1;

                while (Community::query()->where('slug', $slug)->exists()) {
                    $suffix++;
                    $slug = "{$baseSlug}-{$suffix}";
                }

                $rules = array_values(array_filter(
                    array_map(static fn (string $rule): string => trim($rule), $data['rules'] ?? []),
                    static fn (string $rule): bool => $rule !== '',
                ));

                $category = PostCategory::tryFrom($data['category']) ?? PostCategory::General;

                $community = new Community([
                    'name' => trim($data['name']),
                    'slug' => $slug,
                    'category' => $category->value,
                    'description' => isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null,
                    'rules' => $rules === [] ? null : array_slice($rules, 0, 10),
                    'visibility' => ($data['visibility'] ?? 'public') === 'private' ? 'private' : 'public',
                ]);
                $community->owner_id = $owner->id;
                $community->members_count = 1;
                $community->posts_count = 0;

                if ($storedAvatar !== null) {
                    $community->avatar_disk = $storedAvatar['disk'];
                    $community->avatar_path = $storedAvatar['path'];
                }

                if ($storedCover !== null) {
                    $community->cover_disk = $storedCover['disk'];
                    $community->cover_path = $storedCover['path'];
                }

                $community->save();

                CommunityMember::query()->create([
                    'community_id' => $community->id,
                    'user_id' => $owner->id,
                    'role' => CommunityRole::Owner->value,
                    'points' => 10,
                    'joined_at' => now(),
                ]);

                return $community;
            });
        } catch (\Throwable $exception) {
            if ($storedAvatar !== null) {
                $this->imageUploadService->deleteIfExists($storedAvatar['disk'], $storedAvatar['path']);
            }

            if ($storedCover !== null) {
                $this->imageUploadService->deleteIfExists($storedCover['disk'], $storedCover['path']);
            }

            throw $exception;
        }

        $this->auditLogger->log('community.created', $community, ['slug' => $community->slug], $owner);

        return $community;
    }

    /**
     * @param  array{
     *     name: string,
     *     category: string,
     *     description?: string|null,
     *     rules?: list<string>|null,
     *     remove_avatar?: bool,
     *     remove_cover?: bool
     * }  $data
     */
    public function update(
        User $actor,
        Community $community,
        array $data,
        ?UploadedFile $avatar = null,
        ?UploadedFile $cover = null,
    ): Community {
        $newAvatar = $avatar !== null ? $this->imageUploadService->storeImage($avatar, 'communities/avatars', 'community_avatar', 'avatar') : null;
        $newCover = $cover !== null ? $this->imageUploadService->storeImage($cover, 'communities/covers', 'community_cover', 'cover') : null;

        $removeAvatar = $newAvatar === null && filter_var($data['remove_avatar'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $removeCover = $newCover === null && filter_var($data['remove_cover'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $oldAvatarDisk = $community->avatar_disk;
        $oldAvatarPath = $community->avatar_path;
        $oldCoverDisk = $community->cover_disk;
        $oldCoverPath = $community->cover_path;

        $rules = array_values(array_filter(
            array_map(static fn (string $rule): string => trim($rule), $data['rules'] ?? []),
            static fn (string $rule): bool => $rule !== '',
        ));

        $category = PostCategory::tryFrom($data['category']) ?? $community->category;

        $community->fill([
            'name' => trim($data['name']),
            'category' => $category->value,
            'description' => isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null,
            'rules' => $rules === [] ? null : array_slice($rules, 0, 10),
        ]);

        if ($newAvatar !== null) {
            $community->avatar_disk = $newAvatar['disk'];
            $community->avatar_path = $newAvatar['path'];
        } elseif ($removeAvatar) {
            $community->avatar_disk = null;
            $community->avatar_path = null;
        }

        if ($newCover !== null) {
            $community->cover_disk = $newCover['disk'];
            $community->cover_path = $newCover['path'];
        } elseif ($removeCover) {
            $community->cover_disk = null;
            $community->cover_path = null;
        }

        $community->save();

        if ($newAvatar !== null || $removeAvatar) {
            $this->imageUploadService->deleteIfExists($oldAvatarDisk, $oldAvatarPath);
        }

        if ($newCover !== null || $removeCover) {
            $this->imageUploadService->deleteIfExists($oldCoverDisk, $oldCoverPath);
        }

        $this->auditLogger->log('community.updated', $community, ['slug' => $community->slug], $actor);

        return $community;
    }

    /**
     * @return array{joined: bool, members_count: int}
     */
    public function toggleMembership(User $user, Community $community): array
    {
        return DB::transaction(function () use ($user, $community): array {
            /** @var Community $locked */
            $locked = Community::query()->whereKey($community->id)->lockForUpdate()->firstOrFail();

            if ($locked->owner_id === $user->id) {
                throw ValidationException::withMessages([
                    'community' => [__('Community owners cannot leave their own community.')],
                ]);
            }

            $existing = CommunityMember::query()
                ->where('community_id', $locked->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing !== null) {
                $existing->delete();
                $joined = false;
            } else {
                if ($locked->status !== 'active' || ! $locked->allow_new_members) {
                    throw ValidationException::withMessages([
                        'community' => [__('This community is not accepting new members right now.')],
                    ]);
                }

                CommunityMember::query()->create([
                    'community_id' => $locked->id,
                    'user_id' => $user->id,
                    'role' => CommunityRole::Member->value,
                    'points' => 0,
                    'joined_at' => now(),
                ]);
                $joined = true;
            }

            $count = CommunityMember::query()->where('community_id', $locked->id)->count();
            $locked->members_count = $count;
            $locked->save();

            return [
                'joined' => $joined,
                'members_count' => $count,
            ];
        });
    }

    public function togglePinPost(User $actor, Community $community, Post $post): bool
    {
        if ($post->community_id !== $community->id) {
            abort(404);
        }

        $post->is_pinned = ! $post->is_pinned;
        $post->save();

        $this->auditLogger->log(
            $post->is_pinned ? 'community.post_pinned' : 'community.post_unpinned',
            $post,
            ['community_id' => $community->id],
            $actor,
        );

        return $post->is_pinned;
    }

    public function awardPoints(int $communityId, int $userId, int $points): void
    {
        CommunityMember::query()
            ->where('community_id', $communityId)
            ->where('user_id', $userId)
            ->increment('points', max(0, $points));
    }

    /**
     * @return array<string, mixed>
     */
    public function showPayload(
        Community $community,
        ?User $viewer,
        string $tab = 'posts',
        string $category = 'all',
        string $sort = 'latest',
    ): array {
        $membership = $viewer !== null
            ? CommunityMember::query()
                ->where('community_id', $community->id)
                ->where('user_id', $viewer->id)
                ->first()
            : null;

        $isMember = $membership !== null;
        $canModerate = $viewer !== null && ($viewer->isStaff() || $community->owner_id === $viewer->id || ($membership?->role->canModerate() ?? false));

        $postsQuery = Post::query()
            ->where('community_id', $community->id)
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->where('is_spam', false)
            ->where(static function (Builder $statusQuery): void {
                $statusQuery->where('lifecycle_status', 'published')
                    ->orWhereNull('lifecycle_status');
            })
            ->whereHas('author', static function (Builder $authorQuery): void {
                $authorQuery->where('status', UserStatus::Active->value);
            })
            ->with([
                'author:id,name,username,avatar_url,role,rbac_role_id,status,premium_until,premium_suspended_at,account_type,ai_disclosure',
                'author.rbacRole:id,slug,name',
                'author.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
                'community:id,name,slug',
                'hashtags:id,name,slug',
                'media',
                'poll.options',
            ]);

        if ($viewer !== null) {
            $blockedIds = $this->feedService->mutualBlockedUserIds($viewer);

            if ($blockedIds !== []) {
                $postsQuery->whereNotIn('user_id', $blockedIds);
            }
        }

        if ($category !== 'all' && PostCategory::tryFrom($category) !== null) {
            $postsQuery->where('category', $category);
        }

        $postsQuery->orderByDesc('is_pinned');

        if ($sort === 'trending') {
            $postsQuery->orderByDesc('trending_score')->orderByDesc('published_at')->orderByDesc('id');
        } else {
            $postsQuery->orderByDesc('published_at')->orderByDesc('id');
        }

        $posts = $this->feedService->serializeCollection($postsQuery->limit(25)->get(), $viewer);

        $memberRows = CommunityMember::query()
            ->where('community_id', $community->id)
            ->with([
                'user:id,name,username,avatar_url,role,rbac_role_id,status,premium_until,premium_suspended_at,account_type,ai_disclosure',
                'user.rbacRole:id,slug,name',
                'user.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
            ])
            ->orderByDesc('points')
            ->limit(30)
            ->get();

        $memberBadges = app(UserBadgeResolverService::class)->resolveForUsers($memberRows->pluck('user'));

        $members = array_values(
            $memberRows
                ->map(static fn (CommunityMember $member): array => [
                    'id' => $member->id,
                    'role' => $member->role->value,
                    'role_label' => $member->role->label(),
                    'points' => $member->points,
                    'joined_at' => $member->joined_at->toIso8601String(),
                    'user' => $member->user === null ? null : [
                        'id' => $member->user->id,
                        'name' => $member->user->name,
                        'username' => $member->user->username,
                        'headline' => $member->user->profile?->headline,
                        'avatar' => $member->user->avatarUrl(),
                        'is_verified' => $member->user->profile !== null && $member->user->profile->is_verified_badge,
                        'is_premium' => $member->user->isPremium(),
                        'is_ai_agent' => $member->user->isAiAgent(),
                        'ai_disclosure' => $member->user->ai_disclosure,
                        'compact_badges' => $memberBadges[$member->user->id]['compact'] ?? [],
                        'badges' => $memberBadges[$member->user->id]['compact'] ?? [],
                    ],
                ])
                ->all(),
        );

        $leaderboard = array_slice($members, 0, 5);

        return [
            'tab' => in_array($tab, ['posts', 'topics', 'members', 'rules'], true) ? $tab : 'posts',
            'activeCategory' => $category,
            'activeSort' => $sort === 'trending' ? 'trending' : 'latest',
            'categories' => PostCategory::options(),
            'community' => [
                ...$this->serializeSummary($community, $isMember),
                'description' => $community->description,
                'rules' => $community->rules ?? [],
                'visibility' => $community->visibility,
                'owner_id' => $community->owner_id,
                'is_owner' => $viewer !== null && ($viewer->id === $community->owner_id || $viewer->isAdmin()),
                'can_moderate' => $canModerate,
                'member_role' => $membership?->role->value,
            ],
            'posts' => $posts,
            'members' => $members,
            'leaderboard' => $leaderboard,
            'trending_hashtags' => $this->hashtagService->trending(5, $community->id),
        ];
    }

    /**
     * @return array<int, true>
     */
    public function viewerJoinedCommunityIds(?User $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        $ids = [];

        foreach (CommunityMember::query()->where('user_id', $viewer->id)->pluck('community_id') as $communityId) {
            $ids[(int) $communityId] = true;
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(Community $community, bool $isJoined): array
    {
        return [
            'id' => $community->id,
            'name' => $community->name,
            'slug' => $community->slug,
            'category' => $community->category->value,
            'category_label' => $community->category->label(),
            'description' => $community->description,
            'avatar' => $community->avatarUrl(),
            'cover' => $community->coverUrl(),
            'is_verified' => $community->is_verified_badge,
            'members_count' => $community->members_count,
            'posts_count' => $community->posts_count,
            'is_joined' => $isJoined,
        ];
    }
}
