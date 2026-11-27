<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_token
 * @property string $name
 * @property string $username
 * @property string $email
 * @property string $normalized_email
 * @property string|null $canonical_fingerprint
 * @property string $password_hash
 * @property string $timezone
 * @property string $preferred_locale
 * @property string|null $referral_code
 * @property array<string, mixed>|null $acquisition_metadata
 * @property string|null $ip_address
 * @property string|null $user_agent_hash
 * @property int $risk_score
 * @property array<string, mixed>|null $risk_signals
 * @property string $status
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $completed_at
 * @property int|null $created_user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'public_token',
    'name',
    'username',
    'email',
    'normalized_email',
    'canonical_fingerprint',
    'password_hash',
    'timezone',
    'preferred_locale',
    'referral_code',
    'acquisition_metadata',
    'ip_address',
    'user_agent_hash',
    'risk_score',
    'risk_signals',
    'status',
    'expires_at',
    'completed_at',
    'created_user_id',
])]
#[Hidden(['password_hash'])]
class PendingRegistration extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acquisition_metadata' => 'array',
            'risk_signals' => 'array',
            'risk_score' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AuthVerificationChallenge, $this>
     */
    public function challenges(): HasMany
    {
        return $this->hasMany(AuthVerificationChallenge::class, 'pending_registration_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function isPendingAndValid(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }
}
