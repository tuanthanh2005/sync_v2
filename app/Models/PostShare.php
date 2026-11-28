<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $post_id
 * @property int|null $user_id
 * @property string $actor_hash
 * @property string $share_channel
 * @property bool $is_valid
 * @property CarbonInterface $created_at
 */
#[Fillable([
    'post_id',
    'user_id',
    'actor_hash',
    'share_channel',
    'is_valid',
    'created_at',
])]
class PostShare extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'post_id' => 'integer',
            'user_id' => 'integer',
            'is_valid' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
