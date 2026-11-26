<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $driver
 * @property string $base_url
 * @property bool $is_enabled
 * @property string $compliance_status
 * @property string|null $compliance_notes
 * @property array<string, mixed>|null $config
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['slug', 'name', 'driver', 'base_url', 'is_enabled', 'compliance_status', 'compliance_notes', 'config'])]
class AiProvider extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    /**
     * @return HasMany<AiCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(AiCredential::class, 'provider_id');
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }
}
