<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int $user_id
 * @property int|null $campaign_version_id
 * @property string $status
 * @property CarbonInterface $joined_at
 * @property CarbonInterface|null $rewarded_at
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['campaign_id', 'user_id', 'campaign_version_id', 'status', 'joined_at', 'rewarded_at', 'metadata'])]
class GrowthCampaignParticipant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'user_id' => 'integer',
            'campaign_version_id' => 'integer',
            'joined_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'metadata' => 'array',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
