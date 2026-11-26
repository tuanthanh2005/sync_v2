<?php

namespace App\Models;

use App\Enums\AiModelGroup;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $provider_id
 * @property string $model_id
 * @property string $display_name
 * @property AiModelGroup $role_group
 * @property list<string>|null $capabilities
 * @property int $max_input_tokens
 * @property int $max_output_tokens
 * @property float $default_temperature
 * @property bool $supports_thinking
 * @property bool $supports_structured_output
 * @property bool $supports_grounding
 * @property bool $is_paid_only
 * @property int $input_price_per_million_cents
 * @property int $output_price_per_million_cents
 * @property bool $pricing_verified
 * @property bool $is_enabled
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'provider_id', 'model_id', 'display_name', 'role_group', 'capabilities',
    'max_input_tokens', 'max_output_tokens', 'default_temperature',
    'supports_thinking', 'supports_structured_output', 'supports_grounding',
    'is_paid_only', 'input_price_per_million_cents', 'output_price_per_million_cents',
    'pricing_verified', 'is_enabled',
])]
class AiModel extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_id' => 'integer',
            'role_group' => AiModelGroup::class,
            'capabilities' => 'array',
            'max_input_tokens' => 'integer',
            'max_output_tokens' => 'integer',
            'default_temperature' => 'float',
            'supports_thinking' => 'boolean',
            'supports_structured_output' => 'boolean',
            'supports_grounding' => 'boolean',
            'is_paid_only' => 'boolean',
            'input_price_per_million_cents' => 'integer',
            'output_price_per_million_cents' => 'integer',
            'pricing_verified' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /**
     * Estimate cost in integer micro-USD (1 USD = 1,000,000 micro-USD) to avoid float money drift.
     */
    public function estimateCostMicroUsd(int $inputTokens, int $outputTokens): int
    {
        if (! $this->pricing_verified || ($this->input_price_per_million_cents === 0 && $this->output_price_per_million_cents === 0)) {
            return 0;
        }

        // price_per_million_cents * 10_000 = micro-USD per million tokens => / 1_000_000 = price_per_million_cents / 100 per token
        $inMicro = (int) round(($inputTokens * $this->input_price_per_million_cents) / 100);
        $outMicro = (int) round(($outputTokens * $this->output_price_per_million_cents) / 100);

        return max(0, $inMicro + $outMicro);
    }
}
