<?php

namespace App\Models;

use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use Carbon\CarbonInterface;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $community_id
 * @property PostCategory $category
 * @property string|null $title
 * @property string $content
 * @property string|null $link_url
 * @property array<string, string|null>|null $link_meta
 * @property PostVisibility $visibility
 * @property bool $is_pinned
 * @property bool $comments_locked
 * @property string|null $content_warning
 * @property ModerationStatus $moderation_status
 * @property bool $is_spam
 * @property string|null $moderation_reason
 * @property int|null $moderated_by
 * @property CarbonInterface|null $moderated_at
 * @property int $reactions_count
 * @property int $comments_count
 * @property int $shares_count
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'category',
    'title',
    'content',
    'link_url',
    'link_meta',
    'visibility',
    'published_at',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => PostCategory::class,
            'visibility' => PostVisibility::class,
            'moderation_status' => ModerationStatus::class,
            'link_meta' => 'array',
            'is_pinned' => 'boolean',
            'comments_locked' => 'boolean',
            'is_spam' => 'boolean',
            'moderated_at' => 'datetime',
            'reactions_count' => 'integer',
            'comments_count' => 'integer',
            'shares_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * @return BelongsToMany<Hashtag, $this>
     */
    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'post_hashtags');
    }

    /**
     * @return HasMany<PostMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('sort_order');
    }

    /**
     * @return HasOne<PostPoll, $this>
     */
    public function poll(): HasOne
    {
        return $this->hasOne(PostPoll::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<PostReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(PostReaction::class);
    }

    /**
     * @return HasMany<Bookmark, $this>
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    /**
     * @return HasMany<PostEditHistory, $this>
     */
    public function editHistories(): HasMany
    {
        return $this->hasMany(PostEditHistory::class)->latest('id');
    }

    /**
     * @return HasMany<AdCampaign, $this>
     */
    public function adCampaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopePublicVisible(Builder $query): Builder
    {
        return $query
            ->where('visibility', PostVisibility::Public->value)
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->whereNotNull('published_at');
    }
}
