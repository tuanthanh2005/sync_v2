<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property int|null $report_id
 * @property int|null $model_id
 * @property string $risk_category
 * @property string $risk_level
 * @property string $reason
 * @property string $suggested_action
 * @property bool $review_required
 * @property string $status
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'entity_type', 'entity_id', 'report_id', 'model_id',
    'risk_category', 'risk_level', 'reason', 'suggested_action',
    'review_required', 'status', 'reviewed_by', 'reviewed_at',
])]
class AiModerationAssessment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'report_id' => 'integer',
            'model_id' => 'integer',
            'review_required' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
