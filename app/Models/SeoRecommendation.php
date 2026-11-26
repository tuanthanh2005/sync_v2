<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $type
 * @property string $priority
 * @property string|null $locale
 * @property int|null $url_registry_id
 * @property string|null $query
 * @property string $title
 * @property string $description
 * @property array<string, mixed>|null $metrics
 * @property string $status
 * @property string $fingerprint
 * @property int|null $dismissed_by
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'type', 'priority', 'locale', 'url_registry_id', 'query',
    'title', 'description', 'metrics', 'status', 'fingerprint',
    'dismissed_by', 'resolved_at',
])]
class SeoRecommendation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'url_registry_id' => 'integer',
            'metrics' => 'array',
            'resolved_at' => 'datetime',
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
