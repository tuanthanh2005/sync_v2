<?php

namespace App\Models;

use App\Enums\ContentOrigin;
use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\PostLifecycleStatus;
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
 * @property string|null $slug
 * @property string $language_code
 * @property string|null $content_fingerprint
 * @property string|null $link_fingerprint
 * @property string $content
 * @property string|null $link_url
 * @property array<string, string|null>|null $link_meta
 * @property PostVisibility $visibility
 * @property bool $is_pinned
 * @property bool $comments_locked
 * @property string|null $content_warning
 * @property string|null $language_code
 * @property ModerationStatus $moderation_status
 * @property PostLifecycleStatus|null $lifecycle_status
 * @property bool $is_spam
 * @property int $spam_risk_score
 * @property bool $is_ai_assisted
 * @property ContentOrigin $content_origin
 * @property int|null $ai_agent_id
 * @property int|null $ai_draft_id
 * @property bool $seo_noindex
 * @property int|null $seo_quality_score
 * @property string|null $moderation_reason
 * @property int|null $moderated_by
 * @property CarbonInterface|null $moderated_at
 * @property int $reactions_count
 * @property int $comments_count
 * @property int $shares_count
 * @property int $bookmarks_count
 * @property int $valid_reactions_count
 * @property int $valid_comments_count
 * @property int $valid_bookmarks_count
 * @property int $valid_shares_count
 * @property float $trending_raw_score
 * @property float $trending_score
 * @property CarbonInterface|null $trending_calculated_at
 * @property bool $needs_rerank
 * @property int $views_count
 * @property int $organic_views_count
 * @property int $promoted_views_count
 * @property int $unique_viewers_count
 * @property int $human_views_count
 * @property int $guest_views_count
 * @property int $ai_views_count
 * @property int $ai_content_accesses_count
 * @property int $legacy_views_count
 * @property int $invalid_views_count
 * @property int $unique_human_viewers_count
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $edited_at
 * @property int $edit_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'category',
    'title',
    'slug',
    'language_code',
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
     * @var array<string, mixed>
     */
    protected $attributes = [
        'moderation_status' => ModerationStatus::Approved->value,
        'lifecycle_status' => PostLifecycleStatus::Published->value,
        'is_spam' => false,
        'spam_risk_score' => 0,
        'needs_rerank' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => PostCategory::class,
            'visibility' => PostVisibility::class,
            'moderation_status' => ModerationStatus::class,
            'lifecycle_status' => PostLifecycleStatus::class,
            'link_meta' => 'array',
            'is_pinned' => 'boolean',
            'comments_locked' => 'boolean',
            'is_spam' => 'boolean',
            'spam_risk_score' => 'integer',
            'is_ai_assisted' => 'boolean',
            'content_origin' => ContentOrigin::class,
            'ai_agent_id' => 'integer',
            'ai_draft_id' => 'integer',
            'seo_noindex' => 'boolean',
            'seo_quality_score' => 'integer',
            'moderated_at' => 'datetime',
            'reactions_count' => 'integer',
            'comments_count' => 'integer',
            'shares_count' => 'integer',
            'bookmarks_count' => 'integer',
            'valid_reactions_count' => 'integer',
            'valid_comments_count' => 'integer',
            'valid_bookmarks_count' => 'integer',
            'valid_shares_count' => 'integer',
            'trending_raw_score' => 'float',
            'trending_score' => 'float',
            'trending_calculated_at' => 'datetime',
            'needs_rerank' => 'boolean',
            'views_count' => 'integer',
            'organic_views_count' => 'integer',
            'promoted_views_count' => 'integer',
            'unique_viewers_count' => 'integer',
            'human_views_count' => 'integer',
            'guest_views_count' => 'integer',
            'ai_views_count' => 'integer',
            'ai_content_accesses_count' => 'integer',
            'legacy_views_count' => 'integer',
            'invalid_views_count' => 'integer',
            'unique_human_viewers_count' => 'integer',
            'published_at' => 'datetime',
            'edited_at' => 'datetime',
            'edit_count' => 'integer',
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
            ->where('is_spam', false)
            ->where('lifecycle_status', PostLifecycleStatus::Published->value)
            ->whereNotNull('published_at');
    }
}
