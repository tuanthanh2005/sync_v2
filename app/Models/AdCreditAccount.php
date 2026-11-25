<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $currency
 * @property int $balance
 * @property int $promo_balance
 * @property int $held_balance
 * @property int $total_deposited
 * @property int $total_spent
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['currency'])]
class AdCreditAccount extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'balance' => 0,
        'promo_balance' => 0,
        'held_balance' => 0,
        'total_deposited' => 0,
        'total_spent' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'promo_balance' => 'integer',
            'held_balance' => 'integer',
            'total_deposited' => 'integer',
            'total_spent' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AdCreditEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(AdCreditEntry::class, 'account_id');
    }
}
