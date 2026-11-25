<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'secret',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'recovery_code',
        'code',
    ];

    /**
     * Record a security or administrative audit trail entry with sensitive fields redacted.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?User $actor = null,
        ?Request $request = null,
    ): AuditLog {
        $resolvedRequest = $request ?? request();
        $resolvedActor = $actor ?? $resolvedRequest->user();

        return AuditLog::query()->create([
            'user_id' => $resolvedActor?->id,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'ip_address' => $resolvedRequest->ip(),
            'user_agent' => Str::limit((string) $resolvedRequest->userAgent(), 500, ''),
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (in_array(Str::lower((string) $key), self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $nested */
                $nested = $value;
                $sanitized[$key] = $this->redact($nested);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
