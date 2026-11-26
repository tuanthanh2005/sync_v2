<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $referral_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property int|null $actor_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface $created_at
 */
#[Fillable(['referral_id', 'from_status', 'to_status', 'reason', 'actor_id', 'metadata', 'created_at'])]
class ReferralQualificationEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'referral_id' => 'integer',
            'actor_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Referral, $this>
     */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class, 'referral_id');
    }
}
