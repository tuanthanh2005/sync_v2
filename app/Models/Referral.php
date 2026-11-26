<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $referrer_id
 * @property int $referee_id
 * @property int $referral_code_id
 * @property int|null $campaign_id
 * @property int|null $campaign_version_id
 * @property ReferralStatus $status
 * @property string|null $status_reason
 * @property string $attribution_source
 * @property string|null $landing_path
 * @property string $locale
 * @property string|null $ip_hash
 * @property int $risk_score
 * @property list<string>|null $risk_signals
 * @property CarbonInterface|null $eligible_at
 * @property CarbonInterface|null $qualified_at
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property CarbonInterface|null $revoked_at
 * @property string|null $revoked_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'referrer_id', 'referee_id', 'referral_code_id', 'campaign_id', 'campaign_version_id',
    'status', 'status_reason', 'attribution_source', 'landing_path', 'locale',
    'ip_hash', 'risk_score', 'risk_signals', 'eligible_at', 'qualified_at',
    'reviewed_by', 'reviewed_at', 'revoked_at', 'revoked_reason',
])]
class Referral extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'referrer_id' => 'integer',
            'referee_id' => 'integer',
            'referral_code_id' => 'integer',
            'campaign_id' => 'integer',
            'campaign_version_id' => 'integer',
            'status' => ReferralStatus::class,
            'risk_score' => 'integer',
            'risk_signals' => 'array',
            'eligible_at' => 'datetime',
            'qualified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_id');
    }

    /**
     * @return BelongsTo<ReferralCode, $this>
     */
    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_code_id');
    }

    /**
     * @return BelongsTo<GrowthCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GrowthCampaign::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<ReferralQualificationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ReferralQualificationEvent::class, 'referral_id')->latest('id');
    }
}
