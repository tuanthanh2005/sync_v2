<?php

namespace App\Models;

use App\Enums\SeoEntityType;
use App\Enums\SeoIndexEligibility;
use App\Enums\SeoUrlStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $url_hash
 * @property string $url
 * @property string $path
 * @property SeoEntityType $entity_type
 * @property int|null $entity_id
 * @property string $locale
 * @property string|null $source_locale
 * @property string|null $canonical_url
 * @property SeoIndexEligibility $index_eligibility
 * @property SeoUrlStatus $status
 * @property string|null $exclusion_reason
 * @property int $http_status
 * @property int $priority_score
 * @property int $quality_score
 * @property bool $is_in_sitemap
 * @property string|null $sitemap_shard_key
 * @property string|null $content_hash
 * @property CarbonInterface|null $lastmod_at
 * @property CarbonInterface|null $last_crawled_at
 * @property CarbonInterface|null $last_inspected_at
 * @property string|null $inspection_verdict
 * @property string|null $inspection_coverage_state
 * @property CarbonInterface|null $indexnow_last_submitted_at
 * @property string|null $indexnow_last_hash
 * @property bool $is_noindex_forced
 * @property array<string, mixed>|null $metadata_snapshot
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'url_hash', 'url', 'path', 'entity_type', 'entity_id', 'locale', 'source_locale',
    'canonical_url', 'index_eligibility', 'status', 'exclusion_reason', 'http_status',
    'priority_score', 'quality_score', 'is_in_sitemap', 'sitemap_shard_key',
    'content_hash', 'lastmod_at', 'last_crawled_at', 'last_inspected_at',
    'inspection_verdict', 'inspection_coverage_state', 'indexnow_last_submitted_at',
    'indexnow_last_hash', 'is_noindex_forced', 'metadata_snapshot',
])]
class SeoUrlRegistry extends Model
{
    protected $table = 'seo_url_registry';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_type' => SeoEntityType::class,
            'entity_id' => 'integer',
            'index_eligibility' => SeoIndexEligibility::class,
            'status' => SeoUrlStatus::class,
            'http_status' => 'integer',
            'priority_score' => 'integer',
            'quality_score' => 'integer',
            'is_in_sitemap' => 'boolean',
            'is_noindex_forced' => 'boolean',
            'lastmod_at' => 'datetime',
            'last_crawled_at' => 'datetime',
            'last_inspected_at' => 'datetime',
            'indexnow_last_submitted_at' => 'datetime',
            'metadata_snapshot' => 'array',
        ];
    }

    /**
     * @return HasMany<SeoUrlInspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(SeoUrlInspection::class, 'url_registry_id')->latest('id');
    }

    /**
     * @return HasMany<SeoAuditIssue, $this>
     */
    public function auditIssues(): HasMany
    {
        return $this->hasMany(SeoAuditIssue::class, 'url_registry_id');
    }

    /**
     * @param  Builder<SeoUrlRegistry>  $query
     * @return Builder<SeoUrlRegistry>
     */
    public function scopeEligibleForSitemap(Builder $query): Builder
    {
        return $query
            ->where('index_eligibility', SeoIndexEligibility::Eligible->value)
            ->where('http_status', 200)
            ->where('is_noindex_forced', false);
    }
}
