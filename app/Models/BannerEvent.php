<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $banner_campaign_id
 * @property int|null $viewer_id
 * @property string $event_type
 * @property string $dedup_hash
 * @property string|null $ip_hash
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface $created_at
 */
#[Fillable(['event_type', 'dedup_hash', 'ip_hash', 'metadata'])]
class BannerEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BannerCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BannerCampaign::class, 'banner_campaign_id');
    }
}
