<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $provider_id
 * @property int|null $credential_id
 * @property int|null $model_id
 * @property int|null $agent_id
 * @property string|null $project_id
 * @property string $task_type
 * @property string|null $language_code
 * @property string $status
 * @property int|null $http_status
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $thinking_tokens
 * @property int $cached_tokens
 * @property int $tool_calls
 * @property int $duration_ms
 * @property int $estimated_cost_micro_usd
 * @property bool $pricing_verified
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonInterface $created_at
 */
#[Fillable([
    'provider_id', 'credential_id', 'model_id', 'agent_id', 'project_id',
    'task_type', 'language_code', 'status', 'http_status', 'input_tokens',
    'output_tokens', 'thinking_tokens', 'cached_tokens', 'tool_calls',
    'duration_ms', 'estimated_cost_micro_usd', 'pricing_verified',
    'error_code', 'error_message', 'created_at',
])]
class AiUsageRecord extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_id' => 'integer',
            'credential_id' => 'integer',
            'model_id' => 'integer',
            'agent_id' => 'integer',
            'http_status' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'thinking_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'tool_calls' => 'integer',
            'duration_ms' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'pricing_verified' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiCredential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(AiCredential::class, 'credential_id');
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /**
     * @return BelongsTo<AiAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }
}
