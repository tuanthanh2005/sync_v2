<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $url_registry_id
 * @property string $url
 * @property string $url_hash
 * @property string|null $content_hash
 * @property string $endpoint
 * @property string $status
 * @property int|null $http_status
 * @property int $attempts
 * @property string|null $response_excerpt
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'url_registry_id', 'url', 'url_hash', 'content_hash', 'endpoint',
    'status', 'http_status', 'attempts', 'response_excerpt', 'submitted_at',
])]
class SeoIndexNowSubmission extends Model
{
    protected $table = 'seo_indexnow_submissions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'url_registry_id' => 'integer',
            'http_status' => 'integer',
            'attempts' => 'integer',
            'submitted_at' => 'datetime',
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
