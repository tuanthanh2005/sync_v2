<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $provider_id
 * @property string $name
 * @property string $project_id
 * @property string $api_key_encrypted
 * @property string $key_fingerprint
 * @property string $key_last4
 * @property list<string>|null $allowed_models
 * @property string $billing_tier
 * @property int $rpm_limit
 * @property int $tpm_limit
 * @property int $rpd_limit
 * @property int $monthly_budget_cents
 * @property int $priority
 * @property bool $is_enabled
 * @property string $health_status
 * @property CarbonInterface|null $last_used_at
 * @property CarbonInterface|null $last_tested_at
 * @property string|null $last_error
 * @property CarbonInterface|null $cooldown_until
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'provider_id', 'name', 'project_id', 'key_fingerprint', 'key_last4',
    'allowed_models', 'billing_tier', 'rpm_limit', 'tpm_limit', 'rpd_limit',
    'monthly_budget_cents', 'priority', 'is_enabled', 'health_status',
    'last_used_at', 'last_tested_at', 'last_error', 'cooldown_until', 'created_by',
])]
#[Hidden(['api_key_encrypted'])]
class AiCredential extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_id' => 'integer',
            'allowed_models' => 'array',
            'rpm_limit' => 'integer',
            'tpm_limit' => 'integer',
            'rpd_limit' => 'integer',
            'monthly_budget_cents' => 'integer',
            'priority' => 'integer',
            'is_enabled' => 'boolean',
            'last_used_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'cooldown_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /**
     * @return HasMany<AiUsageRecord, $this>
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(AiUsageRecord::class, 'credential_id');
    }

    public function maskedKey(): string
    {
        return '••••••••'.$this->key_last4;
    }

    public function isInCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }
}
