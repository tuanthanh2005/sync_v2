<?php

namespace App\Policies;

use App\Enums\CommunityRole;
use App\Enums\ModerationStatus;
use App\Enums\PostVisibility;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Post;
use App\Models\User;
use App\Models\UserBlock;

class PostPolicy
{
    public function view(?User $user, Post $post): bool
    {
        if ($user !== null && $user->isStaff()) {
            return true;
        }

        if ($post->moderation_status !== ModerationStatus::Approved) {
            return $user !== null && $post->user_id === $user->id;
        }

        if ($post->visibility === PostVisibility::Private) {
            return $user !== null && $post->user_id === $user->id;
        }

        if ($post->published_at === null) {
            return $user !== null && $post->user_id === $user->id;
        }

        if ($post->community_id !== null) {
            $community = $post->community ?? Community::query()->find($post->community_id);

            if ($community !== null && $community->visibility === 'private') {
                if ($user === null) {
                    return false;
                }

                $isMember = $community->owner_id === $user->id
                    || CommunityMember::query()
                        ->where('community_id', $community->id)
                        ->where('user_id', $user->id)
                        ->exists();

                if (! $isMember) {
                    return false;
                }
            }
        }

        if ($user !== null && $user->id !== $post->user_id) {
            $isBlocked = UserBlock::query()
                ->where(function ($query) use ($user, $post): void {
                    $query->where('blocker_id', $user->id)
                        ->where('blocked_id', $post->user_id);
                })
                ->orWhere(function ($query) use ($user, $post): void {
                    $query->where('blocker_id', $post->user_id)
                        ->where('blocked_id', $user->id);
                })
                ->exists();

            if ($isBlocked) {
                return false;
            }
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail();
    }

    public function update(User $user, Post $post): bool
    {
        return $user->isActive() && $post->user_id === $user->id;
    }

    public function delete(User $user, Post $post): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($post->user_id === $user->id || $user->isStaff()) {
            return true;
        }

        if ($post->community_id !== null) {
            return CommunityMember::query()
                ->where('community_id', $post->community_id)
                ->where('user_id', $user->id)
                ->whereIn('role', [CommunityRole::Owner->value, CommunityRole::Moderator->value])
                ->exists();
        }

        return false;
    }
}
