<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int $version_number
 * @property array<string, mixed>|null $qualification_rules
 * @property array<string, mixed>|null $reward_config
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 */
#[Fillable(['campaign_id', 'version_number', 'qualification_rules', 'reward_config', 'notes', 'created_by', 'created_at'])]
class GrowthCampaignVersion extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'version_number' => 'integer',
            'qualification_rules' => 'array',
            'reward_config' => 'array',
            'created_at' => 'datetime',
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
