<?php

namespace App\Policies;

use App\Enums\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;

class CommunityPolicy
{
    public function view(?User $user, Community $community): bool
    {
        if ($community->status !== 'active') {
            return $user !== null && $user->isStaff();
        }

        if ($community->visibility === 'public') {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($user->isStaff() || $community->owner_id === $user->id) {
            return true;
        }

        return CommunityMember::query()
            ->where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail();
    }

    public function update(User $user, Community $community): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $community->owner_id === $user->id || $user->isAdmin();
    }

    public function moderate(User $user, Community $community): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($community->owner_id === $user->id || $user->isStaff()) {
            return true;
        }

        return CommunityMember::query()
            ->where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->whereIn('role', [CommunityRole::Owner->value, CommunityRole::Moderator->value])
            ->exists();
    }
}
