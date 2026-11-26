<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $referral_id
 * @property int $user_id
 * @property int $risk_score
 * @property list<string>|null $signals
 * @property string $status
 * @property string|null $decision_note
 * @property string|null $appeal_note
 * @property CarbonInterface|null $appealed_at
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'referral_id', 'user_id', 'risk_score', 'signals', 'status',
    'decision_note', 'appeal_note', 'appealed_at', 'reviewed_by', 'reviewed_at',
])]
class GrowthFraudReview extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'referral_id' => 'integer',
            'user_id' => 'integer',
            'risk_score' => 'integer',
            'signals' => 'array',
            'appealed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Referral, $this>
     */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class, 'referral_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
