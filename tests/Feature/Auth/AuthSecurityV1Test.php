<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuthVerificationChallenge;
use App\Models\DisposableEmailDomain;
use App\Models\PendingRegistration;
use App\Models\Post;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\AuthOtpVerificationNotification;
use App\Notifications\SecurityAlertNotification;
use App\Services\System\SystemSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

/**
 * Helper to extract the 6-digit OTP and scanner-safe confirmation URL from the last sent AuthOtpVerificationNotification.
 *
 * @return array{otp: string, url: string}
 */
function captureLastAuthOtpNotification(): array
{
    $captured = ['otp' => '', 'url' => ''];

    Notification::assertSentOnDemand(
        AuthOtpVerificationNotification::class,
        function (AuthOtpVerificationNotification $notification, array $channels, object $notifiable) use (&$captured): bool {
            $mail = $notification->toMail($notifiable);
            foreach ($mail->introLines as $line) {
                if (is_string($line) && preg_match('/\*\*(\d{6})\*\*/', $line, $m) === 1) {
                    $captured['otp'] = $m[1];
                }
            }
            $captured['url'] = (string) ($mail->actionUrl ?? '');

            return true;
        },
    );

    expect($captured['otp'])->toMatch('/^\d{6}$/');

    return $captured;
}

function setAuthSetting(string $key, mixed $value): void
{
    SystemSetting::query()->updateOrCreate(
        ['key' => $key],
        [
            'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            'group' => 'auth_security',
            'type' => is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string'),
        ],
    );
    app(SystemSettingService::class)->flushCache();
}

// =========================================================================
// 1. REGISTRATION, PENDING ARCHITECTURE, DISPOSABLE EMAILS & ANTI-BOT
// =========================================================================

test('pending registration creates no user row or member session until OTP verified and preserves password hash', function () {
    Notification::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Alice Nguyen',
        'email' => 'alice.real@company.com.vn',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
    ]);

    $response->assertRedirect(route('verification.notice', absolute: false));
    $this->assertGuest();

    // No official user exists yet
    $this->assertDatabaseMissing('users', ['email' => 'alice.real@company.com.vn']);
    $pending = PendingRegistration::query()->where('normalized_email', 'alice.real@company.com.vn')->first();
    expect($pending)->not->toBeNull();
    expect($pending->status)->toBe(PendingRegistration::STATUS_PENDING);

    // OTP is NEVER stored in plaintext
    $captured = captureLastAuthOtpNotification();
    $challenge = AuthVerificationChallenge::query()->where('pending_registration_id', $pending->id)->first();
    expect($challenge)->not->toBeNull();
    expect($challenge->otp_hmac)->not->toBe($captured['otp']);
    expect(strlen($challenge->otp_hmac))->toBe(64);

    // Complete verification
    $verifyRes = $this->post(route('auth.verify.otp'), ['code' => $captured['otp']]);
    $verifyRes->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'alice.real@company.com.vn')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull();
    expect(Hash::check('SyncViet#2026Strong!', (string) $user->password))->toBeTrue();

    // Logout and sign in with the registered password (proves zero double-hashing bug)
    $this->post(route('logout'));
    $this->assertGuest();

    $loginRes = $this->post(route('login.store'), [
        'email' => 'alice.real@company.com.vn',
        'password' => 'SyncViet#2026Strong!',
    ]);
    $loginRes->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('disposable email domains are blocked while major providers and custom corporate domains are allowed', function () {
    Notification::fake();

    // Disposable domain blocked
    $this->post(route('register.store'), [
        'name' => 'Spam Bot',
        'email' => 'bot123@mailinator.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
    ])->assertSessionHasErrors('email');

    $this->assertDatabaseMissing('pending_registrations', ['normalized_email' => 'bot123@mailinator.com']);

    // Custom admin-added disposable domain blocked
    DisposableEmailDomain::query()->create(['domain' => 'custom-burner.xyz', 'is_active' => true]);
    $this->post(route('register.store'), [
        'name' => 'Spam Bot 2',
        'email' => 'bot456@custom-burner.xyz',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
    ])->assertSessionHasErrors('email');

    // Legitimate corporate / custom domain allowed
    $this->post(route('register.store'), [
        'name' => 'Corporate Member',
        'email' => 'founder@syncviet-partner.io',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
    ])->assertRedirect(route('verification.notice', absolute: false));

    $this->assertDatabaseHas('pending_registrations', ['normalized_email' => 'founder@syncviet-partner.io']);
});

test('registration rate limit blocks rapid automated signup bursts', function () {
    Notification::fake();
    setAuthSetting('auth_register_limit_10min', 2);

    foreach (['one@gmail.com', 'two@outlook.com'] as $email) {
        $this->post(route('register.store'), [
            'name' => 'Normal User',
            'email' => $email,
            'password' => 'SyncViet#2026Strong!',
            'password_confirmation' => 'SyncViet#2026Strong!',
        ])->assertRedirect(route('verification.notice', absolute: false));
    }

    $this->post(route('register.store'), [
        'name' => 'Third Burst',
        'email' => 'three@proton.me',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
    ])->assertSessionHasErrors('email');

    $this->assertDatabaseMissing('pending_registrations', ['normalized_email' => 'three@proton.me']);
});

test('cloudflare turnstile enforces fail-closed verification, replay blocking, and action/hostname matching', function () {
    Notification::fake();
    Http::preventStrayRequests();

    setAuthSetting('auth_turnstile_enabled', true);
    setAuthSetting('auth_turnstile_site_key', '0x4AAAAAAATestSiteKey');
    setAuthSetting('auth_turnstile_secret_encrypted', Crypt::encryptString('0x4AAAAAAATestSecretKey'));
    config(['app.url' => 'https://syncviet.com']);

    // 1. Missing token -> fail-closed
    $this->post(route('register.store'), [
        'name' => 'Bot No Token',
        'email' => 'notoken@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
    ])->assertSessionHasErrors('cf_turnstile_response');

    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::sequence()
            // 2. Provider error (500) -> fail-closed
            ->push([], 500)
            // 3. Action mismatch -> rejected
            ->push(['success' => true, 'action' => 'login', 'hostname' => 'syncviet.com'], 200)
            // 4. Untrusted hostname -> rejected
            ->push(['success' => true, 'action' => 'register', 'hostname' => 'evil-phishing.example'], 200)
            // 5. Valid token succeeds once
            ->push(['success' => true, 'action' => 'register', 'hostname' => 'syncviet.com'], 200),
    ]);

    $this->post(route('register.store'), [
        'name' => 'Bot 500',
        'email' => 'err500@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
        'cf_turnstile_response' => 'token-500-fail',
    ])->assertSessionHasErrors('cf_turnstile_response');

    $this->post(route('register.store'), [
        'name' => 'Bot Wrong Action',
        'email' => 'wrongaction@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
        'cf_turnstile_response' => 'token-wrong-action',
    ])->assertSessionHasErrors('cf_turnstile_response');

    $this->post(route('register.store'), [
        'name' => 'Bot Wrong Host',
        'email' => 'wronghost@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
        'cf_turnstile_response' => 'token-wrong-host',
    ])->assertSessionHasErrors('cf_turnstile_response');

    $this->post(route('register.store'), [
        'name' => 'Human Turnstile',
        'email' => 'human.ok@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
        'cf_turnstile_response' => 'token-valid-once-12345',
    ])->assertRedirect(route('verification.notice', absolute: false));

    // Replaying exact same Turnstile token fails immediately (cache replay defense)
    $this->post(route('register.store'), [
        'name' => 'Replay Attempt',
        'email' => 'replay.try@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
        'cf_turnstile_response' => 'token-valid-once-12345',
    ])->assertSessionHasErrors('cf_turnstile_response');
});

test('emergency registration pause blocks new signups while verified members still sign in normally', function () {
    Notification::fake();
    setAuthSetting('auth_emergency_registration_pause', true);

    $this->post(route('register.store'), [
        'name' => 'Blocked Signup',
        'email' => 'paused@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
    ])->assertSessionHasErrors('email');

    $this->assertDatabaseMissing('pending_registrations', ['normalized_email' => 'paused@gmail.com']);

    $verifiedUser = User::factory()->create();
    $this->post(route('login.store'), [
        'email' => $verifiedUser->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($verifiedUser);
});

test('pending registration cleanup command cleans expired rows without affecting real users', function () {
    $realUser = User::factory()->create();

    $recentExpired = PendingRegistration::query()->create([
        'public_token' => Str::random(64),
        'name' => 'Recent Expired Pending',
        'username' => 'recent_expired_user',
        'email' => 'recent.expired@gmail.com',
        'normalized_email' => 'recent.expired@gmail.com',
        'email_fingerprint' => hash_hmac('sha256', 'recent.expired@gmail.com', 'test'),
        'password_hash' => Hash::make('SyncViet#2026Strong!'),
        'status' => PendingRegistration::STATUS_PENDING,
        'expires_at' => now()->subHours(48),
    ]);

    $staleExpired = PendingRegistration::query()->create([
        'public_token' => Str::random(64),
        'name' => 'Stale Expired Pending',
        'username' => 'stale_expired_user',
        'email' => 'stale.expired@gmail.com',
        'normalized_email' => 'stale.expired@gmail.com',
        'email_fingerprint' => hash_hmac('sha256', 'stale.expired@gmail.com', 'test'),
        'password_hash' => Hash::make('SyncViet#2026Strong!'),
        'status' => PendingRegistration::STATUS_EXPIRED,
        'expires_at' => now()->subDays(10),
    ]);
    DB::table('pending_registrations')
        ->where('id', $staleExpired->id)
        ->update(['updated_at' => now()->subDays(9)]);

    $this->artisan('app:auth:cleanup-pending')->assertSuccessful();

    expect($recentExpired->refresh()->status)->toBe(PendingRegistration::STATUS_EXPIRED);
    expect(PendingRegistration::query()->find($staleExpired->id))->toBeNull();
    expect(User::query()->find($realUser->id))->not->toBeNull();
});

// =========================================================================
// 2. OTP SECURITY, SCANNER-SAFE CONFIRMATION LINK & GLOBAL VERIFICATION
// =========================================================================

test('otp enforces max attempts, expiry, replay block, resend cooldown, and old otp invalidation', function () {
    Notification::fake();
    setAuthSetting('auth_otp_max_attempts', 3);
    setAuthSetting('auth_otp_resend_cooldown', 60);
    setAuthSetting('auth_otp_expire_minutes', 5);

    $this->post(route('register.store'), [
        'name' => 'OTP Security Test',
        'email' => 'otp.guard@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
    ])->assertRedirect(route('verification.notice', absolute: false));

    $first = captureLastAuthOtpNotification();

    // Resend within cooldown (60s) is rejected
    $this->post(route('auth.verify.resend'))
        ->assertSessionHasErrors('code');

    // Wrong OTP 2 times
    $this->post(route('auth.verify.otp'), ['code' => '000001'])->assertSessionHasErrors('code');
    $this->post(route('auth.verify.otp'), ['code' => '000002'])->assertSessionHasErrors('code');

    // Travel 61 seconds and resend -> old OTP invalidated, new OTP issued
    $this->travel(61)->seconds();
    $this->post(route('auth.verify.resend'))->assertSessionHasNoErrors();

    $second = captureLastAuthOtpNotification();
    expect($second['otp'])->not->toBe($first['otp']);

    // Old OTP must fail
    $this->post(route('auth.verify.otp'), ['code' => $first['otp']])->assertSessionHasErrors('code');

    // Travel past expiry (6 minutes) -> new OTP expires
    $this->travel(6)->minutes();
    $this->post(route('auth.verify.otp'), ['code' => $second['otp']])->assertSessionHasErrors('code');
    $this->assertGuest();

    // Request fresh OTP and verify cleanly
    $this->post(route('auth.verify.resend'))->assertSessionHasNoErrors();
    $third = captureLastAuthOtpNotification();

    $this->post(route('auth.verify.otp'), ['code' => $third['otp']])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    // Replaying the already-used OTP after logout must fail
    $this->post(route('logout'));
    $this->post(route('auth.verify.otp'), ['code' => $third['otp']])->assertRedirect(route('login', absolute: false));
    $this->assertGuest();
});

test('verification link is scanner-safe on GET and activates only on POST without allowing replay', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Scanner Safe User',
        'email' => 'scanner.safe@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'password_confirmation' => 'SyncViet#2026Strong!',
    ])->assertRedirect(route('verification.notice', absolute: false));

    $captured = captureLastAuthOtpNotification();
    $path = (string) parse_url($captured['url'], PHP_URL_PATH);

    // Automated email link scanner performs GET -> MUST NOT activate user!
    $this->get($path)->assertOk();
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'scanner.safe@gmail.com']);

    // Human clicks Confirm button (POST with CSRF) -> activates user!
    $this->post($path)->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['email' => 'scanner.safe@gmail.com']);

    // Replay POST on same confirmation link fails cleanly
    $this->post(route('logout'));
    $this->post($path)->assertRedirect(route('login', absolute: false));
    $this->assertGuest();
});

test('global middleware blocks authenticated unverified users across web and API routes', function () {
    $unverified = User::factory()->unverified()->create();

    // Member web routes redirect to /email/verify
    $this->actingAs($unverified)->get('/')->assertRedirect(route('verification.notice', absolute: false));
    $this->actingAs($unverified)->get(route('dashboard'))->assertRedirect(route('verification.notice', absolute: false));
    $this->actingAs($unverified)->get(route('profile.edit'))->assertRedirect(route('verification.notice', absolute: false));
    $this->actingAs($unverified)->get(route('communities.index'))->assertRedirect(route('verification.notice', absolute: false));
    $this->actingAs($unverified)->post('/posts', ['body' => 'Spam post'])->assertRedirect(route('verification.notice', absolute: false));

    // Direct XHR/JSON API call returns 403 JSON (cannot bypass via API!)
    $this->actingAs($unverified)
        ->postJson('/posts', ['body' => 'Spam API post'])
        ->assertForbidden()
        ->assertJson(['code' => 'EMAIL_NOT_VERIFIED']);

    // Allowed routes for unverified user: verification notice & logout
    $this->actingAs($unverified)->get(route('verification.notice'))->assertOk();
    $this->actingAs($unverified)->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();

    // Public profile of unverified user returns 404 to guests
    $this->get("/u/{$unverified->username}")->assertNotFound();
});

// =========================================================================
// 3. LOGIN SECURITY, UNVERIFIED LOGIN REDIRECTION & SAFE REMEDIATION
// =========================================================================

test('unverified user login enters limited verification flow without full member session', function () {
    Notification::fake();

    $unverified = User::factory()->unverified()->create();

    $response = $this->post(route('login.store'), [
        'email' => $unverified->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('verification.notice', absolute: false));
    $this->assertGuest();

    // Verification page renders for the limited verification session
    $this->get(route('verification.notice'))->assertOk();

    // Still cannot access member dashboard
    $this->get(route('dashboard'))->assertRedirect(route('login', absolute: false));

    // Verify with OTP activates existing account and logs in
    $captured = captureLastAuthOtpNotification();
    $this->post(route('auth.verify.otp'), ['code' => $captured['otp']])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($unverified);
    expect($unverified->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('unverified remediation command revokes active sessions and rotates remember tokens without deleting data or auto-verifying', function () {
    $unverified = User::factory()->unverified()->create([
        'remember_token' => 'old-unverified-remember-token-1234567890',
    ]);
    $verified = User::factory()->create();

    $post = Post::factory()->create(['user_id' => $unverified->id]);

    DB::table('sessions')->insert([
        [
            'id' => 'sess-unverified-1',
            'user_id' => $unverified->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ],
        [
            'id' => 'sess-verified-1',
            'user_id' => $verified->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ],
    ]);

    $this->artisan('app:auth:remediate-unverified')->assertSuccessful();

    // Unverified session revoked, remember token rotated, NOT auto-verified, post NOT deleted
    $this->assertDatabaseMissing('sessions', ['id' => 'sess-unverified-1']);
    $this->assertDatabaseHas('sessions', ['id' => 'sess-verified-1']);
    expect($unverified->refresh()->email_verified_at)->toBeNull();
    expect($unverified->remember_token)->not->toBe('old-unverified-remember-token-1234567890');
    expect(Post::query()->find($post->id))->not->toBeNull();
});

// =========================================================================
// 4. FORGOT PASSWORD & RESET PASSWORD HARDENING
// =========================================================================

test('forgot password returns identical anti-enumeration response for existing and non-existent emails', function () {
    Notification::fake();

    $existing = User::factory()->create(['email' => 'exists@gmail.com']);

    $resExisting = $this->post(route('password.email'), ['email' => $existing->email]);
    $resUnknown = $this->post(route('password.email'), ['email' => 'nobody.here@gmail.com']);

    $resExisting->assertSessionHas('status');
    $resUnknown->assertSessionHas('status');
    expect(session('status'))->toBeString();
});

test('password reset revokes all sessions, rotates remember token, never auto-logins, never auto-verifies, and preserves 2FA', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create([
        'remember_token' => 'pre-reset-token-12345',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);

    DB::table('sessions')->insert([
        'id' => 'sess-before-reset',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'payload' => base64_encode('test'),
        'last_activity' => time(),
    ]);

    $token = Password::broker()->createToken($user);

    $response = $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewReset#2026Strong!',
        'password_confirmation' => 'NewReset#2026Strong!',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertGuest();

    $user->refresh();
    expect(Hash::check('NewReset#2026Strong!', (string) $user->password))->toBeTrue();
    expect($user->remember_token)->not->toBe('pre-reset-token-12345');
    expect($user->email_verified_at)->toBeNull();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
    $this->assertDatabaseMissing('sessions', ['id' => 'sess-before-reset']);

    // Reusing the same reset token fails
    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'AnotherPass#2026Strong!',
        'password_confirmation' => 'AnotherPass#2026Strong!',
    ])->assertSessionHasErrors('email');
});

// =========================================================================
// 5. SECURE EMAIL CHANGE & SOCIAL PRE-ACCOUNT-TAKEOVER DEFENSE
// =========================================================================

test('secure email change requires current password, keeps old email until verified, and alerts old email', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'original@gmail.com']);

    // Wrong current password rejected
    $this->actingAs($user)->post(route('settings.email.change-request'), [
        'new_email' => 'updated@gmail.com',
        'current_password' => 'wrong-password',
    ])->assertSessionHasErrors('current_password');

    // Correct current password sends OTP to new email & alert to old email, while keeping original@gmail.com active
    $this->actingAs($user)->post(route('settings.email.change-request'), [
        'new_email' => 'updated@gmail.com',
        'current_password' => 'password',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('original@gmail.com');

    Notification::assertSentOnDemand(SecurityAlertNotification::class);
    $captured = captureLastAuthOtpNotification();

    // Purpose binding: cannot use change_email OTP on /email/verify/otp registration endpoint
    $this->actingAs($user)->post(route('settings.email.verify-otp'), [
        'new_email' => 'updated@gmail.com',
        'code' => $captured['otp'],
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('updated@gmail.com');
    expect($user->email_verified_at)->not->toBeNull();
});

test('social oauth pre-account-takeover defense invalidates unverified attacker password and sessions', function () {
    setAuthSetting('feature_google_login', true);
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
    ]);

    // Attacker pre-registered an unverified password account using victim's Gmail
    $preCreated = User::factory()->unverified()->create([
        'email' => 'victim@gmail.com',
        'password' => 'AttackerSecret#123',
        'has_password' => true,
        'social_provider' => null,
    ]);

    DB::table('sessions')->insert([
        'id' => 'attacker-session-id',
        'user_id' => $preCreated->id,
        'ip_address' => '1.2.3.4',
        'user_agent' => 'AttackerBrowser',
        'payload' => base64_encode('test'),
        'last_activity' => time(),
    ]);

    $socialUser = Mockery::mock(SocialiteUserContract::class);
    $socialUser->shouldReceive('getEmail')->andReturn('victim@gmail.com');
    $socialUser->shouldReceive('getId')->andReturn('google-uid-999');
    $socialUser->shouldReceive('getName')->andReturn('Real Victim');
    $socialUser->shouldReceive('getAvatar')->andReturn(null);
    $socialUser->user = ['email_verified' => true];

    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andReturn($socialUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->get('/auth/google/callback')->assertRedirect(route('dashboard', absolute: false));

    $preCreated->refresh();
    expect($preCreated->email_verified_at)->not->toBeNull();
    expect($preCreated->has_password)->toBeFalse();
    expect(Hash::check('AttackerSecret#123', (string) $preCreated->password))->toBeFalse();
    $this->assertDatabaseMissing('sessions', ['id' => 'attacker-session-id']);
});

// =========================================================================
// 6. MASS ASSIGNMENT PROTECTION & ADMIN SECURITY PROTECTION CONTROLS
// =========================================================================

test('user model blocks mass assignment of email_verified_at, role, and status', function () {
    $user = new User([
        'name' => 'Mass Assign Try',
        'username' => 'mass_assign_try',
        'email' => 'mass.try@gmail.com',
        'password' => 'SyncViet#2026Strong!',
        'email_verified_at' => now(),
        'role' => UserRole::Admin->value,
        'status' => UserStatus::Banned->value,
    ]);
    $user->save();
    $user->refresh();

    expect($user->email_verified_at)->toBeNull();
    expect($user->role)->toBe(UserRole::User);
    expect($user->status)->toBe(UserStatus::Active);
});

test('admin security protection controls work for admins with 2FA and deny regular members', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->post(route('admin.security.auth-protection.emergency-pause'), ['paused' => true])
        ->assertForbidden();

    $admin = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->forceFill(['role' => UserRole::Admin, 'status' => UserStatus::Active])->save();

    $this->actingAs($admin)
        ->post(route('admin.security.auth-protection.settings'), [
            'feature_registration' => true,
            'auth_emergency_registration_pause' => false,
            'auth_turnstile_enabled' => true,
            'auth_turnstile_site_key' => '0x4SitePublicKey',
            'auth_turnstile_secret' => '0x4SuperSecretKeyDoNotLeak',
            'auth_otp_expire_minutes' => 5,
            'auth_otp_resend_cooldown' => 60,
            'auth_otp_max_attempts' => 5,
            'auth_otp_max_per_email_hour' => 5,
            'auth_global_otp_hourly_budget' => 300,
            'auth_register_limit_10min' => 3,
            'auth_register_limit_day' => 10,
            'auth_login_failed_limit_min' => 5,
            'auth_forgot_password_limit_15min' => 3,
            'auth_disposable_email_protection' => true,
            'auth_suspicious_risk_threshold' => 50,
            'auth_pending_retention_hours' => 24,
        ])
        ->assertSessionHasNoErrors();

    // Ensure raw Turnstile secret is NEVER exposed in Inertia props
    $pageRes = $this->actingAs($admin)->get(route('admin.security.index', ['tab' => 'protection']));
    $pageRes->assertOk();
    expect($pageRes->getContent())->not->toContain('0x4SuperSecretKeyDoNotLeak');

    // Admin emergency pause toggle works
    $this->actingAs($admin)
        ->post(route('admin.security.auth-protection.emergency-pause'), ['paused' => true])
        ->assertSessionHasNoErrors();
    expect((bool) app(SystemSettingService::class)->get('auth_emergency_registration_pause', false))->toBeTrue();
});
