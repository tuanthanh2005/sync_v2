<?php

namespace App\Models;

use App\Enums\PostCategory;
use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string $slug
 * @property string $language_code
 * @property PostCategory $category
 * @property string|null $description
 * @property string|null $avatar_disk
 * @property string|null $avatar_path
 * @property string|null $cover_disk
 * @property string|null $cover_path
 * @property list<string>|null $rules
 * @property string $visibility
 * @property string $status
 * @property bool $is_verified_badge
 * @property bool $is_featured
 * @property bool $seo_noindex
 * @property bool $allow_new_members
 * @property bool $allow_posting
 * @property string|null $moderation_reason
 * @property int $members_count
 * @property int $posts_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'slug', 'language_code', 'category', 'description', 'rules', 'visibility'])]
class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => PostCategory::class,
            'rules' => 'array',
            'is_verified_badge' => 'boolean',
            'is_featured' => 'boolean',
            'seo_noindex' => 'boolean',
            'allow_new_members' => 'boolean',
            'allow_posting' => 'boolean',
            'members_count' => 'integer',
            'posts_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<CommunityMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CommunityMember::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function avatarUrl(): ?string
    {
        if (! is_string($this->avatar_path) || $this->avatar_path === '') {
            return null;
        }

        $disk = $this->avatar_disk ?: (string) config('syncviet.media_disk', 'public');

        return Storage::disk($disk)->url($this->avatar_path);
    }

    public function coverUrl(): ?string
    {
        if (! is_string($this->cover_path) || $this->cover_path === '') {
            return null;
        }

        $disk = $this->cover_disk ?: (string) config('syncviet.media_disk', 'public');

        return Storage::disk($disk)->url($this->cover_path);
    }
}
