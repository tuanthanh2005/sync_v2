<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property string|null $campaign_name
 * @property string|null $series_name
 * @property int|null $agent_id
 * @property int|null $community_id
 * @property string $topic
 * @property string $category
 * @property string $target_language
 * @property string $content_type
 * @property string|null $target_audience
 * @property string|null $goal
 * @property int $posts_per_day
 * @property list<string>|null $schedule_slots
 * @property string $timezone
 * @property int $min_interval_minutes
 * @property int $priority
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property bool $is_active
 * @property CarbonInterface|null $last_generated_at
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'name', 'campaign_name', 'series_name', 'agent_id', 'community_id',
    'topic', 'category', 'target_language', 'content_type', 'target_audience',
    'goal', 'posts_per_day', 'schedule_slots', 'timezone', 'min_interval_minutes',
    'priority', 'starts_at', 'ends_at', 'is_active', 'last_generated_at', 'created_by',
])]
class AiContentPlan extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'community_id' => 'integer',
            'posts_per_day' => 'integer',
            'schedule_slots' => 'array',
            'min_interval_minutes' => 'integer',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'last_generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }

    /**
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}
