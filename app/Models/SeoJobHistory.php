<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $job_name
 * @property string $queue
 * @property string $status
 * @property int $processed_count
 * @property int $error_count
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $context
 * @property string|null $error_message
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'job_name', 'queue', 'status', 'processed_count', 'error_count',
    'duration_ms', 'context', 'error_message', 'started_at', 'finished_at',
])]
class SeoJobHistory extends Model
{
    protected $table = 'seo_job_history';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_count' => 'integer',
            'error_count' => 'integer',
            'duration_ms' => 'integer',
            'context' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
