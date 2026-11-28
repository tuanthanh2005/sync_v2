<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $action
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property string $status
 * @property int|null $post_id
 * @property int|null $response_code
 * @property CarbonInterface $expires_at
 */
#[Fillable([
    'user_id',
    'action',
    'idempotency_key',
    'payload_hash',
    'status',
    'post_id',
    'response_code',
    'expires_at',
])]
class PostIdempotency extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'post_id' => 'integer',
            'response_code' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
