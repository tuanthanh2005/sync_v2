<?php

namespace App\Policies;

use App\Models\PolicyPage;
use App\Models\User;

class PolicyPagePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive() && $actor->isAdmin() && $actor->hasEnabledTwoFactorAuthentication();
    }

    public function view(?User $actor, PolicyPage $policyPage): bool
    {
        if ($policyPage->isPubliclyAccessible()) {
            return true;
        }

        return $actor !== null
            && $actor->isActive()
            && $actor->isAdmin()
            && $actor->hasEnabledTwoFactorAuthentication();
    }

    public function create(User $actor): bool
    {
        return $actor->isActive() && $actor->isAdmin() && $actor->hasEnabledTwoFactorAuthentication();
    }

    public function update(User $actor, PolicyPage $policyPage): bool
    {
        return $actor->isActive() && $actor->isAdmin() && $actor->hasEnabledTwoFactorAuthentication();
    }

    public function delete(User $actor, PolicyPage $policyPage): bool
    {
        return $actor->isActive() && $actor->isAdmin() && $actor->hasEnabledTwoFactorAuthentication();
    }
}
