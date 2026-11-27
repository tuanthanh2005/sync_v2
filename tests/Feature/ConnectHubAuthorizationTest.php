<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\AuthOtpVerificationNotification;
use App\Policies\AccountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests can view the public home feed without internal account telemetry', function (): void {
    User::factory()->count(2)->create(['status' => UserStatus::Active]);
    User::factory()->suspended()->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->missing('summary')
            ->has('posts', 0),
        );
});

test('authenticated verified active users can visit dashboard without internal summary metrics', function (): void {
    $user = User::factory()->create([
        'username' => 'minh_anh',
        'timezone' => 'Asia/Ho_Chi_Minh',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->missing('summary')
            ->where('auth.user.username', 'minh_anh')
            ->where('auth.user.role', 'user')
            ->where('accountChecklist.emailVerified', true)
            ->where('accountChecklist.twoFactorEnabled', false)
            ->where('accountChecklist.usernameConfigured', true)
            ->where('accountChecklist.timezoneConfigured', true),
        );
});

test('standard users are forbidden from accessing admin overview', function (): void {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('admin.overview'))
        ->assertForbidden();
});

test('admins without two-factor authentication are redirected to security settings', function (): void {
    $adminWithout2fa = User::factory()->admin()->create();

    $this->actingAs($adminWithout2fa)
        ->get(route('admin.overview'))
        ->assertRedirect(route('security.edit'));
});

test('moderators and 2fa-enabled admins can access admin overview and receive technical metrics', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $moderator = User::factory()->moderator()->create();
    User::factory()->suspended()->create();

    $this->actingAs($moderator)
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Overview')
            ->where('metrics.total_users', 3)
            ->where('metrics.verified_users', 3)
            ->where('metrics.staff_users', 2)
            ->where('metrics.suspended_users', 1)
            ->where('platform_status.hashing_driver', 'argon2id')
            ->where('platform_status.admin_two_factor_enforced', true)
            ->has('recent_users', 3),
        );

    $this->actingAs($admin)
        ->get(route('admin.overview'))
        ->assertOk();
});

test('suspended users cannot authenticate on login', function (): void {
    $user = User::factory()->suspended()->create();

    $response = $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('banned users are logged out when visiting protected routes', function (): void {
    $user = User::factory()->banned()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('registration generates unique lowercase username and enforces server-side default role and status', function (): void {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Nguyễn Văn Hùng',
        'email' => 'hung@example.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
        'role' => 'admin',
        'status' => 'banned',
    ])->assertRedirect(route('verification.notice', absolute: false));

    expect(User::query()->where('email', 'hung@example.com')->exists())->toBeFalse();

    $otp = null;
    Notification::assertSentOnDemand(
        AuthOtpVerificationNotification::class,
        function (AuthOtpVerificationNotification $notification, array $channels, object $notifiable) use (&$otp): bool {
            $mail = $notification->toMail($notifiable);
            foreach ($mail->introLines as $line) {
                if (is_string($line) && preg_match('/\*\*(\d{6})\*\*/', $line, $m) === 1) {
                    $otp = $m[1];
                }
            }

            return true;
        },
    );

    expect($otp)->toMatch('/^\d{6}$/');

    $this->post(route('auth.verify.otp'), ['code' => $otp])
        ->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'hung@example.com')->firstOrFail();

    expect($user->username)->toBe('nguyen_van_hung')
        ->and($user->role)->toBe(UserRole::User)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->timezone)->toBe('Asia/Ho_Chi_Minh')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('profile update validates username format and saves timezone', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => 'Updated User',
            'username' => 'Invalid Handle!',
            'email' => $user->email,
            'timezone' => 'Asia/Ho_Chi_Minh',
        ])
        ->assertSessionHasErrors('username');

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Updated User',
            'username' => 'Nguyen_Dev_99',
            'email' => $user->email,
            'timezone' => 'Asia/Singapore',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->username)->toBe('nguyen_dev_99')
        ->and($user->timezone)->toBe('Asia/Singapore');
});

test('AccountPolicy enforces role and ownership boundaries', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $moderator = User::factory()->moderator()->create();
    $member = User::factory()->create();
    $otherMember = User::factory()->create();

    $policy = new AccountPolicy;

    expect($policy->accessAdmin($admin))->toBeTrue()
        ->and($policy->accessAdmin($moderator))->toBeTrue()
        ->and($policy->accessAdmin($member))->toBeFalse()
        ->and($policy->manageRoles($admin, $member))->toBeTrue()
        ->and($policy->manageRoles($admin, $admin))->toBeFalse()
        ->and($policy->manageRoles($moderator, $member))->toBeFalse()
        ->and($policy->update($member, $member))->toBeTrue()
        ->and($policy->update($member, $otherMember))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('access-admin'))->toBeTrue()
        ->and(Gate::forUser($member)->allows('access-admin'))->toBeFalse();
});
