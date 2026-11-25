<?php

namespace App\Models;

use App\Enums\PaymentServiceType;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $order_code
 * @property int $user_id
 * @property PaymentServiceType $service_type
 * @property string|null $payable_type
 * @property int|null $payable_id
 * @property string $provider
 * @property string|null $provider_reference
 * @property string $idempotency_key
 * @property int $amount
 * @property int $fee_amount
 * @property int $refunded_amount
 * @property string $currency
 * @property int|null $exchange_rate_snapshot
 * @property PaymentStatus $status
 * @property string $platform
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $cancelled_at
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
     * @var array<string, mixed>
     */
    protected $attributes = [
        'service_type' => 'topup',
        'fee_amount' => 0,
        'refunded_amount' => 0,
        'platform' => 'web',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_type' => PaymentServiceType::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'refunded_amount' => 'integer',
            'exchange_rate_snapshot' => 'integer',
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function totalAmount(): int
    {
        return $this->amount + $this->fee_amount;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
