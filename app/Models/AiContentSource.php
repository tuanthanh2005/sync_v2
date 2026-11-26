<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $url
 * @property string $url_hash
 * @property string|null $publisher
 * @property string $source_type
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $retrieved_at
 * @property string $verification_status
 * @property string|null $excerpt
 * @property string|null $content_hash
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'title', 'url', 'url_hash', 'publisher', 'source_type',
    'published_at', 'retrieved_at', 'verification_status',
    'excerpt', 'content_hash', 'created_by',
])]
class AiContentSource extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'retrieved_at' => 'datetime',
        ];
    }
}
