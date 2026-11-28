<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $post_id
 * @property string $event_type
 * @property string $action_context
 * @property int $risk_score
 * @property string $risk_level
 * @property string $decision
 * @property array<string, mixed>|null $signals
 * @property string|null $trust_tier
 * @property string|null $content_hash
 * @property string|null $ip_hash
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property CarbonInterface $created_at
 */
#[Fillable([
    'user_id',
    'post_id',
    'event_type',
    'action_context',
    'risk_score',
    'risk_level',
    'decision',
    'signals',
    'trust_tier',
    'content_hash',
    'ip_hash',
    'reviewed_by',
    'reviewed_at',
    'created_at',
])]
class SpamEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'post_id' => 'integer',
            'risk_score' => 'integer',
            'signals' => 'array',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
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

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
