<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $rule_id
 * @property int|null $campaign_id
 * @property int|null $subscription_id
 * @property string $idempotency_key
 * @property int $claim_index
 * @property int $milestone_referrals
 * @property int $days_granted
 * @property int $cumulative_days_target
 * @property string $source
 * @property string $status
 * @property string|null $note
 * @property int|null $granted_by
 * @property CarbonInterface $granted_at
 * @property int|null $revoked_by
 * @property CarbonInterface|null $revoked_at
 * @property string|null $revoke_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id', 'rule_id', 'campaign_id', 'subscription_id', 'idempotency_key',
    'claim_index', 'milestone_referrals', 'days_granted', 'cumulative_days_target',
    'source', 'status', 'note', 'granted_by', 'granted_at', 'revoked_by',
    'revoked_at', 'revoke_reason',
])]
class ReferralReward extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'rule_id' => 'integer',
            'campaign_id' => 'integer',
            'subscription_id' => 'integer',
            'claim_index' => 'integer',
            'milestone_referrals' => 'integer',
            'days_granted' => 'integer',
            'cumulative_days_target' => 'integer',
            'granted_at' => 'datetime',
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
     * @return BelongsTo<ReferralRewardRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ReferralRewardRule::class, 'rule_id');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
