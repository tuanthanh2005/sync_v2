<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string|null $provider_reference
 * @property string $idempotency_key
 * @property int $amount
 * @property int $refunded_amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $refunded_at
 * @property string|null $refund_reason
 * @property int|null $refunded_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['provider', 'idempotency_key', 'amount', 'currency', 'metadata'])]
class Payment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'refunded_amount' => 'integer',
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
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
