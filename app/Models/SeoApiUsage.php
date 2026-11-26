<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property string $endpoint
 * @property CarbonInterface $usage_date
 * @property int $requests_count
 * @property int $errors_count
 * @property int $quota_limit
 * @property CarbonInterface|null $last_called_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'provider', 'endpoint', 'usage_date', 'requests_count',
    'errors_count', 'quota_limit', 'last_called_at',
])]
class SeoApiUsage extends Model
{
    protected $table = 'seo_api_usage';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'requests_count' => 'integer',
            'errors_count' => 'integer',
            'quota_limit' => 'integer',
            'last_called_at' => 'datetime',
        ];
    }
}
