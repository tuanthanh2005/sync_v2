<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $row_hash
 * @property CarbonInterface $date
 * @property string $property_url
 * @property string $page_url_hash
 * @property string $page_url
 * @property string $query
 * @property string $country
 * @property string $device
 * @property string|null $locale
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property int $clicks
 * @property int $impressions
 * @property float $ctr
 * @property float $position
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'row_hash', 'date', 'property_url', 'page_url_hash', 'page_url',
    'query', 'country', 'device', 'locale', 'entity_type', 'entity_id',
    'clicks', 'impressions', 'ctr', 'position',
])]
class SeoSearchAnalytic extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'entity_id' => 'integer',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'float',
            'position' => 'float',
        ];
    }
}
