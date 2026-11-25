<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int $user_id
 * @property int|null $payment_id
 * @property int|null $actor_id
 * @property string $event_type
 * @property int $days_delta
 * @property CarbonInterface|null $previous_expires_at
 * @property CarbonInterface|null $new_expires_at
 * @property string|null $note
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface $created_at
 */
#[Fillable(['event_type', 'days_delta', 'previous_expires_at', 'new_expires_at', 'note', 'metadata'])]
class SubscriptionEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days_delta' => 'integer',
            'previous_expires_at' => 'datetime',
            'new_expires_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
