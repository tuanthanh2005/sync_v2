<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $member_number
 * @property int|null $campaign_id
 * @property int|null $subscription_id
 * @property string $status
 * @property int $premium_days_granted
 * @property CarbonInterface $qualified_at
 * @property int|null $revoked_by
 * @property CarbonInterface|null $revoked_at
 * @property string|null $revoke_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id', 'member_number', 'campaign_id', 'subscription_id', 'status',
    'premium_days_granted', 'qualified_at', 'revoked_by', 'revoked_at', 'revoke_reason',
])]
class EarlyMember extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'member_number' => 'integer',
            'campaign_id' => 'integer',
            'subscription_id' => 'integer',
            'premium_days_granted' => 'integer',
            'qualified_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
