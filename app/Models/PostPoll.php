<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property string $question
 * @property Carbon|null $ends_at
 * @property bool $allows_multiple
 * @property bool $is_closed
 * @property string $moderation_status
 * @property string|null $moderation_reason
 * @property int $total_votes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['question', 'ends_at', 'allows_multiple'])]
class PostPoll extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
            'allows_multiple' => 'boolean',
            'is_closed' => 'boolean',
            'total_votes' => 'integer',
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
     * @return HasMany<PostPollOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(PostPollOption::class, 'poll_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<PostPollVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(PostPollVote::class, 'poll_id');
    }

    public function isExpired(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }
}
