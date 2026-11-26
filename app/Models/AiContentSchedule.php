<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $draft_id
 * @property int $agent_id
 * @property CarbonInterface $scheduled_for
 * @property string $timezone
 * @property string $status
 * @property int|null $published_post_id
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonInterface|null $executed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'draft_id', 'agent_id', 'scheduled_for', 'timezone', 'status',
    'published_post_id', 'attempts', 'last_error', 'executed_at',
])]
class AiContentSchedule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draft_id' => 'integer',
            'agent_id' => 'integer',
            'published_post_id' => 'integer',
            'attempts' => 'integer',
            'scheduled_for' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiContentDraft, $this>
     */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(AiContentDraft::class, 'draft_id');
    }

    /**
     * @return BelongsTo<AiAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }
}
