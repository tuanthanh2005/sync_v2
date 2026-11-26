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
 * @property string $display_name
 * @property string $username
 * @property string $ai_disclosure
 * @property string $system_prompt
 * @property string|null $personality
 * @property string|null $writing_style
 * @property string|null $expertise
 * @property string $default_language
 * @property list<string>|null $supported_languages
 * @property list<int>|null $assigned_community_ids
 * @property list<string>|null $allowed_topics
 * @property list<string>|null $restricted_topics
 * @property int|null $preferred_model_id
 * @property array<string, mixed>|null $posting_schedule
 * @property int $daily_post_limit
 * @property int $daily_comment_limit
 * @property int $monthly_token_budget
 * @property bool $is_enabled
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id', 'display_name', 'username', 'ai_disclosure', 'system_prompt',
    'personality', 'writing_style', 'expertise', 'default_language',
    'supported_languages', 'assigned_community_ids', 'allowed_topics',
    'restricted_topics', 'preferred_model_id', 'posting_schedule',
    'daily_post_limit', 'daily_comment_limit', 'monthly_token_budget',
    'is_enabled', 'created_by',
])]
class AiAgent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'preferred_model_id' => 'integer',
            'supported_languages' => 'array',
            'assigned_community_ids' => 'array',
            'allowed_topics' => 'array',
            'restricted_topics' => 'array',
            'posting_schedule' => 'array',
            'daily_post_limit' => 'integer',
            'daily_comment_limit' => 'integer',
            'monthly_token_budget' => 'integer',
            'is_enabled' => 'boolean',
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
     * @return BelongsTo<AiModel, $this>
     */
    public function preferredModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'preferred_model_id');
    }

    /**
     * @return HasMany<AiContentDraft, $this>
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(AiContentDraft::class, 'agent_id');
    }
}
