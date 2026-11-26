<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sitemap_id
 * @property string $shard_key
 * @property int $shard_index
 * @property string $storage_path
 * @property int $url_count
 * @property int $byte_size
 * @property string|null $etag
 * @property bool $is_dirty
 * @property CarbonInterface|null $generated_at
 * @property CarbonInterface|null $lastmod_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'sitemap_id', 'shard_key', 'shard_index', 'storage_path',
    'url_count', 'byte_size', 'etag', 'is_dirty', 'generated_at', 'lastmod_at',
])]
class SeoSitemapShard extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sitemap_id' => 'integer',
            'shard_index' => 'integer',
            'url_count' => 'integer',
            'byte_size' => 'integer',
            'is_dirty' => 'boolean',
            'generated_at' => 'datetime',
            'lastmod_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SeoSitemap, $this>
     */
    public function sitemap(): BelongsTo
    {
        return $this->belongsTo(SeoSitemap::class, 'sitemap_id');
    }
}
