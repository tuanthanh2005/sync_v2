<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $post_id
 * @property int|null $user_id
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int|null $width
 * @property int|null $height
 * @property int $size_bytes
 * @property string|null $alt_text
 * @property int $sort_order
 * @property string $moderation_status
 * @property string|null $moderation_reason
 * @property int|null $moderated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['disk', 'path', 'mime_type', 'width', 'height', 'size_bytes', 'alt_text', 'sort_order'])]
class PostMedia extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function url(): string
    {
        return Storage::disk($this->disk ?: (string) config('syncviet.media_disk', 'public'))->url($this->path);
    }
}
