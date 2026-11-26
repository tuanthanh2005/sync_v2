<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $url_registry_id
 * @property string $provider
 * @property string $inspection_status
 * @property string|null $verdict
 * @property string|null $coverage_state
 * @property string|null $indexing_state
 * @property string|null $page_fetch_state
 * @property string|null $robots_txt_state
 * @property string|null $google_canonical
 * @property string|null $user_canonical
 * @property string|null $mobile_usability_verdict
 * @property string|null $rich_results_verdict
 * @property CarbonInterface|null $last_crawl_time
 * @property array<string, mixed>|null $raw_summary
 * @property string|null $error_message
 * @property CarbonInterface|null $inspected_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'url_registry_id', 'provider', 'inspection_status', 'verdict', 'coverage_state',
    'indexing_state', 'page_fetch_state', 'robots_txt_state', 'google_canonical',
    'user_canonical', 'mobile_usability_verdict', 'rich_results_verdict',
    'last_crawl_time', 'raw_summary', 'error_message', 'inspected_at',
])]
class SeoUrlInspection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'url_registry_id' => 'integer',
            'last_crawl_time' => 'datetime',
            'inspected_at' => 'datetime',
            'raw_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SeoUrlRegistry, $this>
     */
    public function urlRegistry(): BelongsTo
    {
        return $this->belongsTo(SeoUrlRegistry::class, 'url_registry_id');
    }
}
