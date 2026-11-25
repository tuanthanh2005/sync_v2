<?php

namespace App\Models;

use App\Enums\AdBudgetType;
use App\Enums\AdCampaignStatus;
use App\Enums\AdObjective;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $post_id
 * @property string $name
 * @property AdObjective $objective
 * @property list<string>|null $target_topics
 * @property string|null $target_url
 * @property string|null $cta_label
 * @property string $schedule_mode
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property AdBudgetType $budget_type
 * @property int|null $daily_budget
 * @property int $duration_days
 * @property int $total_budget
 * @property int $held_amount
 * @property int $spent_amount
 * @property int $impressions_count
 * @property int $clicks_count
 * @property AdCampaignStatus $status
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property string|null $rejection_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'name',
    'objective',
    'target_topics',
    'target_url',
    'cta_label',
    'schedule_mode',
    'starts_at',
    'ends_at',
    'budget_type',
    'daily_budget',
    'duration_days',
])]
class AdCampaign extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'objective' => AdObjective::class,
            'budget_type' => AdBudgetType::class,
            'status' => AdCampaignStatus::class,
            'target_topics' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'daily_budget' => 'integer',
            'duration_days' => 'integer',
            'total_budget' => 'integer',
            'held_amount' => 'integer',
            'spent_amount' => 'integer',
            'impressions_count' => 'integer',
            'clicks_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<AdEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AdEvent::class);
    }

    /**
     * @return HasMany<AdDailyStat, $this>
     */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(AdDailyStat::class);
    }
}
