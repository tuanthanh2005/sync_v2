<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $purpose
 * @property int|null $pending_registration_id
 * @property int|null $user_id
 * @property string $email
 * @property string $otp_hmac
 * @property string $link_token_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property int $resend_count
 * @property CarbonInterface|null $last_sent_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $link_expires_at
 * @property CarbonInterface|null $used_at
 * @property CarbonInterface|null $invalidated_at
 * @property string|null $ip_address
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'purpose',
    'pending_registration_id',
    'user_id',
    'email',
    'otp_hmac',
    'link_token_hash',
    'attempts',
    'max_attempts',
    'resend_count',
    'last_sent_at',
    'expires_at',
    'link_expires_at',
    'used_at',
    'invalidated_at',
    'ip_address',
    'meta',
])]
#[Hidden(['otp_hmac', 'link_token_hash'])]
class AuthVerificationChallenge extends Model
{
    public const PURPOSE_REGISTRATION = 'registration';

    public const PURPOSE_EMAIL_VERIFICATION = 'email_verification';

    public const PURPOSE_CHANGE_EMAIL = 'change_email';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'resend_count' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'link_expires_at' => 'datetime',
            'used_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PendingRegistration, $this>
     */
    public function pendingRegistration(): BelongsTo
    {
        return $this->belongsTo(PendingRegistration::class, 'pending_registration_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isUsableForOtp(): bool
    {
        return $this->used_at === null
            && $this->invalidated_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < $this->max_attempts;
    }

    public function isUsableForLink(): bool
    {
        $expiry = $this->link_expires_at ?? $this->expires_at;

        return $this->used_at === null
            && $this->invalidated_at === null
            && $expiry->isFuture();
    }
}
