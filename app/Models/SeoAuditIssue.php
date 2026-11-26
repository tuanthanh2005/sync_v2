<?php

namespace App\Models;

use App\Enums\SeoAuditSeverity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $audit_run_id
 * @property int|null $url_registry_id
 * @property string $check_code
 * @property SeoAuditSeverity $severity
 * @property string|null $url
 * @property string|null $locale
 * @property string $title
 * @property string $description
 * @property string|null $recommendation
 * @property string $status
 * @property string $fingerprint
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'audit_run_id', 'url_registry_id', 'check_code', 'severity', 'url', 'locale',
    'title', 'description', 'recommendation', 'status', 'fingerprint', 'resolved_at',
])]
class SeoAuditIssue extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audit_run_id' => 'integer',
            'url_registry_id' => 'integer',
            'severity' => SeoAuditSeverity::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SeoAuditRun, $this>
     */
    public function auditRun(): BelongsTo
    {
        return $this->belongsTo(SeoAuditRun::class, 'audit_run_id');
    }

    /**
     * @return BelongsTo<SeoUrlRegistry, $this>
     */
    public function urlRegistry(): BelongsTo
    {
        return $this->belongsTo(SeoUrlRegistry::class, 'url_registry_id');
    }
}
