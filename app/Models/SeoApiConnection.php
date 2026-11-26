<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $provider
 * @property string $auth_type
 * @property string|null $property_identifier
 * @property string $status
 * @property string|null $verification_method
 * @property string|null $verification_token
 * @property CarbonInterface|null $verified_at
 * @property string|null $credentials_encrypted
 * @property string|null $access_token_encrypted
 * @property string|null $refresh_token_encrypted
 * @property CarbonInterface|null $token_expires_at
 * @property list<string>|null $scopes
 * @property array<string, mixed>|null $settings
 * @property CarbonInterface|null $last_synced_at
 * @property string|null $last_error
 * @property int|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'provider', 'auth_type', 'property_identifier', 'status',
    'verification_method', 'verification_token', 'verified_at',
    'token_expires_at', 'scopes', 'settings', 'last_synced_at',
    'last_error', 'updated_by',
])]
#[Hidden(['credentials_encrypted', 'access_token_encrypted', 'refresh_token_encrypted'])]
class SeoApiConnection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'scopes' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasCredentials(): bool
    {
        return is_string($this->credentials_encrypted) && $this->credentials_encrypted !== '';
    }

    public function hasAccessToken(): bool
    {
        return is_string($this->access_token_encrypted) && $this->access_token_encrypted !== '';
    }
}
