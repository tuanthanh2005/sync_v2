<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\AuthVerificationChallenge;
use App\Models\User;
use App\Services\Auth\AuthRateLimiterService;
use App\Services\Auth\AuthSecurityEventService;
use App\Services\Auth\EmailVerificationChallengeService;
use App\Services\Auth\PendingRegistrationService;
use App\Services\Auth\TurnstileService;
use App\Services\System\SystemSettingService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureTrustedAuthUrls();
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Ensure password reset and email verification URLs ALWAYS use the trusted config('app.url')
     * origin and never an untrusted incoming Host header.
     */
    private function configureTrustedAuthUrls(): void
    {
        ResetPassword::createUrlUsing(static function (object $notifiable, string $token): string {
            $trustedOrigin = rtrim((string) config('app.url', 'https://syncviet.com'), '/');
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? (string) $notifiable->getEmailForPasswordReset()
                : '';

            return $trustedOrigin.'/reset-password/'.$token.'?email='.urlencode($email);
        });

        VerifyEmail::createUrlUsing(static function (object $notifiable): string {
            $trustedOrigin = rtrim((string) config('app.url', 'https://syncviet.com'), '/');
            $expireMinutes = (int) Config::get('auth.verification.expire', 60);

            URL::forceRootUrl($trustedOrigin);
            try {
                return URL::temporarySignedRoute(
                    'verification.verify',
                    Carbon::now()->addMinutes($expireMinutes),
                    [
                        'id' => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : 0,
                        'hash' => sha1(method_exists($notifiable, 'getEmailForVerification') ? (string) $notifiable->getEmailForVerification() : ''),
                    ],
                );
            } finally {
                URL::forceRootUrl(null);
            }
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = Str::lower(trim((string) $request->input(Fortify::username())));

            $rateLimiter = app(AuthRateLimiterService::class);
            $turnstile = app(TurnstileService::class);

            if ($turnstile->isEnabled() && $rateLimiter->loginRequiresTurnstile($request, $email)) {
                $token = is_string($request->input('cf_turnstile_response'))
                    ? $request->input('cf_turnstile_response')
                    : (is_string($request->input('cf-turnstile-response')) ? $request->input('cf-turnstile-response') : null);

                $check = $turnstile->verify($token, $request, 'login', $email);
                if (! $check['ok']) {
                    throw ValidationException::withMessages([
                        'cf_turnstile_response' => [$check['message'] ?? __('Please complete the security verification challenge and try again.')],
                    ]);
                }
            }

            $user = User::query()->where('email', $email)->first();

            if ($user === null || ! Hash::check((string) $request->input('password'), (string) $user->password)) {
                return null;
            }

            if (! $user->isActive()) {
                throw ValidationException::withMessages([
                    Fortify::username() => [__('Your account is currently suspended or disabled.')],
                ]);
            }

            // Never grant a full member session to an unverified account.
            // Transition cleanly into a strictly limited verification-only session.
            if (! $user->hasVerifiedEmail()) {
                $request->session()->put(PendingRegistrationService::SESSION_UNVERIFIED_USER_ID, $user->id);

                if ($rateLimiter->otpCooldownRemainingSeconds($user->email) === 0) {
                    $otpLimit = $rateLimiter->attemptOtpSend($request, $user->email, enforceCooldown: true);
                    if ($otpLimit['allowed']) {
                        try {
                            app(EmailVerificationChallengeService::class)->issueAndSend(
                                purpose: AuthVerificationChallenge::PURPOSE_EMAIL_VERIFICATION,
                                email: $user->email,
                                recipientName: $user->name,
                                locale: $user->preferredLocale(),
                                request: $request,
                                user: $user,
                            );
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                }

                app(AuthSecurityEventService::class)->record(
                    'login_blocked_unverified',
                    'info',
                    $user,
                    $user->email,
                    $request,
                );

                throw new HttpResponseException(
                    redirect()
                        ->route('verification.notice')
                        ->with('status', __('Please verify your email address to complete account registration.')),
                );
            }

            $rateLimiter->clearFailedLoginSignals($request, $email);

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'requireTurnstile' => app(TurnstileService::class)->isEnabled()
                && app(AuthRateLimiterService::class)->loginRequiresTurnstile($request),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return [
                Limit::perMinute(5)->by((string) $request->session()->get('login.id', 'guest')),
                Limit::perMinute(10)->by('2fa-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $perMin = max(3, min(20, (int) app(SystemSettingService::class)->get('auth_login_failed_limit_min', 5)));
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return [
                Limit::perMinute($perMin)->by($throttleKey),
                Limit::perMinute(max(15, $perMin * 4))->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinutes(10, 15)->by('register-ip:'.$request->ip());
        });
    }
}
