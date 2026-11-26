<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $trigger_type
 * @property string $status
 * @property int $technical_health_score
 * @property int $urls_scanned
 * @property int $critical_count
 * @property int $high_count
 * @property int $medium_count
 * @property int $low_count
 * @property int $info_count
 * @property array<string, mixed>|null $summary
 * @property int|null $triggered_by
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'trigger_type', 'status', 'technical_health_score', 'urls_scanned',
    'critical_count', 'high_count', 'medium_count', 'low_count', 'info_count',
    'summary', 'triggered_by', 'started_at', 'finished_at',
])]
class SeoAuditRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'technical_health_score' => 'integer',
            'urls_scanned' => 'integer',
            'critical_count' => 'integer',
            'high_count' => 'integer',
            'medium_count' => 'integer',
            'low_count' => 'integer',
            'info_count' => 'integer',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SeoAuditIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(SeoAuditIssue::class, 'audit_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
