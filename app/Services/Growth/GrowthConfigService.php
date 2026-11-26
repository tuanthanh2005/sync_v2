<?php

namespace App\Services\Growth;

use App\Models\GrowthActivityLog;
use App\Models\GrowthSetting;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class GrowthConfigService
{
    public const CACHE_KEY = 'syncviet.growth.settings.v1';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        try {
            if (! Schema::hasTable('growth_settings')) {
                return [];
            }

            /** @var array<string, string|null> $cached */
            $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(5), static function (): array {
                return GrowthSetting::query()
                    ->pluck('value', 'key')
                    ->map(static fn (mixed $v): ?string => $v === null ? null : (string) $v)
                    ->all();
            });

            return $cached;
        } catch (\Throwable) {
            return [];
        }
    }

    public function bool(string $key, bool $default = false): bool
    {
        $all = $this->all();
        if (! array_key_exists($key, $all) || $all[$key] === null) {
            return $default;
        }

        return in_array(strtolower((string) $all[$key]), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $all = $this->all();
        if (! array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
            return $default;
        }

        return (int) $all[$key];
    }

    public function string(string $key, string $default = ''): string
    {
        $all = $this->all();
        if (! array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
            return $default;
        }

        return (string) $all[$key];
    }

    public function set(string $key, mixed $value, string $group = 'general', string $type = 'string', ?User $actor = null): void
    {
        $serialized = match ($type) {
            'boolean' => ((bool) $value) ? '1' : '0',
            'integer' => (string) ((int) $value),
            default => $value === null ? null : (string) $value,
        };

        GrowthSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'value' => $serialized,
                'type' => $type,
                'updated_by' => $actor?->id,
            ],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logActivity(
        string $action,
        ?User $actor = null,
        ?int $targetUserId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $metadata = [],
    ): void {
        if (Schema::hasTable('growth_activity_logs')) {
            GrowthActivityLog::query()->create([
                'actor_id' => $actor?->id,
                'target_user_id' => $targetUserId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'metadata' => $metadata !== [] ? $metadata : null,
                'created_at' => now(),
            ]);
        }

        if ($actor !== null) {
            $this->auditLogger->log($action, null, $metadata, $actor);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsPayload(): array
    {
        return [
            'referral_enabled' => $this->bool('referral_enabled', true),
            'referral_rewards_auto_grant' => $this->bool('referral_rewards_auto_grant', false),
            'referral_attribution_days' => $this->int('referral_attribution_days', 30),
            'referral_verification_hours' => $this->int('referral_verification_hours', 72),
            'referral_require_email_verified' => $this->bool('referral_require_email_verified', true),
            'referral_require_onboarding_or_activity' => $this->bool('referral_require_onboarding_or_activity', true),
            'referral_max_reward_days_per_user' => $this->int('referral_max_reward_days_per_user', 365),
            'referral_visit_retention_days' => $this->int('referral_visit_retention_days', 90),
            'early_member_enabled' => $this->bool('early_member_enabled', false),
            'early_member_max_slots' => $this->int('early_member_max_slots', 1000),
            'early_member_premium_days' => $this->int('early_member_premium_days', 30),
            'early_member_badge_enabled' => $this->bool('early_member_badge_enabled', true),
            'founding_member_enabled' => $this->bool('founding_member_enabled', false),
            'founding_member_max_slots' => $this->int('founding_member_max_slots', 500),
            'onboarding_enabled' => $this->bool('onboarding_enabled', true),
            'retention_reengagement_enabled' => $this->bool('retention_reengagement_enabled', false),
            'retention_digest_enabled' => $this->bool('retention_digest_enabled', false),
            'retention_reengagement_days' => $this->string('retention_reengagement_days', '3,7,30'),
            'retention_frequency_cap_hours' => $this->int('retention_frequency_cap_hours', 72),
            'fraud_velocity_hourly_threshold' => $this->int('fraud_velocity_hourly_threshold', 5),
            'fraud_manual_review_score' => $this->int('fraud_manual_review_score', 50),
        ];
    }
}
