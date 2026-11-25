<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property list<string> $supported_currencies
 * @property int $fee_percent_bps
 * @property int $fee_fixed_vnd
 * @property int $fee_fixed_usd_cents
 * @property bool $is_enabled
 * @property bool $is_live_ready
 * @property bool $requires_legal_clearance
 * @property array<string, mixed>|null $instructions
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'code',
    'name',
    'category',
    'supported_currencies',
    'fee_percent_bps',
    'fee_fixed_vnd',
    'fee_fixed_usd_cents',
    'is_enabled',
    'is_live_ready',
    'requires_legal_clearance',
    'instructions',
    'sort_order',
])]
class PaymentMethod extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supported_currencies' => 'array',
            'fee_percent_bps' => 'integer',
            'fee_fixed_vnd' => 'integer',
            'fee_fixed_usd_cents' => 'integer',
            'is_enabled' => 'boolean',
            'is_live_ready' => 'boolean',
            'requires_legal_clearance' => 'boolean',
            'instructions' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Compute integer fee in smallest currency unit (zero floats).
     */
    public function calculateFee(int $amount, string $currency): int
    {
        $percentFee = $this->fee_percent_bps > 0
            ? (int) intdiv($amount * $this->fee_percent_bps + 5000, 10000)
            : 0;
        $fixed = strtoupper($currency) === 'USD' ? $this->fee_fixed_usd_cents : $this->fee_fixed_vnd;

        return max(0, $percentFee + $fixed);
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supported_currencies, true);
    }
}
