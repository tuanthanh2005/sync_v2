<?php

namespace App\Listeners;

use App\Models\LoginHistory;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\Auth\AuthRateLimiterService;
use App\Services\Security\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

class RecordAuthSecurityEvents
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handleLogin(Login $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $request = request();
        $ip = $request->ip();
        $ua = Str::limit((string) $request->userAgent(), 500, '');

        $isNewDevice = false;
        if ($user !== null && $user->hasVerifiedEmail()) {
            $priorCount = LoginHistory::query()
                ->where('user_id', $user->id)
                ->where('event_type', 'success')
                ->count();

            if ($priorCount > 0 && $ip !== null) {
                $known = LoginHistory::query()
                    ->where('user_id', $user->id)
                    ->where('event_type', 'success')
                    ->where('ip_address', $ip)
                    ->exists();
                $isNewDevice = ! $known;
            }
        }

        LoginHistory::query()->create([
            'user_id' => $user?->id,
            'email_attempted' => $user?->email,
            'event_type' => 'success',
            'ip_address' => $ip,
            'user_agent' => $ua,
            'created_at' => now(),
        ]);

        if ($user !== null) {
            $user->forceFill([
                'last_login_at' => now(),
                'last_activity_at' => now(),
            ])->saveQuietly();

            $this->auditLogger->log('auth.login', $user, [], $user, $request);

            if ($isNewDevice && Cache::add("syncviet.auth.new_device_alert.{$user->id}.{$ip}", 1, now()->addHours(12))) {
                try {
                    $user->notify(new SecurityAlertNotification(
                        alertKey: 'new_device_login',
                        titleKey: 'New sign-in detected on your account',
                        bodyKey: 'Your account was signed in from a new IP address (:ip).',
                        params: ['ip' => (string) $ip],
                        sendEmail: false,
                    ));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    public function handleFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $request = request();
        $email = isset($event->credentials['email']) && is_string($event->credentials['email'])
            ? Str::lower(trim($event->credentials['email']))
            : null;

        app(AuthRateLimiterService::class)->recordFailedLoginSignal($request, $email);

        LoginHistory::query()->create([
            'user_id' => $user?->id,
            'email_attempted' => $email,
            'event_type' => 'failed',
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'created_at' => now(),
        ]);

        $this->auditLogger->log('auth.login_failed', $user, ['email' => $email], $user, $request);
    }

    public function handleLogout(Logout $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $request = request();

        if ($user !== null) {
            LoginHistory::query()->create([
                'user_id' => $user->id,
                'email_attempted' => $user->email,
                'event_type' => 'logout',
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'created_at' => now(),
            ]);

            $this->auditLogger->log('auth.logout', $user, [], $user, $request);
        }
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->auditLogger->log('auth.2fa_confirmed', $event->user, [], $event->user);

        if ($event->user->hasVerifiedEmail()) {
            try {
                $event->user->notify(new SecurityAlertNotification(
                    alertKey: '2fa_enabled',
                    titleKey: 'Two-factor authentication has been enabled',
                    bodyKey: 'Two-factor authentication was successfully enabled on your account.',
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->auditLogger->log('auth.2fa_disabled', $event->user, [], $event->user);

        if ($event->user->hasVerifiedEmail()) {
            try {
                $event->user->notify(new SecurityAlertNotification(
                    alertKey: '2fa_disabled',
                    titleKey: 'Two-factor authentication has been disabled',
                    bodyKey: 'Two-factor authentication was disabled on your account. Re-enable it immediately if this was not you.',
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Failed::class, [self::class, 'handleFailed']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
    }
}
