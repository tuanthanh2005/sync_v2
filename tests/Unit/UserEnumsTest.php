<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;

test('UserRole identifies staff and admin privileges accurately', function (): void {
    expect(UserRole::User->isStaff())->toBeFalse()
        ->and(UserRole::User->isAdmin())->toBeFalse()
        ->and(UserRole::Moderator->isStaff())->toBeTrue()
        ->and(UserRole::Moderator->isAdmin())->toBeFalse()
        ->and(UserRole::Admin->isStaff())->toBeTrue()
        ->and(UserRole::Admin->isAdmin())->toBeTrue()
        ->and(UserRole::values())->toBe(['user', 'moderator', 'admin']);
});

test('UserStatus enforces authentication eligibility accurately', function (): void {
    expect(UserStatus::Active->canAuthenticate())->toBeTrue()
        ->and(UserStatus::Suspended->canAuthenticate())->toBeFalse()
        ->and(UserStatus::Banned->canAuthenticate())->toBeFalse()
        ->and(UserStatus::values())->toBe(['active', 'suspended', 'banned']);
});
