<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $source_path_hash
 * @property string $source_path
 * @property string $target_url
 * @property int $status_code
 * @property bool $is_active
 * @property int $hit_count
 * @property CarbonInterface|null $last_hit_at
 * @property string|null $reason
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'source_path_hash', 'source_path', 'target_url', 'status_code',
    'is_active', 'hit_count', 'last_hit_at', 'reason', 'created_by',
])]
class SeoRedirect extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'is_active' => 'boolean',
            'hit_count' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
