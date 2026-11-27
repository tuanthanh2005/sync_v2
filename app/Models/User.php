<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Services\System\LanguageService;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property UserRole $role
 * @property AccountType $account_type
 * @property string|null $ai_disclosure
 * @property int|null $rbac_role_id
 * @property UserStatus $status
 * @property CarbonInterface|null $suspended_until
 * @property string|null $ban_reason
 * @property int $warnings_count
 * @property int $violations_count
 * @property int $trust_level
 * @property bool $can_post
 * @property bool $can_comment
 * @property bool $can_create_community
 * @property bool $can_message
 * @property bool $can_advertise
 * @property CarbonInterface|null $post_restricted_until
 * @property CarbonInterface|null $comment_restricted_until
 * @property CarbonInterface|null $community_restricted_until
 * @property CarbonInterface|null $message_restricted_until
 * @property CarbonInterface|null $ad_restricted_until
 * @property CarbonInterface|null $premium_until
 * @property CarbonInterface|null $premium_suspended_at
 * @property CarbonInterface|null $deactivated_at
 * @property string $timezone
 * @property string|null $preferred_locale
 * @property string|null $acquisition_source
 * @property string|null $acquisition_landing_url
 * @property string|null $acquisition_locale
 * @property int|null $referred_by_user_id
 * @property array<string, mixed>|null $notification_preferences
 * @property CarbonInterface|null $last_reengagement_sent_at
 * @property int $reengagement_stage
 * @property bool $is_test_account
 * @property CarbonInterface|null $last_login_at
 * @property CarbonInterface|null $last_activity_at
 * @property string|null $social_provider
 * @property string|null $social_provider_id
 * @property string|null $avatar_url
 * @property CarbonInterface|null $email_verified_at
 * @property string|null $password
 * @property bool $has_password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable(['name', 'username', 'email', 'password', 'has_password', 'timezone', 'preferred_locale', 'social_provider', 'social_provider_id', 'avatar_url', 'email_verified_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Resolve the recipient's preferred locale for emails and queued notifications.
     * Always validates against the platform allowlist and falls back to English.
     */
    public function preferredLocale(): string
    {
        $preferred = is_string($this->preferred_locale) ? trim($this->preferred_locale) : '';

        if ($preferred !== '' && in_array($preferred, LanguageService::allCodes(), true)) {
            return $preferred;
        }

        return (string) config('localization.fallback_locale', 'en');
    }

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::User->value,
        'account_type' => AccountType::Human->value,
        'status' => UserStatus::Active->value,
        'has_password' => true,
        'can_post' => true,
        'can_comment' => true,
        'can_create_community' => true,
        'can_message' => true,
        'can_advertise' => true,
        'warnings_count' => 0,
        'violations_count' => 0,
    ];

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
            'has_password' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'suspended_until' => 'datetime',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_reengagement_sent_at' => 'datetime',
            'reengagement_stage' => 'integer',
            'is_test_account' => 'boolean',
            'notification_preferences' => 'array',
            'post_restricted_until' => 'datetime',
            'comment_restricted_until' => 'datetime',
            'community_restricted_until' => 'datetime',
            'message_restricted_until' => 'datetime',
            'ad_restricted_until' => 'datetime',
            'premium_until' => 'datetime',
            'premium_suspended_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'warnings_count' => 'integer',
            'violations_count' => 'integer',
            'trust_level' => 'integer',
            'can_post' => 'boolean',
            'can_comment' => 'boolean',
            'can_create_community' => 'boolean',
            'can_message' => 'boolean',
            'can_advertise' => 'boolean',
            'role' => UserRole::class,
            'account_type' => AccountType::class,
            'status' => UserStatus::class,
        ];
    }

    public function isAiAgent(): bool
    {
        return ($this->attributes['account_type'] ?? AccountType::Human->value) === AccountType::AiAgent->value;
    }

    public function isHumanMember(): bool
    {
        return ($this->attributes['account_type'] ?? AccountType::Human->value) === AccountType::Human->value
            && ! (bool) ($this->attributes['is_test_account'] ?? false);
    }

    /**
     * @return HasOne<ReferralCode, $this>
     */
    public function referralCode(): HasOne
    {
        return $this->hasOne(ReferralCode::class);
    }

    /**
     * @return HasOne<EarlyMember, $this>
     */
    public function earlyMember(): HasOne
    {
        return $this->hasOne(EarlyMember::class);
    }

    /**
     * @return HasMany<UserBadge, $this>
     */
    public function badges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    /**
     * @return HasOne<OnboardingProgress, $this>
     */
    public function onboardingProgress(): HasOne
    {
        return $this->hasOne(OnboardingProgress::class);
    }

    /**
     * @return HasOne<AiAgent, $this>
     */
    public function aiAgent(): HasOne
    {
        return $this->hasOne(AiAgent::class, 'user_id');
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        if ($this->rbacRole?->slug === 'super_admin' || $this->rbacRole?->slug === 'admin') {
            return true;
        }

        return $this->role->isAdmin();
    }

    public function isSuperAdmin(): bool
    {
        return $this->rbacRole?->slug === 'super_admin';
    }

    public function isModerator(): bool
    {
        return $this->role === UserRole::Moderator;
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff() || ($this->rbacRole !== null && $this->rbacRole->slug !== 'user');
    }

    public function isActive(): bool
    {
        if ($this->deleted_at !== null || $this->deactivated_at !== null) {
            return false;
        }

        if (! array_key_exists('status', $this->attributes) || $this->attributes['status'] === null) {
            return false;
        }

        if ($this->status === UserStatus::Suspended && $this->suspended_until !== null && $this->suspended_until->isPast()) {
            return true;
        }

        return $this->status->canAuthenticate();
    }

    public function isRestrictedFrom(string $capability): bool
    {
        return match ($capability) {
            'post' => ! $this->can_post && ($this->post_restricted_until === null || $this->post_restricted_until->isFuture()),
            'comment' => ! $this->can_comment && ($this->comment_restricted_until === null || $this->comment_restricted_until->isFuture()),
            'community' => ! $this->can_create_community && ($this->community_restricted_until === null || $this->community_restricted_until->isFuture()),
            'message' => ! $this->can_message && ($this->message_restricted_until === null || $this->message_restricted_until->isFuture()),
            'advertise' => ! $this->can_advertise && ($this->ad_restricted_until === null || $this->ad_restricted_until->isFuture()),
            default => false,
        };
    }

    public function hierarchyLevel(): int
    {
        if ($this->rbacRole !== null) {
            return $this->rbacRole->hierarchy_level;
        }

        return match ($this->role) {
            UserRole::Admin => 80,
            UserRole::Moderator => 40,
            UserRole::User => 0,
        };
    }

    public function hasPermission(string $permissionSlug): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->rbacRole !== null) {
            if ($this->rbacRole->slug === 'super_admin') {
                return true;
            }

            return $this->rbacRole->permissions->contains('slug', $permissionSlug);
        }

        if ($this->role === UserRole::Admin) {
            return true;
        }

        if ($this->role === UserRole::Moderator) {
            return in_array($permissionSlug, [
                'dashboard.view',
                'analytics.view',
                'users.view',
                'users.suspend',
                'posts.view',
                'posts.update',
                'posts.delete',
                'comments.view',
                'comments.update',
                'comments.delete',
                'communities.view',
                'communities.update',
                'polls.view',
                'polls.update',
                'hashtags.view',
                'hashtags.update',
                'media.view',
                'media.update',
                'moderation.view',
                'moderation.approve',
                'moderation.reject',
                'moderation.manage',
                'ads.view',
                'ads.approve',
                'ads.reject',
            ], true);
        }

        return false;
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function rbacRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'rbac_role_id');
    }

    /**
     * @return HasMany<UserSanction, $this>
     */
    public function sanctions(): HasMany
    {
        return $this->hasMany(UserSanction::class);
    }

    /**
     * @return HasMany<VerificationRequest, $this>
     */
    public function verificationRequests(): HasMany
    {
        return $this->hasMany(VerificationRequest::class);
    }

    /**
     * @return HasMany<Appeal, $this>
     */
    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class);
    }

    /**
     * @return HasMany<LoginHistory, $this>
     */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
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

    public function isPremium(): bool
    {
        if ($this->premium_until === null || ! $this->premium_until->isFuture() || $this->premium_suspended_at !== null) {
            return false;
        }

        if (array_key_exists('status', $this->attributes)) {
            return $this->isActive();
        }

        return true;
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<BannerCampaign, $this>
     */
    public function bannerCampaigns(): HasMany
    {
        return $this->hasMany(BannerCampaign::class);
    }

    public function avatarUrl(): ?string
    {
        return $this->profile?->avatarUrl() ?? $this->avatar_url;
    }
}
