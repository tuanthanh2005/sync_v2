<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $badge_type
 * @property string $status
 * @property string $label_key
 * @property string $icon
 * @property string $color
 * @property string $grant_source
 * @property int|null $campaign_id
 * @property int|null $granted_by
 * @property CarbonInterface $granted_at
 * @property string|null $grant_reason
 * @property CarbonInterface|null $expires_at
 * @property int|null $revoked_by
 * @property CarbonInterface|null $revoked_at
 * @property string|null $revoke_reason
 * @property bool $is_public
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id', 'badge_type', 'status', 'label_key', 'icon', 'color', 'grant_source',
    'campaign_id', 'granted_by', 'granted_at', 'grant_reason', 'expires_at',
    'revoked_by', 'revoked_at', 'revoke_reason', 'is_public', 'metadata',
])]
class UserBadge extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'campaign_id' => 'integer',
            'granted_by' => 'integer',
            'revoked_by' => 'integer',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_public' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function isActive(): bool
    {
        if (($this->status ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->where(static function (Builder $sub): void {
                $sub->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
