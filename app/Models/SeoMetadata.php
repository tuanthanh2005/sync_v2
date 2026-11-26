<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $entity_type
 * @property int|null $entity_id
 * @property string $locale
 * @property string|null $path_hash
 * @property string|null $path
 * @property string|null $title
 * @property string|null $description
 * @property string|null $canonical_url
 * @property string|null $robots
 * @property string|null $og_title
 * @property string|null $og_description
 * @property string|null $og_image
 * @property string|null $og_type
 * @property string|null $twitter_card
 * @property string|null $schema_type
 * @property array<string, mixed>|null $custom_json_ld
 * @property bool $is_locked
 * @property int|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'entity_type', 'entity_id', 'locale', 'path_hash', 'path',
    'title', 'description', 'canonical_url', 'robots',
    'og_title', 'og_description', 'og_image', 'og_type',
    'twitter_card', 'schema_type', 'custom_json_ld', 'is_locked', 'updated_by',
])]
class SeoMetadata extends Model
{
    protected $table = 'seo_metadata';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'custom_json_ld' => 'array',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
