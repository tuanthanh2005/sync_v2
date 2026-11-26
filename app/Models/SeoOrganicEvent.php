<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $event_type
 * @property string|null $session_hash
 * @property int|null $user_id
 * @property string $source
 * @property string|null $search_engine
 * @property string|null $landing_path
 * @property string|null $locale
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'event_type', 'session_hash', 'user_id', 'source', 'search_engine',
    'landing_path', 'locale', 'entity_type', 'entity_id', 'metadata', 'occurred_at',
])]
class SeoOrganicEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'entity_id' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
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
