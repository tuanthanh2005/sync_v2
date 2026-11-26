<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $locale
 * @property int $total_urls
 * @property int $total_shards
 * @property string $status
 * @property bool $is_dirty
 * @property string|null $etag
 * @property CarbonInterface|null $last_generated_at
 * @property CarbonInterface|null $gsc_submitted_at
 * @property string|null $gsc_status
 * @property int $gsc_errors
 * @property int $gsc_warnings
 * @property string|null $last_error
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'name', 'type', 'locale', 'total_urls', 'total_shards', 'status', 'is_dirty',
    'etag', 'last_generated_at', 'gsc_submitted_at', 'gsc_status',
    'gsc_errors', 'gsc_warnings', 'last_error',
])]
class SeoSitemap extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_urls' => 'integer',
            'total_shards' => 'integer',
            'is_dirty' => 'boolean',
            'gsc_errors' => 'integer',
            'gsc_warnings' => 'integer',
            'last_generated_at' => 'datetime',
            'gsc_submitted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SeoSitemapShard, $this>
     */
    public function shards(): HasMany
    {
        return $this->hasMany(SeoSitemapShard::class, 'sitemap_id')->orderBy('shard_index');
    }
}
