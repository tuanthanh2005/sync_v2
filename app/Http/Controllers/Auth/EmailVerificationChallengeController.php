<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthVerificationChallenge;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Services\Auth\AuthRateLimiterService;
use App\Services\Auth\AuthSecurityEventService;
use App\Services\Auth\EmailVerificationChallengeService;
use App\Services\Auth\PendingRegistrationService;
use App\Services\Auth\TurnstileService;
use App\Services\System\SystemSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationChallengeController extends Controller
{
    public function __construct(
        private readonly PendingRegistrationService $pendingRegistrations,
        private readonly EmailVerificationChallengeService $challenges,
        private readonly AuthRateLimiterService $rateLimiter,
        private readonly TurnstileService $turnstile,
        private readonly AuthSecurityEventService $events,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Render the email verification challenge screen for either a Pending Registration
     * or an existing unverified User (in a strictly limited verification session).
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $context = $this->resolveContext($request);

        if ($context['already_verified']) {
            return redirect()->intended(route('dashboard'));
        }

        $pending = $context['pending'];
        $existingUser = $context['user'];

        if ($pending === null && $existingUser === null) {
            return redirect()->route('login');
        }

        if ($pending !== null) {
            $email = $pending->normalized_email;
            $riskScore = $pending->risk_score;
        } else {
            /** @var User $existingUser */
            $email = $existingUser->email;
            $riskScore = 0;
        }

        $cooldown = $this->rateLimiter->otpCooldownRemainingSeconds($email);

        $latestChallenge = $this->latestActiveChallenge($pending, $existingUser);
        $requireCaptchaOnResend = $this->turnstile->isEnabled()
            && ($latestChallenge === null || $latestChallenge->resend_count >= 2 || $riskScore >= 35);

        return Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
            'maskedEmail' => $this->events->maskEmail($email),
            'isPendingRegistration' => $context['pending'] !== null,
            'cooldownSeconds' => $cooldown,
            'expireMinutes' => max(3, min(15, (int) $this->settings->get('auth_otp_expire_minutes', 5))),
            'requireTurnstileOnResend' => $requireCaptchaOnResend,
            'isAuthenticatedUnverified' => $request->user() !== null,
        ]);
    }

    /**
     * Verify a 6-digit OTP and activate the pending account or verify an existing unverified account.
     */
    public function verifyOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:12'],
        ]);

        $context = $this->resolveContext($request);
        if ($context['already_verified']) {
            return redirect()->intended(route('dashboard'));
        }

        $pending = $context['pending'];
        $existingUser = $context['user'];

        if ($pending === null && $existingUser === null) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Your verification session has expired. Please sign in or register again.')]);
        }

        if ($pending !== null) {
            $purpose = AuthVerificationChallenge::PURPOSE_REGISTRATION;
            $email = $pending->normalized_email;
        } else {
            /** @var User $existingUser */
            $purpose = AuthVerificationChallenge::PURPOSE_EMAIL_VERIFICATION;
            $email = $existingUser->email;
        }

        $result = $this->challenges->verifyOtp(
            purpose: $purpose,
            email: $email,
            rawOtp: (string) $validated['code'],
            request: $request,
            pending: $pending,
            user: $existingUser,
        );

        if (! $result['ok']) {
            $message = match ($result['reason']) {
                'expired' => __('This verification code has expired. Please request a new code.'),
                'too_many_attempts' => __('Too many incorrect verification attempts. Please wait and request a new code.'),
                'mismatch' => __('Invalid verification code. :remaining attempt(s) remaining.', ['remaining' => $result['remaining_attempts']]),
                default => __('Invalid or expired verification code. Please request a new code.'),
            };

            throw ValidationException::withMessages([
                'code' => [$message],
            ]);
        }

        if ($pending !== null) {
            $activatedUser = $this->pendingRegistrations->activatePendingRegistration($pending, $request);

            return $this->completeAuthenticatedLogin($activatedUser, $request);
        }

        /** @var User $verifiedUser */
        $verifiedUser = $this->verifyExistingUserAtomically($existingUser);

        return $this->completeAuthenticatedLogin($verifiedUser, $request);
    }

    /**
     * Resend a fresh 6-digit OTP & confirmation link, enforcing cooldown, hourly limits, and Turnstile when risky.
     */
    public function resend(Request $request): RedirectResponse
    {
        $context = $this->resolveContext($request);
        if ($context['already_verified']) {
            return redirect()->intended(route('dashboard'));
        }

        $pending = $context['pending'];
        $existingUser = $context['user'];

        if ($pending === null && $existingUser === null) {
            return redirect()->route('login');
        }

        if ($pending !== null && (bool) $this->settings->get('auth_emergency_registration_pause', false)) {
            throw ValidationException::withMessages([
                'code' => [__('New account registrations are currently paused by the administrator.')],
            ]);
        }

        if ($pending !== null) {
            $email = $pending->normalized_email;
            $riskScore = $pending->risk_score;
            $recipientName = $pending->name;
            $locale = $pending->preferred_locale;
            $purpose = AuthVerificationChallenge::PURPOSE_REGISTRATION;
        } else {
            /** @var User $existingUser */
            $email = $existingUser->email;
            $riskScore = 0;
            $recipientName = $existingUser->name;
            $locale = $existingUser->preferredLocale();
            $purpose = AuthVerificationChallenge::PURPOSE_EMAIL_VERIFICATION;
        }

        $latest = $this->latestActiveChallenge($pending, $existingUser);
        $needsCaptcha = $this->turnstile->isEnabled()
            && ($latest === null || $latest->resend_count >= 2 || $riskScore >= 35);

        if ($needsCaptcha) {
            $token = is_string($request->input('cf_turnstile_response'))
                ? $request->input('cf_turnstile_response')
                : (is_string($request->input('cf-turnstile-response')) ? $request->input('cf-turnstile-response') : null);

            $captcha = $this->turnstile->verify($token, $request, 'resend_otp', $email);
            if (! $captcha['ok']) {
                throw ValidationException::withMessages([
                    'cf_turnstile_response' => [$captcha['message'] ?? __('Please complete the security verification challenge and try again.')],
                ]);
            }
        }

        $limit = $this->rateLimiter->attemptOtpSend($request, $email, enforceCooldown: true);
        if (! $limit['allowed']) {
            throw ValidationException::withMessages([
                'code' => [__('Please wait :seconds seconds before requesting another verification code.', ['seconds' => $limit['retry_after']])],
            ]);
        }

        try {
            $this->challenges->issueAndSend(
                purpose: $purpose,
                email: $email,
                recipientName: $recipientName,
                locale: $locale,
                request: $request,
                pending: $pending,
                user: $existingUser,
            );
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'code' => [__('Unable to send the verification email right now. Please try again in a moment.')],
            ]);
        }

        return back()->with('status', 'verification-link-sent');
    }

    /**
     * Safely update the registration email of an unactivated PendingRegistration.
     */
    public function changeEmail(Request $request): RedirectResponse
    {
        $context = $this->resolveContext($request);
        $pending = $context['pending'];

        if ($pending === null) {
            return redirect()->route('verification.notice')
                ->withErrors(['email' => __('Email address can only be changed here for pending registrations.')]);
        }

        $this->pendingRegistrations->changePendingEmail(
            $pending,
            (string) $request->input('email', ''),
            $request,
        );

        return redirect()
            ->route('verification.notice')
            ->with('status', 'verification-link-sent');
    }

    /**
     * Cancel a pending registration (or sign out of an unverified verification session).
     */
    public function cancel(Request $request): RedirectResponse
    {
        $context = $this->resolveContext($request);
        if ($context['pending'] !== null) {
            $this->pendingRegistrations->cancelPendingRegistration($context['pending']);
        }

        $request->session()->forget([
            PendingRegistrationService::SESSION_PENDING_TOKEN,
            PendingRegistrationService::SESSION_UNVERIFIED_USER_ID,
        ]);

        if ($request->user() !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('register');
    }

    /**
     * Scanner-safe GET confirmation page for email verification links (NEVER activates on GET!).
     */
    public function showConfirmLink(string $token): Response
    {
        $challenge = $this->challenges->inspectLinkToken($token);

        return Inertia::render('auth/ConfirmVerificationLink', [
            'token' => $challenge !== null ? $token : null,
            'valid' => $challenge !== null,
            'maskedEmail' => $challenge !== null ? $this->events->maskEmail($challenge->email) : null,
            'purpose' => $challenge?->purpose,
        ]);
    }

    /**
     * Explicitly activate/verify via POST confirmation (CSRF-protected & atomic transaction).
     */
    public function confirmLink(Request $request, string $token): RedirectResponse
    {
        $challenge = $this->challenges->consumeLinkToken($token, $request);

        if ($challenge === null) {
            return redirect()->route('login')
                ->withErrors(['email' => __('This verification link is invalid or has expired.')]);
        }

        if ($challenge->purpose === AuthVerificationChallenge::PURPOSE_REGISTRATION && $challenge->pending_registration_id !== null) {
            /** @var PendingRegistration|null $pending */
            $pending = PendingRegistration::query()->find($challenge->pending_registration_id);
            if ($pending === null) {
                return redirect()->route('register')
                    ->withErrors(['email' => __('This registration request has expired. Please register again.')]);
            }

            $user = $this->pendingRegistrations->activatePendingRegistration($pending, $request);

            return $this->completeAuthenticatedLogin($user, $request);
        }

        if ($challenge->purpose === AuthVerificationChallenge::PURPOSE_EMAIL_VERIFICATION && $challenge->user_id !== null) {
            /** @var User|null $existingUser */
            $existingUser = User::query()->find($challenge->user_id);
            if ($existingUser === null || ! $existingUser->isActive()) {
                return redirect()->route('login')
                    ->withErrors(['email' => __('Your account is currently suspended, banned, or deactivated.')]);
            }

            $verified = $this->verifyExistingUserAtomically($existingUser);

            return $this->completeAuthenticatedLogin($verified, $request);
        }

        return redirect()->route('login')
            ->withErrors(['email' => __('This verification link is invalid or has expired.')]);
    }

    /**
     * @return array{pending: ?PendingRegistration, user: ?User, already_verified: bool}
     */
    private function resolveContext(Request $request): array
    {
        $authUser = $request->user();
        if ($authUser !== null) {
            if ($authUser->hasVerifiedEmail()) {
                return ['pending' => null, 'user' => $authUser, 'already_verified' => true];
            }

            return ['pending' => null, 'user' => $authUser, 'already_verified' => false];
        }

        $pendingToken = $request->session()->get(PendingRegistrationService::SESSION_PENDING_TOKEN);
        if (is_string($pendingToken) && $pendingToken !== '') {
            /** @var PendingRegistration|null $pending */
            $pending = PendingRegistration::query()
                ->where('public_token', $pendingToken)
                ->first();

            if ($pending !== null && $pending->isPendingAndValid()) {
                return ['pending' => $pending, 'user' => null, 'already_verified' => false];
            }

            $request->session()->forget(PendingRegistrationService::SESSION_PENDING_TOKEN);
        }

        $unverifiedId = $request->session()->get(PendingRegistrationService::SESSION_UNVERIFIED_USER_ID);
        if (is_int($unverifiedId) || (is_string($unverifiedId) && ctype_digit($unverifiedId))) {
            /** @var User|null $existing */
            $existing = User::query()->find((int) $unverifiedId);
            if ($existing !== null && $existing->isActive()) {
                if ($existing->hasVerifiedEmail()) {
                    $request->session()->forget(PendingRegistrationService::SESSION_UNVERIFIED_USER_ID);

                    return ['pending' => null, 'user' => null, 'already_verified' => false];
                }

                return ['pending' => null, 'user' => $existing, 'already_verified' => false];
            }

            $request->session()->forget(PendingRegistrationService::SESSION_UNVERIFIED_USER_ID);
        }

        return ['pending' => null, 'user' => null, 'already_verified' => false];
    }

    private function latestActiveChallenge(?PendingRegistration $pending, ?User $user): ?AuthVerificationChallenge
    {
        $query = AuthVerificationChallenge::query()->latest('id');
        if ($pending !== null) {
            $query->where('pending_registration_id', $pending->id);
        } elseif ($user !== null) {
            $query->where('user_id', $user->id)
                ->where('purpose', AuthVerificationChallenge::PURPOSE_EMAIL_VERIFICATION);
        } else {
            return null;
        }

        return $query->first();
    }

    private function verifyExistingUserAtomically(?User $user): User
    {
        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => [__('Invalid verification session.')],
            ]);
        }

        /** @var User $updated */
        $updated = DB::transaction(static function () use ($user): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->email_verified_at === null) {
                $locked->forceFill(['email_verified_at' => now()])->save();
            }

            AuthVerificationChallenge::query()
                ->where('user_id', $locked->id)
                ->where('purpose', AuthVerificationChallenge::PURPOSE_EMAIL_VERIFICATION)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            return $locked->refresh();
        });

        return $updated;
    }

    private function completeAuthenticatedLogin(User $user, Request $request): RedirectResponse
    {
        $request->session()->forget([
            PendingRegistrationService::SESSION_PENDING_TOKEN,
            PendingRegistrationService::SESSION_UNVERIFIED_USER_ID,
        ]);

        if (! $user->isActive()) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Your account is currently suspended, banned, or deactivated.')]);
        }

        // Enforce 2FA challenge if already enabled on existing account
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your email has been verified and your account is now active.'),
        ]);

        return redirect()->intended(route('dashboard'));
    }
}
