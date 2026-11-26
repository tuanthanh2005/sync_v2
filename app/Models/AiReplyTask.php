<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $post_id
 * @property int|null $parent_comment_id
 * @property int $agent_id
 * @property int|null $triggered_by_user_id
 * @property string $mode
 * @property string $language_code
 * @property string|null $draft_reply
 * @property int|null $published_comment_id
 * @property string $status
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property string|null $error_message
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'post_id', 'parent_comment_id', 'agent_id', 'triggered_by_user_id',
    'mode', 'language_code', 'draft_reply', 'published_comment_id',
    'status', 'reviewed_by', 'reviewed_at', 'error_message',
])]
class AiReplyTask extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'post_id' => 'integer',
            'parent_comment_id' => 'integer',
            'agent_id' => 'integer',
            'triggered_by_user_id' => 'integer',
            'published_comment_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<AiAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
