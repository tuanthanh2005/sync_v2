<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuthSecurityEvent;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuthSecurityEventService
{
    /** @var list<string> */
    private const REDACTED_KEYS = [
        'password',
        'password_hash',
        'otp',
        'code',
        'token',
        'secret',
        'recovery_code',
        'two_factor_secret',
        'turnstile_secret',
        'cf-turnstile-response',
    ];

    /**
     * Record a sanitized authentication security telemetry event without ever persisting secrets or OTPs.
     *
     * @param  array<string, mixed>  $details
     */
    public function record(
        string $eventType,
        string $severity = 'info',
        ?User $user = null,
        ?string $email = null,
        ?Request $request = null,
        int $riskScore = 0,
        array $details = [],
    ): ?AuthSecurityEvent {
        if (! Schema::hasTable('auth_security_events')) {
            return null;
        }

        $normalizedEmail = $email !== null ? Str::lower(trim($email)) : ($user?->email ? Str::lower(trim($user->email)) : null);
        $ip = $request?->ip();

        $event = AuthSecurityEvent::query()->create([
            'event_type' => Str::limit($eventType, 48, ''),
            'severity' => in_array($severity, ['info', 'warning', 'high', 'critical'], true) ? $severity : 'info',
            'user_id' => $user?->id,
            'email_hash' => $normalizedEmail !== null && $normalizedEmail !== '' ? $this->hashEmail($normalizedEmail) : null,
            'email_masked' => $normalizedEmail !== null && $normalizedEmail !== '' ? $this->maskEmail($normalizedEmail) : null,
            'ip_address' => $ip !== null ? Str::limit($ip, 45, '') : null,
            'risk_score' => max(0, min(100, $riskScore)),
            'details' => $this->sanitizeDetails($details),
            'created_at' => now(),
        ]);

        if (in_array($eventType, ['captcha_failed', 'rate_limited', 'registration_blocked', 'otp_failed', 'mail_delivery_failed'], true)) {
            $this->maybeAlertAdmins($eventType);
        }

        return $event;
    }

    public function maskEmail(string $email): string
    {
        $clean = Str::lower(trim($email));
        if (! str_contains($clean, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $clean, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.'***@'.$domain;
    }

    public function hashEmail(string $email): string
    {
        $key = (string) config('app.key', 'syncviet-auth-key');

        return hash_hmac('sha256', Str::lower(trim($email)), $key);
    }

    /**
     * Trigger a frequency-capped Admin notification if an attack burst crosses safe thresholds.
     */
    private function maybeAlertAdmins(string $eventType): void
    {
        $thresholds = [
            'captcha_failed' => 10,
            'rate_limited' => 15,
            'registration_blocked' => 10,
            'otp_failed' => 15,
            'mail_delivery_failed' => 3,
        ];

        $threshold = $thresholds[$eventType] ?? 15;
        $recentCount = AuthSecurityEvent::query()
            ->where('event_type', $eventType)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($recentCount < $threshold) {
            return;
        }

        // Strict 30-minute frequency cap per anomaly category so alerts never spam admins
        if (! Cache::add("syncviet.auth.admin_alert.{$eventType}", 1, now()->addMinutes(30))) {
            return;
        }

        $admins = User::query()
            ->where('role', UserRole::Admin->value)
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->limit(10)
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new SocialActivityNotification(
                eventKey: 'security.auth_anomaly',
                titleVi: "Cảnh báo bảo mật xác thực: phát hiện {$recentCount} sự kiện {$eventType} trong 10 phút qua.",
                titleEn: "Authentication security alert: {$recentCount} {$eventType} events detected in the last 10 minutes.",
                url: '/admin/security?tab=protection',
                actorName: (string) config('app.name', 'SysNoxis'),
                actorUsername: 'security',
                titleKey: 'Authentication security alert: :count :event events detected in the last 10 minutes.',
                titleParams: ['count' => $recentCount, 'event' => $eventType],
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function sanitizeDetails(array $details): array
    {
        $clean = [];

        foreach ($details as $key => $value) {
            $lowerKey = Str::lower((string) $key);
            $isSecret = false;
            foreach (self::REDACTED_KEYS as $blocked) {
                if (str_contains($lowerKey, $blocked)) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeDetails($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? Str::limit($value, 240, '') : $value;
            }
        }

        return $clean;
    }
}
