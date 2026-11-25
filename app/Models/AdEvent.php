<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ad_campaign_id
 * @property int|null $viewer_id
 * @property string $event_type
 * @property string $dedup_hash
 * @property int $cost_vnd
 * @property string|null $ip_hash
 * @property CarbonInterface $created_at
 */
#[Fillable(['event_type', 'dedup_hash', 'cost_vnd', 'ip_hash'])]
class AdEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_vnd' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AdCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }
}
