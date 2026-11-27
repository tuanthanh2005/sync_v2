<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $event_type
 * @property string $severity
 * @property int|null $user_id
 * @property string|null $email_hash
 * @property string|null $email_masked
 * @property string|null $ip_address
 * @property int $risk_score
 * @property array<string, mixed>|null $details
 * @property CarbonInterface $created_at
 */
#[Fillable([
    'event_type',
    'severity',
    'user_id',
    'email_hash',
    'email_masked',
    'ip_address',
    'risk_score',
    'details',
    'created_at',
])]
class AuthSecurityEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'risk_score' => 'integer',
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
