<?php

namespace App\Models;

use App\Enums\AiDraftStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $agent_id
 * @property int|null $plan_id
 * @property int|null $community_id
 * @property int|null $prompt_template_id
 * @property int|null $prompt_version_number
 * @property int|null $model_id
 * @property int|null $credential_id
 * @property int|null $source_id
 * @property int|null $published_post_id
 * @property string $title
 * @property string $body
 * @property string|null $summary
 * @property string $language_code
 * @property string $content_type
 * @property string $category
 * @property list<string>|null $suggested_tags
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property list<array<string, mixed>>|null $source_references
 * @property list<string>|null $factual_claims
 * @property list<string>|null $review_flags
 * @property string|null $content_hash
 * @property string $quality_status
 * @property int $quality_score
 * @property array<string, mixed>|null $quality_report
 * @property AiDraftStatus $status
 * @property string|null $idempotency_key
 * @property CarbonInterface|null $scheduled_for
 * @property CarbonInterface|null $published_at
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property string|null $review_notes
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $thinking_tokens
 * @property int $estimated_cost_micro_usd
 * @property string|null $error_message
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'agent_id', 'plan_id', 'community_id', 'prompt_template_id', 'prompt_version_number',
    'model_id', 'credential_id', 'source_id', 'published_post_id', 'title', 'body',
    'summary', 'language_code', 'content_type', 'category', 'suggested_tags',
    'seo_title', 'seo_description', 'source_references', 'factual_claims',
    'review_flags', 'content_hash', 'quality_status', 'quality_score', 'quality_report',
    'status', 'idempotency_key', 'scheduled_for', 'published_at', 'reviewed_by',
    'reviewed_at', 'review_notes', 'input_tokens', 'output_tokens', 'thinking_tokens',
    'estimated_cost_micro_usd', 'error_message',
])]
class AiContentDraft extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'plan_id' => 'integer',
            'community_id' => 'integer',
            'prompt_template_id' => 'integer',
            'prompt_version_number' => 'integer',
            'model_id' => 'integer',
            'credential_id' => 'integer',
            'source_id' => 'integer',
            'published_post_id' => 'integer',
            'suggested_tags' => 'array',
            'source_references' => 'array',
            'factual_claims' => 'array',
            'review_flags' => 'array',
            'quality_score' => 'integer',
            'quality_report' => 'array',
            'status' => AiDraftStatus::class,
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'thinking_tokens' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
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
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /**
     * @return BelongsTo<AiContentSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(AiContentSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function publishedPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'published_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<AiContentReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(AiContentReview::class, 'draft_id')->latest('id');
    }
}
