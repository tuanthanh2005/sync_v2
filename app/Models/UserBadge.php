<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $badge_type
 * @property string $label_key
 * @property string $icon
 * @property string $color
 * @property string $grant_source
 * @property int|null $campaign_id
 * @property int|null $granted_by
 * @property CarbonInterface $granted_at
 * @property CarbonInterface|null $expires_at
 * @property int|null $revoked_by
 * @property CarbonInterface|null $revoked_at
 * @property string|null $revoke_reason
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id', 'badge_type', 'label_key', 'icon', 'color', 'grant_source',
    'campaign_id', 'granted_by', 'granted_at', 'expires_at', 'revoked_by',
    'revoked_at', 'revoke_reason', 'metadata',
])]
class UserBadge extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'campaign_id' => 'integer',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
