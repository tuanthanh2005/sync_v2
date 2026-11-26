<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $provider_id
 * @property string $project_id
 * @property string $model_id
 * @property string $window_type
 * @property string $window_key
 * @property int $requests_count
 * @property int $tokens_count
 * @property CarbonInterface|null $waiting_for_quota_until
 * @property CarbonInterface|null $last_rate_limited_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'provider_id', 'project_id', 'model_id', 'window_type', 'window_key',
    'requests_count', 'tokens_count', 'waiting_for_quota_until', 'last_rate_limited_at',
])]
class AiQuotaTracking extends Model
{
    protected $table = 'ai_quota_tracking';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_id' => 'integer',
            'requests_count' => 'integer',
            'tokens_count' => 'integer',
            'waiting_for_quota_until' => 'datetime',
            'last_rate_limited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
