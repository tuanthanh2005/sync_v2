<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $draft_id
 * @property int|null $reviewer_id
 * @property string $decision
 * @property string|null $notes
 * @property string|null $draft_content_hash
 * @property CarbonInterface $created_at
 */
#[Fillable(['draft_id', 'reviewer_id', 'decision', 'notes', 'draft_content_hash', 'created_at'])]
class AiContentReview extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draft_id' => 'integer',
            'reviewer_id' => 'integer',
            'created_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
