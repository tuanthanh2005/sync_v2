<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $premium_plan_id
 * @property int|null $payment_id
 * @property SubscriptionStatus $status
 * @property string $source
 * @property string $currency
 * @property int $amount_paid
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $grace_ends_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $suspended_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $reminder_7d_sent_at
 * @property CarbonInterface|null $reminder_3d_sent_at
 * @property CarbonInterface|null $reminder_1d_sent_at
 * @property CarbonInterface|null $expired_notified_at
 * @property int|null $granted_by
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['reason', 'metadata'])]
class Subscription extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'amount_paid' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'suspended_at' => 'datetime',
            'revoked_at' => 'datetime',
            'reminder_7d_sent_at' => 'datetime',
            'reminder_3d_sent_at' => 'datetime',
            'reminder_1d_sent_at' => 'datetime',
            'expired_notified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isCurrentlyActive(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && $this->expires_at->isFuture()
            && $this->suspended_at === null
            && $this->revoked_at === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PremiumPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PremiumPlan::class, 'premium_plan_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @return HasMany<SubscriptionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }
}
