<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $campaign_id
 * @property string $slug
 * @property string $name
 * @property int $required_qualified_referrals
 * @property int $cumulative_premium_days
 * @property bool $is_repeatable
 * @property int $max_claims_per_user
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'campaign_id', 'slug', 'name', 'required_qualified_referrals',
    'cumulative_premium_days', 'is_repeatable', 'max_claims_per_user',
    'is_active', 'sort_order',
])]
class ReferralRewardRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'required_qualified_referrals' => 'integer',
            'cumulative_premium_days' => 'integer',
            'is_repeatable' => 'boolean',
            'max_claims_per_user' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GrowthCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GrowthCampaign::class, 'campaign_id');
    }
}
