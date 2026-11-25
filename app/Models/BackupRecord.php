<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $created_by
 * @property string $type
 * @property string $disk
 * @property string $path
 * @property int $size_bytes
 * @property string $checksum
 * @property string $status
 * @property string|null $notes
 * @property CarbonInterface $created_at
 */
#[Fillable(['type', 'disk', 'path', 'size_bytes', 'checksum', 'status', 'notes'])]
class BackupRecord extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
