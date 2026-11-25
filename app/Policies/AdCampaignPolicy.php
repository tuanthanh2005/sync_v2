<?php

namespace App\Policies;

use App\Enums\AdCampaignStatus;
use App\Models\AdCampaign;
use App\Models\User;

class AdCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, AdCampaign $campaign): bool
    {
        return $user->isActive() && ($campaign->user_id === $user->id || $user->isStaff());
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail();
    }

    public function updateStatus(User $user, AdCampaign $campaign): bool
    {
        if (! $user->isActive() || $campaign->user_id !== $user->id) {
            return false;
        }

        return in_array($campaign->status, [
            AdCampaignStatus::PendingReview,
            AdCampaignStatus::Active,
            AdCampaignStatus::Paused,
        ], true);
    }

    public function review(User $user): bool
    {
        return $user->isActive() && $user->isStaff();
    }
}
