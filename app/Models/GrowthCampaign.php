<?php

namespace App\Models;

use App\Enums\GrowthCampaignStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property GrowthCampaignStatus $status
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property string $target_audience
 * @property list<string>|null $target_languages
 * @property array<string, mixed>|null $qualification_rules
 * @property array<string, mixed>|null $reward_config
 * @property int|null $max_participants
 * @property int $current_participants
 * @property int|null $max_reward_days_budget
 * @property int $used_reward_days_budget
 * @property int|null $active_version_id
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'slug', 'name', 'description', 'type', 'status', 'starts_at', 'ends_at',
    'target_audience', 'target_languages', 'qualification_rules', 'reward_config',
    'max_participants', 'current_participants', 'max_reward_days_budget',
    'used_reward_days_budget', 'active_version_id', 'created_by',
])]
class GrowthCampaign extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GrowthCampaignStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'target_languages' => 'array',
            'qualification_rules' => 'array',
            'reward_config' => 'array',
            'max_participants' => 'integer',
            'current_participants' => 'integer',
            'max_reward_days_budget' => 'integer',
            'used_reward_days_budget' => 'integer',
            'active_version_id' => 'integer',
        ];
    }

    public function isCurrentlyActive(): bool
    {
        if ($this->status !== GrowthCampaignStatus::Active) {
            return false;
        }

        $now = now();
        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    public function hasRemainingBudget(int $daysNeeded): bool
    {
        if ($this->max_reward_days_budget === null) {
            return true;
        }

        return ($this->used_reward_days_budget + $daysNeeded) <= $this->max_reward_days_budget;
    }

    /**
     * @return HasMany<GrowthCampaignVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(GrowthCampaignVersion::class, 'campaign_id')->orderByDesc('version_number');
    }

    /**
     * @return HasMany<GrowthCampaignParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(GrowthCampaignParticipant::class, 'campaign_id');
    }
}
