<?php

namespace App\Models;

use App\Enums\CreditEntryType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property int|null $payment_id
 * @property int|null $ad_campaign_id
 * @property CreditEntryType $type
 * @property int $amount
 * @property int $balance_after
 * @property int $held_after
 * @property string $idempotency_key
 * @property string $description
 * @property CarbonInterface $created_at
 */
#[Fillable(['type', 'amount', 'balance_after', 'held_after', 'idempotency_key', 'description'])]
class AdCreditEntry extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditEntryType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'held_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AdCreditAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdCreditAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
