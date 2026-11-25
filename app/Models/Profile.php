<?php

namespace App\Models;

use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $headline
 * @property string|null $bio
 * @property string|null $location
 * @property string|null $website_url
 * @property string|null $avatar_disk
 * @property string|null $avatar_path
 * @property string|null $cover_disk
 * @property string|null $cover_path
 * @property bool $is_verified_badge
 * @property int $followers_count
 * @property int $following_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['headline', 'bio', 'location', 'website_url'])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_verified_badge' => 'boolean',
            'followers_count' => 'integer',
            'following_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
