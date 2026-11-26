<?php

namespace App\Services\Social;

use App\Enums\PostVisibility;
use App\Models\Bookmark;
use App\Models\Follow;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use App\Models\UserBlock;
use App\Notifications\SocialActivityNotification;
use App\Services\Growth\EarlyMemberAndBadgeService;
use App\Services\Media\SafeImageUploadService;
use App\Services\Notification\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileService
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly SafeImageUploadService $imageUploadService,
        private readonly NotificationService $notificationService,
    ) {}

    public function getOrCreateProfile(User $user): Profile
    {
        $profile = $user->profile;

        if ($profile !== null) {
            return $profile;
        }

        $profile = new Profile;
        $profile->user_id = $user->id;
        $profile->save();

        $user->setRelation('profile', $profile);

        return $profile;
    }

    /**
     * Build public or member-owned profile view payload.
     *
     * @return array{
     *     profile_user: array<string, mixed>,
     *     posts: list<array<string, mixed>>,
     *     bookmarks: list<array<string, mixed>>,
     *     tab: string
     * }
     */
    public function profilePayload(User $targetUser, ?User $viewer, string $tab = 'posts'): array
    {
        $profile = $this->getOrCreateProfile($targetUser);
        $isOwner = $viewer !== null && $viewer->id === $targetUser->id;
        $isBlocked = false;
        $isFollowing = false;

        if ($viewer !== null && ! $isOwner) {
            $isBlocked = UserBlock::query()
                ->where('blocker_id', $viewer->id)
                ->where('blocked_id', $targetUser->id)
                ->exists();

            $isFollowing = Follow::query()
                ->where('follower_id', $viewer->id)
                ->where('following_id', $targetUser->id)
                ->exists();
        }

        $posts = [];
        $bookmarks = [];

        $blockedMutual = $viewer !== null && ! $isOwner
            && in_array($targetUser->id, $this->feedService->mutualBlockedUserIds($viewer), true);

        if (! $blockedMutual) {
            $postQuery = Post::query()
                ->where('user_id', $targetUser->id)
                ->with([
                    'author:id,name,username,status,premium_until,premium_suspended_at,account_type,ai_disclosure',
                    'author.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
                    'media',
                    'poll.options',
                ])
                ->orderByDesc('published_at')
                ->orderByDesc('id');

            if (! $isOwner) {
                $postQuery->publicVisible();
            } else {
                $postQuery->whereIn('visibility', [
                    PostVisibility::Public->value,
                    PostVisibility::Community->value,
                    PostVisibility::Private->value,
                ]);
            }

            $posts = $this->feedService->serializeCollection($postQuery->limit(20)->get(), $viewer);
        }

        if ($isOwner && $tab === 'bookmarks') {
            $bookmarkedPostIds = Bookmark::query()
                ->where('user_id', $targetUser->id)
                ->latest('created_at')
                ->limit(20)
                ->pluck('post_id')
                ->all();

            if ($bookmarkedPostIds !== []) {
                $bookmarkedPosts = $this->feedService->baseVisibleQuery($viewer)
                    ->whereIn('id', $bookmarkedPostIds)
                    ->get();

                $bookmarks = $this->feedService->serializeCollection($bookmarkedPosts, $viewer);
            }
        }

        return [
            'tab' => $isOwner && $tab === 'bookmarks' ? 'bookmarks' : 'posts',
            'profile_user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'username' => $targetUser->username,
                'role_label' => $targetUser->role->label(),
                'headline' => $profile->headline,
                'bio' => $profile->bio,
                'location' => $profile->location,
                'website_url' => $profile->website_url,
                'avatar' => $profile->avatarUrl(),
                'cover' => $profile->coverUrl(),
                'is_verified' => $profile->is_verified_badge,
                'is_premium' => $targetUser->isPremium(),
                'account_type' => $targetUser->account_type->value,
                'is_ai_agent' => $targetUser->isAiAgent(),
                'ai_disclosure' => $targetUser->ai_disclosure,
                'badges' => app(EarlyMemberAndBadgeService::class)->activeBadgesForUsers([$targetUser->id])[$targetUser->id] ?? [],
                'followers_count' => $profile->followers_count,
                'following_count' => $profile->following_count,
                'posts_count' => Post::query()->where('user_id', $targetUser->id)->publicVisible()->count(),
                'joined_at' => $targetUser->created_at?->toIso8601String(),
                'is_owner' => $isOwner,
                'is_following' => $isFollowing,
                'is_blocked' => $isBlocked,
            ],
            'posts' => $posts,
            'bookmarks' => $bookmarks,
        ];
    }

    /**
     * @param  array{headline?: string|null, bio?: string|null, location?: string|null, website_url?: string|null}  $data
     */
    public function updateDetails(
        User $user,
        array $data,
        ?UploadedFile $avatar = null,
        ?UploadedFile $cover = null,
    ): Profile {
        $profile = $this->getOrCreateProfile($user);

        $newAvatar = $avatar !== null ? $this->imageUploadService->storeImage($avatar, 'avatars') : null;
        $newCover = $cover !== null ? $this->imageUploadService->storeImage($cover, 'covers') : null;

        $oldAvatarDisk = $profile->avatar_disk;
        $oldAvatarPath = $profile->avatar_path;
        $oldCoverDisk = $profile->cover_disk;
        $oldCoverPath = $profile->cover_path;

        $profile->fill([
            'headline' => isset($data['headline']) && trim($data['headline']) !== '' ? trim($data['headline']) : null,
            'bio' => isset($data['bio']) && trim($data['bio']) !== '' ? trim($data['bio']) : null,
            'location' => isset($data['location']) && trim($data['location']) !== '' ? trim($data['location']) : null,
            'website_url' => isset($data['website_url']) && trim($data['website_url']) !== '' ? trim($data['website_url']) : null,
        ]);

        if ($newAvatar !== null) {
            $profile->avatar_disk = $newAvatar['disk'];
            $profile->avatar_path = $newAvatar['path'];
        }

        if ($newCover !== null) {
            $profile->cover_disk = $newCover['disk'];
            $profile->cover_path = $newCover['path'];
        }

        $profile->save();

        if ($newAvatar !== null) {
            $this->imageUploadService->deleteIfExists($oldAvatarDisk, $oldAvatarPath);
        }

        if ($newCover !== null) {
            $this->imageUploadService->deleteIfExists($oldCoverDisk, $oldCoverPath);
        }

        return $profile;
    }

    /**
     * @return array{following: bool, followers_count: int}
     */
    public function toggleFollow(User $follower, User $target): array
    {
        if ($follower->id === $target->id) {
            throw ValidationException::withMessages([
                'user' => [__('You cannot follow your own account.')],
            ]);
        }

        $blocked = UserBlock::query()
            ->where(function ($query) use ($follower, $target): void {
                $query->where('blocker_id', $follower->id)->where('blocked_id', $target->id);
            })
            ->orWhere(function ($query) use ($follower, $target): void {
                $query->where('blocker_id', $target->id)->where('blocked_id', $follower->id);
            })
            ->exists();

        if ($blocked) {
            throw ValidationException::withMessages([
                'user' => [__('Unable to follow this user.')],
            ]);
        }

        $result = DB::transaction(function () use ($follower, $target): array {
            $followerProfile = $this->getOrCreateProfile($follower);
            $targetProfile = $this->getOrCreateProfile($target);

            $existing = Follow::query()
                ->where('follower_id', $follower->id)
                ->where('following_id', $target->id)
                ->first();

            if ($existing !== null) {
                $existing->delete();
                $following = false;
            } else {
                Follow::query()->create([
                    'follower_id' => $follower->id,
                    'following_id' => $target->id,
                ]);
                $following = true;
            }

            $followersCount = Follow::query()->where('following_id', $target->id)->count();
            $followingCount = Follow::query()->where('follower_id', $follower->id)->count();

            $targetProfile->followers_count = $followersCount;
            $targetProfile->save();

            $followerProfile->following_count = $followingCount;
            $followerProfile->save();

            return [
                'following' => $following,
                'followers_count' => $followersCount,
            ];
        });

        if ($result['following']) {
            $this->notificationService->notifyIfAllowed(
                $target,
                $follower,
                new SocialActivityNotification(
                    eventKey: 'user.followed',
                    titleVi: "{$follower->name} đã bắt đầu theo dõi bạn.",
                    titleEn: "{$follower->name} started following you.",
                    url: route('users.show', $follower->username, false),
                    actorName: $follower->name,
                    actorUsername: $follower->username,
                    titleKey: ':name started following you.',
                    titleParams: ['name' => $follower->name],
                ),
            );
        }

        return $result;
    }

    /**
     * @return array{blocked: bool}
     */
    public function toggleBlock(User $blocker, User $target): array
    {
        if ($blocker->id === $target->id) {
            throw ValidationException::withMessages([
                'user' => [__('You cannot block your own account.')],
            ]);
        }

        return DB::transaction(function () use ($blocker, $target): array {
            $existing = UserBlock::query()
                ->where('blocker_id', $blocker->id)
                ->where('blocked_id', $target->id)
                ->first();

            if ($existing !== null) {
                $existing->delete();

                return ['blocked' => false];
            }

            UserBlock::query()->create([
                'blocker_id' => $blocker->id,
                'blocked_id' => $target->id,
            ]);

            Follow::query()
                ->where(function ($query) use ($blocker, $target): void {
                    $query->where('follower_id', $blocker->id)->where('following_id', $target->id);
                })
                ->orWhere(function ($query) use ($blocker, $target): void {
                    $query->where('follower_id', $target->id)->where('following_id', $blocker->id);
                })
                ->delete();

            $blockerProfile = $this->getOrCreateProfile($blocker);
            $targetProfile = $this->getOrCreateProfile($target);

            $blockerProfile->followers_count = Follow::query()->where('following_id', $blocker->id)->count();
            $blockerProfile->following_count = Follow::query()->where('follower_id', $blocker->id)->count();
            $blockerProfile->save();

            $targetProfile->followers_count = Follow::query()->where('following_id', $target->id)->count();
            $targetProfile->following_count = Follow::query()->where('follower_id', $target->id)->count();
            $targetProfile->save();

            return ['blocked' => true];
        });
    }
}
