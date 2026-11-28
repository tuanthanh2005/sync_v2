<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $post_id
 * @property CarbonInterface $created_at
 */
#[Fillable([
    'user_id',
    'post_id',
    'created_at',
])]
class UserHiddenPost extends Model
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
            'created_at' => 'datetime',
        ];
    }
}
