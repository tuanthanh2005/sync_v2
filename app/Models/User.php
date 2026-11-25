<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property UserRole $role
 * @property UserStatus $status
 * @property string $timezone
 * @property string|null $social_provider
 * @property string|null $social_provider_id
 * @property string|null $avatar_url
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'password', 'timezone', 'social_provider', 'social_provider_id', 'avatar_url', 'email_verified_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function isModerator(): bool
    {
        return $this->role === UserRole::Moderator;
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }

    /**
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<Bookmark, $this>
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    /**
     * @return HasMany<Follow, $this>
     */
    public function followingRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    /**
     * @return HasMany<Follow, $this>
     */
    public function followerRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'following_id');
    }

    /**
     * @return HasMany<UserBlock, $this>
     */
    public function blockedRelations(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocker_id');
    }

    /**
     * @return HasMany<UserBlock, $this>
     */
    public function blockedByRelations(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocked_id');
    }

    /**
     * @return HasOne<AdCreditAccount, $this>
     */
    public function creditAccount(): HasOne
    {
        return $this->hasOne(AdCreditAccount::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<AdCampaign, $this>
     */
    public function adCampaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    public function avatarUrl(): ?string
    {
        return $this->profile?->avatarUrl();
    }
}
