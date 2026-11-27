<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\System\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthRateLimiterService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AuthSecurityEventService $events,
    ) {}

    /**
     * Enforce multi-dimensional registration rate limits (IP 10-min, IP daily, normalized email, global budget).
     *
     * @return array{allowed: bool, retry_after: int, reason: ?string}
     */
    public function attemptRegistration(Request $request, string $email): array
    {
        $ip = (string) $request->ip();
        $emailKey = $this->events->hashEmail($email);

        $burstLimit = max(1, (int) $this->settings->get('auth_register_limit_10min', 3));
        $dailyLimit = max(3, (int) $this->settings->get('auth_register_limit_day', 10));
        $globalBudget = max(20, (int) $this->settings->get('auth_global_otp_hourly_budget', 300));

        $checks = [
            ['key' => "auth:reg:ip10m:{$ip}", 'max' => $burstLimit, 'decay' => 600, 'reason' => 'ip_10m_limit'],
            ['key' => "auth:reg:ipday:{$ip}", 'max' => $dailyLimit, 'decay' => 86_400, 'reason' => 'ip_daily_limit'],
            ['key' => "auth:reg:email15m:{$emailKey}", 'max' => 3, 'decay' => 900, 'reason' => 'email_burst_limit'],
            ['key' => 'auth:global:email_budget_1h', 'max' => $globalBudget, 'decay' => 3600, 'reason' => 'global_budget_exceeded'],
        ];

        return $this->evaluateChecks($checks, 'registration', $email, $request);
    }

    /**
     * Enforce OTP send / resend limits (60s cooldown, 5/hour per email, IP hourly, global budget).
     *
     * @return array{allowed: bool, retry_after: int, reason: ?string}
     */
    public function attemptOtpSend(Request $request, string $email, bool $enforceCooldown = true): array
    {
        $ip = (string) $request->ip();
        $emailKey = $this->events->hashEmail($email);

        $cooldown = max(30, (int) $this->settings->get('auth_otp_resend_cooldown', 60));
        $emailHourLimit = max(2, (int) $this->settings->get('auth_otp_max_per_email_hour', 5));
        $globalBudget = max(20, (int) $this->settings->get('auth_global_otp_hourly_budget', 300));

        $checks = [];
        if ($enforceCooldown) {
            $checks[] = ['key' => "auth:otp:cooldown:{$emailKey}", 'max' => 1, 'decay' => $cooldown, 'reason' => 'otp_cooldown'];
        }
        $checks[] = ['key' => "auth:otp:email1h:{$emailKey}", 'max' => $emailHourLimit, 'decay' => 3600, 'reason' => 'otp_email_hourly_limit'];
        $checks[] = ['key' => "auth:otp:ip1h:{$ip}", 'max' => 20, 'decay' => 3600, 'reason' => 'otp_ip_hourly_limit'];
        $checks[] = ['key' => 'auth:global:email_budget_1h', 'max' => $globalBudget, 'decay' => 3600, 'reason' => 'global_budget_exceeded'];

        return $this->evaluateChecks($checks, 'otp_send', $email, $request);
    }

    /**
     * Read remaining OTP resend cooldown seconds for UI countdown display.
     */
    public function otpCooldownRemainingSeconds(string $email): int
    {
        $emailKey = $this->events->hashEmail($email);
        $key = "auth:otp:cooldown:{$emailKey}";

        if (! RateLimiter::tooManyAttempts($key, 1)) {
            return 0;
        }

        return max(0, RateLimiter::availableIn($key));
    }

    /**
     * Record an OTP cooldown hit without incrementing twice when initial OTP is dispatched during registration.
     */
    public function markOtpCooldown(string $email): void
    {
        $emailKey = $this->events->hashEmail($email);
        $cooldown = max(30, (int) $this->settings->get('auth_otp_resend_cooldown', 60));
        RateLimiter::hit("auth:otp:cooldown:{$emailKey}", $cooldown);
        RateLimiter::hit("auth:otp:email1h:{$emailKey}", 3600);
    }

    /**
     * Enforce forgot-password limits (3/15 min per email, IP 15-min, global budget).
     *
     * @return array{allowed: bool, retry_after: int, reason: ?string}
     */
    public function attemptForgotPassword(Request $request, string $email): array
    {
        $ip = (string) $request->ip();
        $emailKey = $this->events->hashEmail($email);
        $limit = max(1, (int) $this->settings->get('auth_forgot_password_limit_15min', 3));
        $globalBudget = max(20, (int) $this->settings->get('auth_global_otp_hourly_budget', 300));

        $checks = [
            ['key' => "auth:forgot:email15m:{$emailKey}", 'max' => $limit, 'decay' => 900, 'reason' => 'forgot_email_limit'],
            ['key' => "auth:forgot:ip15m:{$ip}", 'max' => max(6, $limit * 2), 'decay' => 900, 'reason' => 'forgot_ip_limit'],
            ['key' => 'auth:global:email_budget_1h', 'max' => $globalBudget, 'decay' => 3600, 'reason' => 'global_budget_exceeded'],
        ];

        return $this->evaluateChecks($checks, 'forgot_password', $email, $request);
    }

    /**
     * Enforce reset-password submit limits (5/15 min).
     *
     * @return array{allowed: bool, retry_after: int, reason: ?string}
     */
    public function attemptResetPassword(Request $request, string $email): array
    {
        $ip = (string) $request->ip();
        $emailKey = $this->events->hashEmail($email);
        $limit = (int) config('syncviet.auth_security.reset_password_limit_15min', 5);

        $checks = [
            ['key' => "auth:reset:email15m:{$emailKey}", 'max' => $limit, 'decay' => 900, 'reason' => 'reset_email_limit'],
            ['key' => "auth:reset:ip15m:{$ip}", 'max' => $limit * 2, 'decay' => 900, 'reason' => 'reset_ip_limit'],
        ];

        return $this->evaluateChecks($checks, 'reset_password', $email, $request);
    }

    /**
     * Enforce change-email limits (3/hour per account or pending session + IP).
     *
     * @return array{allowed: bool, retry_after: int, reason: ?string}
     */
    public function attemptChangeEmail(Request $request, string $scopeId, string $email, ?User $user = null): array
    {
        $ip = (string) $request->ip();
        $limit = (int) config('syncviet.auth_security.change_email_limit_hour', 3);

        $checks = [
            ['key' => "auth:change_email:scope1h:{$scopeId}", 'max' => $limit, 'decay' => 3600, 'reason' => 'change_email_limit'],
            ['key' => "auth:change_email:ip1h:{$ip}", 'max' => 10, 'decay' => 3600, 'reason' => 'change_email_ip_limit'],
        ];

        return $this->evaluateChecks($checks, 'change_email', $email, $request, $user);
    }

    /**
     * Track failed login signals so suspicious login attempts trigger Turnstile without locking out victims permanently.
     */
    public function recordFailedLoginSignal(Request $request, ?string $email): void
    {
        $ip = (string) $request->ip();
        RateLimiter::hit("auth:login_fail:ip10m:{$ip}", 600);

        if ($email !== null && trim($email) !== '') {
            $emailKey = $this->events->hashEmail($email);
            RateLimiter::hit("auth:login_fail:email10m:{$emailKey}", 600);
        }
    }

    public function clearFailedLoginSignals(Request $request, string $email): void
    {
        $emailKey = $this->events->hashEmail($email);
        RateLimiter::clear("auth:login_fail:email10m:{$emailKey}");
    }

    public function loginRequiresTurnstile(Request $request, ?string $email = null): bool
    {
        $ip = (string) $request->ip();
        if (RateLimiter::attempts("auth:login_fail:ip10m:{$ip}") >= 2) {
            return true;
        }

        if ($email !== null && trim($email) !== '') {
            $emailKey = $this->events->hashEmail($email);
            if (RateLimiter::attempts("auth:login_fail:email10m:{$emailKey}") >= 2) {
                return true;
            }
        }

        return false;
    }

    public function registrationRiskScore(Request $request, string $email, bool $isDisposable): int
    {
        $ip = (string) $request->ip();
        $score = 0;

        if ($isDisposable) {
            $score += 70;
        }

        $ipBurst = RateLimiter::attempts("auth:reg:ip10m:{$ip}");
        if ($ipBurst >= 2) {
            $score += 25;
        }

        $ipDaily = RateLimiter::attempts("auth:reg:ipday:{$ip}");
        if ($ipDaily >= 5) {
            $score += 25;
        }

        $ua = Str::lower((string) $request->userAgent());
        if ($ua === '' || preg_match('/curl|python|wget|scrapy|httpclient|headless|phantom|selenium|playwright/i', $ua) === 1) {
            $score += 40;
        }

        return min(100, $score);
    }

    /**
     * @param  list<array{key: string, max: int, decay: int, reason: string}>  $checks
     * @return array{allowed: bool, retry_after: int, reason: ?string}
     */
    private function evaluateChecks(
        array $checks,
        string $endpoint,
        string $email,
        Request $request,
        ?User $user = null,
    ): array {
        foreach ($checks as $check) {
            if (RateLimiter::tooManyAttempts($check['key'], $check['max'])) {
                $retryAfter = max(1, RateLimiter::availableIn($check['key']));
                $this->events->record('rate_limited', 'warning', $user, $email, $request, 35, [
                    'endpoint' => $endpoint,
                    'reason' => $check['reason'],
                    'retry_after' => $retryAfter,
                ]);

                return [
                    'allowed' => false,
                    'retry_after' => $retryAfter,
                    'reason' => $check['reason'],
                ];
            }
        }

        foreach ($checks as $check) {
            RateLimiter::hit($check['key'], $check['decay']);
        }

        return ['allowed' => true, 'retry_after' => 0, 'reason' => null];
    }
}
