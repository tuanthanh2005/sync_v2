<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property bool $is_active
 * @property string|null $disabled_reason
 * @property int|null $disabled_by
 * @property CarbonInterface|null $disabled_at
 * @property int $visits_count
 * @property int $registrations_count
 * @property int $qualified_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id', 'code', 'is_active', 'disabled_reason', 'disabled_by',
    'disabled_at', 'visits_count', 'registrations_count', 'qualified_count',
])]
class ReferralCode extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_active' => 'boolean',
            'disabled_at' => 'datetime',
            'visits_count' => 'integer',
            'registrations_count' => 'integer',
            'qualified_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Referral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referral_code_id');
    }
}
