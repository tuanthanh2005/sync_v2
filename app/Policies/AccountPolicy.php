<?php

namespace App\Policies;

use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive() && $actor->hasVerifiedEmail() && $actor->isStaff();
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->id === $target->id || ($actor->isActive() && $actor->hasVerifiedEmail() && $actor->isStaff());
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->isActive() && $actor->hasVerifiedEmail() && ($actor->id === $target->id || $actor->isAdmin());
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->hasVerifiedEmail() && $actor->id === $target->id;
    }

    public function accessModeration(User $actor): bool
    {
        return $actor->isActive() && $actor->hasVerifiedEmail() && $actor->isStaff();
    }

    public function accessAdmin(User $actor): bool
    {
        return $actor->isActive() && $actor->hasVerifiedEmail() && $actor->isStaff();
    }

    public function manageRoles(User $actor, User $target): bool
    {
        return $actor->isActive() && $actor->hasVerifiedEmail() && $actor->isAdmin() && $actor->id !== $target->id;
    }
}
