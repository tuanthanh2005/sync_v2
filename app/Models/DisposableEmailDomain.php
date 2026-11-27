<?php

namespace App\Models;

use App\Services\Auth\DisposableEmailService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $domain
 * @property bool $is_active
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['domain', 'is_active', 'notes'])]
class DisposableEmailDomain extends Model
{
    protected static function booted(): void
    {
        static::saved(static function (): void {
            Cache::forget(DisposableEmailService::CACHE_KEY);
        });

        static::deleted(static function (): void {
            Cache::forget(DisposableEmailService::CACHE_KEY);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
