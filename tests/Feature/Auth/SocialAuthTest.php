<?php

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Social\ProfileService;
use App\Services\System\LanguageService;
use App\Services\System\LocalizationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.google.client_id', 'test-google-client-id');
    config()->set('services.google.client_secret', 'test-google-client-secret');
});

/**
 * Create a mocked Socialite Google provider returning the given user payload.
 */
function mockGoogleSocialiteUser(
    string $id = 'google-user-123',
    ?string $name = 'Nguyen Van A',
    ?string $email = 'nguyenvana@example.com',
    ?string $avatar = 'https://lh3.googleusercontent.com/a/example-avatar',
): void {
    $socialiteUser = (new SocialiteUser)->map([
        'id' => $id,
        'nickname' => null,
        'name' => $name,
        'email' => $email,
        'avatar' => $avatar,
    ]);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->with('google')
        ->once()
        ->andReturn($provider);
}

test('new user can sign up and sign in via Google OAuth without server error', function (): void {
    mockGoogleSocialiteUser(
        id: 'google-new-001',
        name: 'Minh Anh',
        email: 'Minh.Anh@Example.COM',
        avatar: 'https://lh3.googleusercontent.com/a/minh-anh',
    );

    $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'minh.anh@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::User)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->account_type)->toBe(AccountType::Human)
        ->and($user->isActive())->toBeTrue()
        ->and($user->has_password)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->social_provider)->toBe('google')
        ->and($user->social_provider_id)->toBe('google-new-001')
        ->and($user->avatar_url)->toBe('https://lh3.googleusercontent.com/a/minh-anh');
});

test('existing active email user is merged with Google OAuth identity and signed in', function (): void {
    $existing = User::factory()->create([
        'email' => 'existing@example.com',
        'social_provider' => null,
        'social_provider_id' => null,
        'email_verified_at' => null,
    ]);

    mockGoogleSocialiteUser(
        id: 'google-existing-002',
        name: 'Existing Member',
        email: 'existing@example.com',
        avatar: 'https://lh3.googleusercontent.com/a/existing',
    );

    $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($existing);

    $existing->refresh();

    expect($existing->social_provider)->toBe('google')
        ->and($existing->social_provider_id)->toBe('google-existing-002')
        ->and($existing->email_verified_at)->not->toBeNull();
});

test('soft-deleted or suspended user cannot sign in via Google OAuth and redirects cleanly', function (): void {
    $deletedUser = User::factory()->create([
        'email' => 'deleted@example.com',
    ]);
    $deletedUser->delete();

    mockGoogleSocialiteUser(
        id: 'google-deleted-003',
        name: 'Deleted User',
        email: 'deleted@example.com',
    );

    $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $suspendedUser = User::factory()->create([
        'email' => 'suspended@example.com',
        'status' => UserStatus::Suspended,
        'suspended_until' => now()->addDays(7),
    ]);

    mockGoogleSocialiteUser(
        id: 'google-suspended-004',
        name: 'Suspended User',
        email: $suspendedUser->email,
    );

    $response2 = $this->get(route('auth.social.callback', ['provider' => 'google']));

    $response2->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('Google OAuth handles oversized avatar URLs, empty emails, and provider exceptions cleanly', function (): void {
    $oversizedAvatar = 'https://lh3.googleusercontent.com/'.str_repeat('a', 320);

    mockGoogleSocialiteUser(
        id: 'google-avatar-005',
        name: 'Long Avatar User',
        email: 'longavatar@example.com',
        avatar: $oversizedAvatar,
    );

    $this->get(route('auth.social.callback', ['provider' => 'google']))
        ->assertRedirect(route('dashboard'));

    $created = User::query()->where('email', 'longavatar@example.com')->first();
    expect($created)->not->toBeNull()
        ->and($created->avatar_url)->toBeNull();

    $this->post(route('logout'));

    // Empty email from provider
    mockGoogleSocialiteUser(
        id: 'google-empty-006',
        name: 'No Email',
        email: '',
    );

    $this->get(route('auth.social.callback', ['provider' => 'google']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    // Provider throws exception (e.g., invalid state or expired code)
    $failingProvider = Mockery::mock(Provider::class);
    $failingProvider->shouldReceive('user')->once()->andThrow(new RuntimeException('Invalid OAuth state'));

    Socialite::shouldReceive('driver')
        ->with('google')
        ->once()
        ->andReturn($failingProvider);

    $this->get(route('auth.social.callback', ['provider' => 'google']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('Google OAuth enforces two-factor challenge for users with 2FA enabled', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create([
        'email' => 'twofactor@example.com',
    ]);

    mockGoogleSocialiteUser(
        id: 'google-2fa-007',
        name: $user->name,
        email: $user->email,
    );

    $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

    $response->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('Google OAuth redirect falls back cleanly to login when credentials are missing', function (): void {
    config()->set('services.google.client_id', '');
    config()->set('services.google.client_secret', '');

    $this->get(route('auth.social.redirect', ['provider' => 'google']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
});

test('all 15 frontend locale JSON packs have zero unescaped vue-i18n @ characters', function (): void {
    $auditService = app(LocalizationAuditService::class);
    $report = $auditService->audit(includeSourceScan: false);

    expect($report['summary']['total_placeholder_errors'])->toBe(0);

    foreach (LanguageService::allCodes() as $code) {
        $path = resource_path("js/i18n/locales/{$code}.json");
        $raw = (string) file_get_contents($path);
        $stripped = str_replace(["{'@'}", '{"@"}'], '', $raw);

        expect(str_contains($stripped, '@'))->toBeFalse(
            "Locale {$code}.json contains an unescaped '@' character which breaks vue-i18n v11 compilation."
        );
    }
});

test('Google avatar syncs automatically to public profile, feed, and social lists until replaced or removed', function (): void {
    $googleAvatar = 'https://lh3.googleusercontent.com/a/default-sync-photo';

    mockGoogleSocialiteUser(
        id: 'google-avatar-sync-008',
        name: 'Avatar Sync Member',
        email: 'avatarsync@example.com',
        avatar: $googleAvatar,
    );

    $this->get(route('auth.social.callback', ['provider' => 'google']))
        ->assertRedirect(route('dashboard'));

    /** @var User $user */
    $user = User::query()->where('email', 'avatarsync@example.com')->firstOrFail();

    // Public profile payload shows Google avatar immediately without requiring custom upload
    $payload = app(ProfileService::class)->profilePayload($user, $user);
    expect($payload['profile_user']['avatar'])->toBe($googleAvatar);

    // Explicitly removing avatar clears Google avatar fallback cleanly
    app(ProfileService::class)->updateDetails($user, ['remove_avatar' => true]);
    $user->refresh();
    $payloadAfterRemove = app(ProfileService::class)->profilePayload($user, $user);
    expect($payloadAfterRemove['profile_user']['avatar'])->toBeNull();
});

test('mail:test command warns when mailer is log and succeeds with smtp transport', function (): void {
    config()->set('mail.default', 'log');

    $this->artisan('mail:test', ['email' => 'check@example.com'])
        ->assertFailed();

    config()->set('mail.default', 'array');

    $this->artisan('mail:test', ['email' => 'check@example.com'])
        ->assertFailed();
});
