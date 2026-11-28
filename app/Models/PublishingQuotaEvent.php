<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $action_type
 * @property int|null $subject_id
 * @property string $trust_tier
 * @property string|null $ip_hash
 * @property CarbonInterface $created_at
 */
#[Fillable([
    'user_id',
    'action_type',
    'subject_id',
    'trust_tier',
    'ip_hash',
    'created_at',
])]
class PublishingQuotaEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'subject_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
