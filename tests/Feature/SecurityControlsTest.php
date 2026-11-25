<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('passwords are hashed with argon2id by default', function (): void {
    $user = User::factory()->create([
        'password' => 'StrongPass@2026',
    ]);

    expect(config('hashing.driver'))->toBe('argon2id')
        ->and(str_starts_with($user->password, '$argon2id$'))->toBeTrue()
        ->and(Hash::check('StrongPass@2026', $user->password))->toBeTrue();
});

test('registration endpoint is rate limited after exceeding threshold', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('register.store'), [
            'name' => "User {$i}",
            'email' => "ratelimit{$i}@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        auth()->logout();
    }

    $this->post(route('register.store'), [
        'name' => 'Blocked User',
        'email' => 'blocked_ratelimit@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertTooManyRequests();
});

test('authenticated user can log out other browser sessions with password confirmation and records audit log', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->delete(route('other-browser-sessions.destroy'), [
            'password' => 'wrong-password',
        ])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->delete(route('other-browser-sessions.destroy'), [
            'password' => 'password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(AuditLog::query()
        ->where('user_id', $user->id)
        ->where('action', 'auth.logout_other_sessions')
        ->exists())->toBeTrue();
});

test('login and failed login record login history and audit log without exposing secrets', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    $failedEntry = LoginHistory::query()->where('event_type', 'failed')->first();
    $successEntry = LoginHistory::query()->where('event_type', 'success')->first();

    expect($failedEntry)->not->toBeNull()
        ->and($successEntry)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'auth.login')->exists())->toBeTrue()
        ->and(json_encode(AuditLog::query()->pluck('metadata')->all()))->not->toContain('wrong-password');
});

test('web responses include mandatory defense-in-depth HTTP security headers', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("frame-ancestors 'self'")
        ->and($csp)->toContain("object-src 'none'");
});

test('users cannot self-escalate role or status through profile update', function (): void {
    $user = User::factory()->create([
        'role' => UserRole::User,
        'status' => UserStatus::Active,
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Safe Name',
            'email' => $user->email,
            'role' => 'admin',
            'status' => 'suspended',
        ])
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->role)->toBe(UserRole::User)
        ->and($user->status)->toBe(UserStatus::Active);
});
