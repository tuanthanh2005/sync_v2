<?php

namespace App\Listeners;

use App\Models\LoginHistory;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Events\Dispatcher;
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

        LoginHistory::query()->create([
            'user_id' => $user?->id,
            'email_attempted' => $user?->email,
            'event_type' => 'success',
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'created_at' => now(),
        ]);

        if ($user !== null) {
            $user->forceFill([
                'last_login_at' => now(),
                'last_activity_at' => now(),
            ])->saveQuietly();

            $this->auditLogger->log('auth.login', $user, [], $user, $request);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $request = request();
        $email = isset($event->credentials['email']) && is_string($event->credentials['email'])
            ? Str::lower(trim($event->credentials['email']))
            : null;

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
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->auditLogger->log('auth.2fa_disabled', $event->user, [], $event->user);
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
