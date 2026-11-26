<?php

namespace App\Models;

use App\Enums\AiPromptType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property AiPromptType $prompt_type
 * @property int|null $agent_id
 * @property int|null $active_version_id
 * @property string $system_instruction
 * @property string $user_template
 * @property list<string>|null $allowed_variables
 * @property bool $is_enabled
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'slug', 'name', 'prompt_type', 'agent_id', 'active_version_id',
    'system_instruction', 'user_template', 'allowed_variables',
    'is_enabled', 'created_by',
])]
class AiPromptTemplate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_type' => AiPromptType::class,
            'agent_id' => 'integer',
            'active_version_id' => 'integer',
            'allowed_variables' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AiAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }

    /**
     * @return HasMany<AiPromptVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AiPromptVersion::class, 'template_id')->orderByDesc('version_number');
    }
}
