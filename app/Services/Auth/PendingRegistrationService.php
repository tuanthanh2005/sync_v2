<?php

namespace App\Services\Auth;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AccountType;
use App\Enums\ActivationMilestone;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuthVerificationChallenge;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Services\Account\UsernameGenerator;
use App\Services\Growth\EarlyMemberAndBadgeService;
use App\Services\Growth\OnboardingAndActivationService;
use App\Services\Growth\ReferralService;
use App\Services\Seo\OrganicGrowthService;
use App\Services\System\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PendingRegistrationService
{
    use PasswordValidationRules, ProfileValidationRules;

    public const SESSION_PENDING_TOKEN = 'auth.pending_registration_token';

    public const SESSION_UNVERIFIED_USER_ID = 'auth.unverified_user_id';

    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly UsernameGenerator $usernameGenerator,
        private readonly TurnstileService $turnstile,
        private readonly AuthRateLimiterService $rateLimiter,
        private readonly DisposableEmailService $disposableEmails,
        private readonly EmailVerificationChallengeService $challenges,
        private readonly AuthSecurityEventService $events,
        private readonly OrganicGrowthService $organicGrowth,
        private readonly ReferralService $referralService,
        private readonly OnboardingAndActivationService $onboardingService,
        private readonly EarlyMemberAndBadgeService $earlyMemberService,
    ) {}

    /**
     * Validate registration payload, enforce anti-bot + rate limit + disposable email checks,
     * create/update a single PendingRegistration (without creating a User row yet), and send OTP.
     *
     * @param  array<string, mixed>  $input
     */
    public function startRegistration(array $input, Request $request): PendingRegistration
    {
        if (
            ! $this->settings->featureEnabled('feature_registration')
            || (bool) $this->settings->get('auth_emergency_registration_pause', false)
        ) {
            $this->events->record('registration_blocked', 'warning', null, is_string($input['email'] ?? null) ? $input['email'] : null, $request, 20, [
                'reason' => 'registration_paused',
            ]);

            throw ValidationException::withMessages([
                'email' => [__('New account registrations are currently paused by the administrator.')],
            ]);
        }

        // Silent honeypot check for naive bots
        if (is_string($input['website_hp'] ?? null) && trim($input['website_hp']) !== '') {
            $this->events->record('registration_blocked', 'high', null, is_string($input['email'] ?? null) ? $input['email'] : null, $request, 85, [
                'reason' => 'honeypot_triggered',
            ]);

            throw ValidationException::withMessages([
                'email' => [__('Unable to complete registration. Please try again.')],
            ]);
        }

        // Enforce Cloudflare Turnstile anti-bot validation before touching DB or Mail
        $turnstileToken = is_string($input['cf_turnstile_response'] ?? null)
            ? $input['cf_turnstile_response']
            : (is_string($input['cf-turnstile-response'] ?? null) ? $input['cf-turnstile-response'] : null);

        $captchaCheck = $this->turnstile->verify(
            $turnstileToken,
            $request,
            'register',
            is_string($input['email'] ?? null) ? $input['email'] : null,
        );

        if (! $captchaCheck['ok']) {
            throw ValidationException::withMessages([
                'cf_turnstile_response' => [$captchaCheck['message'] ?? __('Please complete the security verification challenge and try again.')],
            ]);
        }

        if (isset($input['username']) && is_string($input['username'])) {
            $input['username'] = Str::lower(trim($input['username']));
        }
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = $this->disposableEmails->normalizeEmail($input['email']);
        }

        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $normalizedEmail = $this->disposableEmails->normalizeEmail((string) $validated['email']);

        // Multi-dimensional rate limit (IP burst, IP daily, email burst, global budget)
        $limit = $this->rateLimiter->attemptRegistration($request, $normalizedEmail);
        if (! $limit['allowed']) {
            throw ValidationException::withMessages([
                'email' => [__('Too many registration attempts. Please try again in :seconds seconds.', ['seconds' => $limit['retry_after']])],
            ]);
        }

        // Disposable email domain guard (preserves Gmail, Outlook, Yahoo, Proton, iCloud & corporate domains)
        $isDisposable = $this->disposableEmails->isDisposable($normalizedEmail);
        if ($isDisposable) {
            $this->events->record('registration_blocked', 'warning', null, $normalizedEmail, $request, 70, [
                'reason' => 'disposable_email',
            ]);

            throw ValidationException::withMessages([
                'email' => [__('Temporary or disposable email addresses are not allowed. Please use a permanent email address.')],
            ]);
        }

        // Hard DB ceiling protection against pending-table exhaustion
        $maxPending = max(100, (int) config('syncviet.auth_security.max_active_pending_registrations', 5000));
        $activePendingCount = PendingRegistration::query()
            ->where('status', PendingRegistration::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->count();

        if ($activePendingCount >= $maxPending) {
            $this->events->record('registration_blocked', 'critical', null, $normalizedEmail, $request, 50, [
                'reason' => 'pending_capacity_reached',
            ]);

            throw ValidationException::withMessages([
                'email' => [__('Registration queue is temporarily busy. Please try again in a few minutes.')],
            ]);
        }

        // Ensure email is not owned by any active or soft-deleted user
        if (User::withTrashed()->where('email', $normalizedEmail)->exists()) {
            throw ValidationException::withMessages([
                'email' => [__('The email has already been taken.')],
            ]);
        }

        $username = isset($validated['username']) && is_string($validated['username']) && $validated['username'] !== ''
            ? $validated['username']
            : $this->usernameGenerator->generate((string) $validated['name']);

        if (User::withTrashed()->where('username', $username)->exists()) {
            throw ValidationException::withMessages([
                'username' => [__('The username has already been taken.')],
            ]);
        }

        $riskScore = $this->rateLimiter->registrationRiskScore($request, $normalizedEmail, false);
        $riskThreshold = (int) $this->settings->get('auth_suspicious_risk_threshold', 50);
        $retentionHours = max(6, min(72, (int) $this->settings->get('auth_pending_retention_hours', 24)));
        $fingerprint = $this->disposableEmails->canonicalFingerprint($normalizedEmail);

        $returnTo = $request->hasSession() && is_string($request->session()->get(ReferralService::SESSION_RETURN_KEY))
            ? (string) $request->session()->get(ReferralService::SESSION_RETURN_KEY)
            : null;

        $acquisition = [
            'return_to' => $returnTo,
            'locale' => app()->getLocale(),
            'ref_cookie' => is_string($request->cookie(ReferralService::COOKIE_NAME)) ? Str::limit((string) $request->cookie(ReferralService::COOKIE_NAME), 32, '') : null,
        ];

        $passwordHash = Hash::make((string) $validated['password']);
        $referralCode = isset($input['referral_code']) && is_string($input['referral_code'])
            ? Str::limit(trim($input['referral_code']), 32, '')
            : null;

        /** @var PendingRegistration $pending */
        $pending = DB::transaction(function () use (
            $validated,
            $username,
            $normalizedEmail,
            $fingerprint,
            $passwordHash,
            $referralCode,
            $acquisition,
            $request,
            $riskScore,
            $retentionHours,
        ): PendingRegistration {
            /** @var PendingRegistration|null $existing */
            $existing = PendingRegistration::query()
                ->where('normalized_email', $normalizedEmail)
                ->where('status', PendingRegistration::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'public_token' => bin2hex(random_bytes(32)),
                'name' => Str::limit(trim((string) $validated['name']), 120, ''),
                'username' => $username,
                'email' => $normalizedEmail,
                'normalized_email' => $normalizedEmail,
                'canonical_fingerprint' => $fingerprint,
                'password_hash' => $passwordHash,
                'timezone' => isset($validated['timezone']) && is_string($validated['timezone']) ? $validated['timezone'] : 'Asia/Ho_Chi_Minh',
                'preferred_locale' => app()->getLocale(),
                'referral_code' => $referralCode !== '' ? $referralCode : null,
                'acquisition_metadata' => $acquisition,
                'ip_address' => Str::limit((string) $request->ip(), 45, ''),
                'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
                'risk_score' => $riskScore,
                'risk_signals' => $riskScore >= 25 ? ['velocity_or_ua_signal' => true] : null,
                'status' => PendingRegistration::STATUS_PENDING,
                'expires_at' => now()->addHours($retentionHours),
            ];

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();

                return $existing->refresh();
            }

            return PendingRegistration::query()->create($attributes);
        });

        try {
            $this->challenges->issueAndSend(
                purpose: AuthVerificationChallenge::PURPOSE_REGISTRATION,
                email: $pending->normalized_email,
                recipientName: $pending->name,
                locale: $pending->preferred_locale,
                request: $request,
                pending: $pending,
            );
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'email' => [__('Unable to send the verification email right now. Please try again in a moment.')],
            ]);
        }

        $this->rateLimiter->markOtpCooldown($pending->normalized_email);

        $this->events->record(
            $riskScore >= $riskThreshold ? 'registration_suspicious' : 'registration_started',
            $riskScore >= $riskThreshold ? 'warning' : 'info',
            null,
            $pending->normalized_email,
            $request,
            $riskScore,
            ['pending_id' => $pending->id],
        );

        return $pending;
    }

    /**
     * Atomically convert a verified PendingRegistration into an official active, verified User.
     * Uses DB transaction + row lock to prevent duplicate activation under concurrent requests.
     */
    public function activatePendingRegistration(PendingRegistration $pending, Request $request): User
    {
        /** @var User $user */
        $user = DB::transaction(function () use ($pending): User {
            /** @var PendingRegistration|null $locked */
            $locked = PendingRegistration::query()
                ->whereKey($pending->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->isPendingAndValid()) {
                throw ValidationException::withMessages([
                    'code' => [__('This registration request has expired or was already completed. Please register again.')],
                ]);
            }

            if (User::withTrashed()->where('email', $locked->normalized_email)->exists()) {
                $locked->forceFill(['status' => PendingRegistration::STATUS_CANCELLED])->save();

                throw ValidationException::withMessages([
                    'code' => [__('An account with this email address already exists. Please sign in.')],
                ]);
            }

            $username = $locked->username;
            if (User::withTrashed()->where('username', $username)->exists()) {
                $username = $this->usernameGenerator->generate($locked->name);
            }

            $newUser = new User([
                'name' => $locked->name,
                'username' => $username,
                'email' => $locked->normalized_email,
                'timezone' => $locked->timezone,
                'preferred_locale' => $locked->preferred_locale,
                'has_password' => true,
            ]);

            // Assign pre-hashed password directly and enforce server-owned privileges & verification status
            $newUser->forceFill([
                'password' => $locked->password_hash,
                'role' => UserRole::User,
                'status' => UserStatus::Active,
                'account_type' => AccountType::Human,
                'email_verified_at' => now(),
            ]);
            $newUser->save();

            $locked->forceFill([
                'status' => PendingRegistration::STATUS_COMPLETED,
                'completed_at' => now(),
                'created_user_id' => $newUser->id,
            ])->save();

            AuthVerificationChallenge::query()
                ->where('pending_registration_id', $locked->id)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            return $newUser->refresh();
        });

        try {
            $returnTo = is_array($pending->acquisition_metadata) && is_string($pending->acquisition_metadata['return_to'] ?? null)
                ? (string) $pending->acquisition_metadata['return_to']
                : null;

            $this->organicGrowth->attributeNewRegistration($user, $request);
            $this->referralService->attributeRegistration($user, $request, $pending->referral_code);
            $this->onboardingService->ensureProgress($user, $returnTo);
            $this->onboardingService->recordMilestone($user, ActivationMilestone::RegistrationCompleted);
            $this->onboardingService->recordMilestone($user, ActivationMilestone::EmailVerified);
            $this->earlyMemberService->enrollEarlyMemberIfEligible($user);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->events->record('registration_completed', 'info', $user, $user->email, $request, 0, [
            'pending_id' => $pending->id,
        ]);

        return $user;
    }

    /**
     * Safely change the email address of an unactivated PendingRegistration and resend a fresh OTP.
     */
    public function changePendingEmail(PendingRegistration $pending, string $rawEmail, Request $request): PendingRegistration
    {
        $newEmail = $this->disposableEmails->normalizeEmail($rawEmail);

        Validator::make(['email' => $newEmail], [
            'email' => ['required', 'string', 'email', 'max:255'],
        ])->validate();

        $limit = $this->rateLimiter->attemptChangeEmail($request, 'pending:'.$pending->id, $newEmail);
        if (! $limit['allowed']) {
            throw ValidationException::withMessages([
                'email' => [__('Too many email change attempts. Please try again later.')],
            ]);
        }

        if ($this->disposableEmails->isDisposable($newEmail)) {
            throw ValidationException::withMessages([
                'email' => [__('Temporary or disposable email addresses are not allowed. Please use a permanent email address.')],
            ]);
        }

        if (User::withTrashed()->where('email', $newEmail)->exists()) {
            throw ValidationException::withMessages([
                'email' => [__('The email has already been taken.')],
            ]);
        }

        $pending->forceFill([
            'email' => $newEmail,
            'normalized_email' => $newEmail,
            'canonical_fingerprint' => $this->disposableEmails->canonicalFingerprint($newEmail),
        ])->save();

        try {
            $this->challenges->issueAndSend(
                purpose: AuthVerificationChallenge::PURPOSE_REGISTRATION,
                email: $newEmail,
                recipientName: $pending->name,
                locale: $pending->preferred_locale,
                request: $request,
                pending: $pending,
            );
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'email' => [__('Unable to send the verification email right now. Please try again in a moment.')],
            ]);
        }

        $this->rateLimiter->markOtpCooldown($newEmail);

        return $pending->refresh();
    }

    public function cancelPendingRegistration(PendingRegistration $pending): void
    {
        DB::transaction(static function () use ($pending): void {
            $pending->forceFill(['status' => PendingRegistration::STATUS_CANCELLED])->save();

            AuthVerificationChallenge::query()
                ->where('pending_registration_id', $pending->id)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);
        });
    }

    /**
     * Batch-clean expired pending registrations and stale challenges safely (never deleting real users).
     *
     * @return array{pending_expired: int, pending_deleted: int, challenges_deleted: int}
     */
    public function cleanupExpired(): array
    {
        $expiredMarked = 0;
        $pendingDeleted = 0;
        $challengesDeleted = 0;

        if (Schema::hasTable('pending_registrations')) {
            $expiredMarked = PendingRegistration::query()
                ->where('status', PendingRegistration::STATUS_PENDING)
                ->where('expires_at', '<=', now())
                ->update(['status' => PendingRegistration::STATUS_EXPIRED]);

            $cutoff = now()->subDays(7);
            PendingRegistration::query()
                ->whereIn('status', [
                    PendingRegistration::STATUS_EXPIRED,
                    PendingRegistration::STATUS_CANCELLED,
                    PendingRegistration::STATUS_COMPLETED,
                ])
                ->where('updated_at', '<=', $cutoff)
                ->chunkById(250, static function ($rows) use (&$pendingDeleted): void {
                    $ids = $rows->pluck('id')->all();
                    if ($ids !== []) {
                        $pendingDeleted += PendingRegistration::query()->whereIn('id', $ids)->delete();
                    }
                });
        }

        if (Schema::hasTable('auth_verification_challenges')) {
            $challengeCutoff = now()->subHours(48);
            AuthVerificationChallenge::query()
                ->where(static function ($q) use ($challengeCutoff): void {
                    $q->where('expires_at', '<=', $challengeCutoff)
                        ->orWhereNotNull('used_at')
                        ->orWhereNotNull('invalidated_at');
                })
                ->where('updated_at', '<=', $challengeCutoff)
                ->chunkById(250, static function ($rows) use (&$challengesDeleted): void {
                    $ids = $rows->pluck('id')->all();
                    if ($ids !== []) {
                        $challengesDeleted += AuthVerificationChallenge::query()->whereIn('id', $ids)->delete();
                    }
                });
        }

        return [
            'pending_expired' => $expiredMarked,
            'pending_deleted' => $pendingDeleted,
            'challenges_deleted' => $challengesDeleted,
        ];
    }

    /**
     * Audit existing unverified accounts and safely revoke their active sessions & remember tokens
     * without deleting any real account, post, balance, or transaction, and without auto-verifying them.
     *
     * @return array{
     *     total_unverified: int,
     *     with_posts: int,
     *     with_payments: int,
     *     with_rewards: int,
     *     sessions_revoked: int,
     *     remember_tokens_rotated: int
     * }
     */
    public function auditAndRemediateUnverifiedUsers(bool $revokeSessions = true, ?User $actor = null): array
    {
        $baseQuery = User::query()
            ->whereNull('email_verified_at')
            ->where('account_type', AccountType::Human->value);

        $totalUnverified = (clone $baseQuery)->count();
        $withPosts = (clone $baseQuery)->whereHas('posts')->count();
        $withPayments = (clone $baseQuery)->whereHas('payments')->count();
        $withRewards = (clone $baseQuery)->where(static function ($q): void {
            $q->whereHas('earlyMember')->orWhereHas('subscriptions');
        })->count();

        $sessionsRevoked = 0;
        $tokensRotated = 0;

        if ($revokeSessions && $totalUnverified > 0) {
            (clone $baseQuery)->chunkById(200, static function ($users) use (&$sessionsRevoked, &$tokensRotated): void {
                $ids = $users->pluck('id')->all();

                if (Schema::hasTable('sessions') && $ids !== []) {
                    $sessionsRevoked += DB::table('sessions')->whereIn('user_id', $ids)->delete();
                }

                foreach ($users as $user) {
                    if ($user->remember_token !== null) {
                        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
                        $tokensRotated++;
                    }
                }
            });

            $this->events->record('unverified_sessions_revoked', 'info', $actor, null, null, 0, [
                'total_unverified' => $totalUnverified,
                'sessions_revoked' => $sessionsRevoked,
                'remember_tokens_rotated' => $tokensRotated,
            ]);
        }

        return [
            'total_unverified' => $totalUnverified,
            'with_posts' => $withPosts,
            'with_payments' => $withPayments,
            'with_rewards' => $withRewards,
            'sessions_revoked' => $sessionsRevoked,
            'remember_tokens_rotated' => $tokensRotated,
        ];
    }
}
