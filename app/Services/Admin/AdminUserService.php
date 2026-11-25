<?php

namespace App\Services\Admin;

use App\Enums\SanctionType;
use App\Enums\UserStatus;
use App\Models\AdCampaign;
use App\Models\AuditLog;
use App\Models\BannerCampaign;
use App\Models\Comment;
use App\Models\CommunityMember;
use App\Models\Post;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSanction;
use App\Models\VerificationRequest;
use App\Notifications\SocialActivityNotification;
use App\Services\Security\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{filter?: string, search?: string, role?: string, sort?: string, dir?: string}  $filters
     * @return array<string, mixed>
     */
    public function listPayload(array $filters): array
    {
        $filter = (string) ($filters['filter'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));
        $roleFilter = trim((string) ($filters['role'] ?? ''));
        $sortRaw = (string) ($filters['sort'] ?? 'created_at');
        $sort = in_array($sortRaw, ['id', 'name', 'created_at', 'last_login_at', 'violations_count'], true)
            ? $sortRaw
            : 'created_at';
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = User::query()
            ->with(['profile:id,user_id,avatar_disk,avatar_path,is_verified_badge', 'rbacRole:id,slug,name,hierarchy_level'])
            ->withCount(['posts', 'comments']);

        if ($filter === 'deleted') {
            $query->onlyTrashed();
        } else {
            $query->withTrashed();
        }

        if ($search !== '') {
            $escaped = addcslashes($search, '\\%_');
            $pattern = "%{$escaped}%";
            $query->where(static function (Builder $q) use ($pattern, $search): void {
                $q->where('name', 'like', $pattern)
                    ->orWhere('username', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern);

                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        match ($filter) {
            'active' => $query->where('status', UserStatus::Active->value)->whereNull('deleted_at')->whereNull('deactivated_at'),
            'suspended' => $query->where('status', UserStatus::Suspended->value)->whereNull('deleted_at'),
            'banned' => $query->where('status', UserStatus::Banned->value)->whereNull('deleted_at'),
            'unverified' => $query->whereNull('email_verified_at')->whereNull('deleted_at'),
            default => null,
        };

        if ($roleFilter !== '') {
            $query->where(static function (Builder $q) use ($roleFilter): void {
                $q->where('role', $roleFilter)
                    ->orWhereHas('rbacRole', static fn (Builder $rq) => $rq->where('slug', $roleFilter));
            });
        }

        $paginator = $query->orderBy($sort, $dir)->paginate(20)->withQueryString();

        $items = array_values(
            collect($paginator->items())->map(static fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'email' => $u->email,
                'avatar' => $u->avatarUrl(),
                'role' => $u->role->value,
                'role_label' => $u->rbacRole !== null ? $u->rbacRole->name : $u->role->label(),
                'rbac_role' => $u->rbacRole !== null ? $u->rbacRole->slug : $u->role->value,
                'hierarchy_level' => $u->hierarchyLevel(),
                'status' => $u->status->value,
                'status_label' => $u->status->label(),
                'is_verified_badge' => $u->profile !== null && $u->profile->is_verified_badge,
                'email_verified' => $u->email_verified_at !== null,
                'two_factor_enabled' => $u->hasEnabledTwoFactorAuthentication(),
                'posts_count' => (int) ($u->getAttribute('posts_count') ?? 0),
                'comments_count' => (int) ($u->getAttribute('comments_count') ?? 0),
                'warnings_count' => $u->warnings_count,
                'violations_count' => $u->violations_count,
                'suspended_until' => $u->suspended_until?->toIso8601String(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'last_activity_at' => $u->last_activity_at?->toIso8601String(),
                'deactivated_at' => $u->deactivated_at?->toIso8601String(),
                'deleted_at' => $u->deleted_at?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
            ])->all(),
        );

        $roles = Role::query()->orderByDesc('hierarchy_level')->get(['id', 'slug', 'name', 'hierarchy_level'])->all();

        return [
            'users' => [
                'data' => $items,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'counts' => [
                'all' => User::query()->withTrashed()->count(),
                'active' => User::query()->where('status', UserStatus::Active->value)->whereNull('deactivated_at')->count(),
                'suspended' => User::query()->where('status', UserStatus::Suspended->value)->count(),
                'banned' => User::query()->where('status', UserStatus::Banned->value)->count(),
                'unverified' => User::query()->whereNull('email_verified_at')->count(),
                'deleted' => User::query()->onlyTrashed()->count(),
            ],
            'roles' => $roles,
            'filters' => [
                'filter' => $filter,
                'search' => $search,
                'role' => $roleFilter,
                'sort' => $sort,
                'dir' => $dir,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPayload(User $target): array
    {
        $target->loadMissing(['profile', 'rbacRole', 'creditAccount']);

        $sessions = Schema::hasTable('sessions')
            ? DB::table('sessions')
                ->where('user_id', $target->id)
                ->orderByDesc('last_activity')
                ->limit(15)
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'ip_address' => (string) ($row->ip_address ?? '-'),
                    'user_agent' => Str::limit((string) ($row->user_agent ?? ''), 120),
                    'last_active_at' => CarbonImmutable::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                ])->values()->all()
            : [];

        $sanctions = UserSanction::query()
            ->where('user_id', $target->id)
            ->with(['actor:id,username', 'revoker:id,username'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(static fn (UserSanction $s): array => [
                'id' => $s->id,
                'type' => $s->type->value,
                'type_label' => $s->type->label(),
                'reason' => $s->reason,
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at?->toIso8601String(),
                'is_active' => $s->is_active,
                'actor_username' => $s->actor?->username,
                'revoked_at' => $s->revoked_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values()->all();

        $posts = Post::query()
            ->withTrashed()
            ->where('user_id', $target->id)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'title', 'content', 'moderation_status', 'reactions_count', 'comments_count', 'created_at', 'deleted_at'])
            ->map(static fn (Post $p): array => [
                'id' => $p->id,
                'title' => $p->title,
                'excerpt' => Str::limit($p->content, 120),
                'moderation_status' => $p->moderation_status->value,
                'reactions_count' => $p->reactions_count,
                'comments_count' => $p->comments_count,
                'created_at' => $p->created_at?->toIso8601String(),
                'deleted_at' => $p->deleted_at?->toIso8601String(),
            ])->values()->all();

        $comments = Comment::query()
            ->withTrashed()
            ->where('user_id', $target->id)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'post_id', 'content', 'moderation_status', 'created_at', 'deleted_at'])
            ->map(static fn (Comment $c): array => [
                'id' => $c->id,
                'post_id' => $c->post_id,
                'excerpt' => Str::limit($c->content, 120),
                'moderation_status' => $c->moderation_status->value,
                'created_at' => $c->created_at?->toIso8601String(),
                'deleted_at' => $c->deleted_at?->toIso8601String(),
            ])->values()->all();

        $communities = CommunityMember::query()
            ->where('user_id', $target->id)
            ->with(['community:id,name,slug'])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(static fn (CommunityMember $m): array => [
                'id' => $m->community_id,
                'name' => $m->community !== null ? $m->community->name : '#'.$m->community_id,
                'slug' => $m->community?->slug,
                'role' => $m->role->value,
                'points' => $m->points,
                'joined_at' => $m->joined_at->toIso8601String(),
            ])->values()->all();

        $reportsAgainst = Report::query()
            ->where('reportable_type', User::class)
            ->where('reportable_id', $target->id)
            ->latest('id')
            ->limit(15)
            ->get(['id', 'reason', 'status', 'created_at'])
            ->map(static fn (Report $r): array => [
                'id' => $r->id,
                'reason' => $r->reason->label(),
                'status' => $r->status->value,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all();

        $campaigns = AdCampaign::query()
            ->where('user_id', $target->id)
            ->latest('id')
            ->limit(15)
            ->get(['id', 'name', 'status', 'total_budget', 'spent_amount', 'impressions_count', 'clicks_count', 'created_at'])
            ->map(static fn (AdCampaign $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status->value,
                'total_budget' => $c->total_budget,
                'spent_amount' => $c->spent_amount,
                'impressions_count' => $c->impressions_count,
                'clicks_count' => $c->clicks_count,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values()->all();

        $creditEntries = $target->creditAccount !== null
            ? $target->creditAccount->entries()->latest('id')->limit(20)->get()->map(static fn ($e): array => [
                'id' => $e->id,
                'type' => $e->type->value,
                'wallet_bucket' => $e->wallet_bucket ?? 'paid',
                'amount' => $e->amount,
                'balance_after' => $e->balance_after,
                'description' => $e->description,
                'created_at' => $e->created_at->toIso8601String(),
            ])->values()->all()
            : [];

        $audits = AuditLog::query()
            ->where(static function (Builder $q) use ($target): void {
                $q->where('user_id', $target->id)
                    ->orWhere(static function (Builder $sub) use ($target): void {
                        $sub->where('auditable_type', User::class)->where('auditable_id', $target->id);
                    });
            })
            ->with(['actor:id,username'])
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_username' => $log->actor?->username,
                'ip_address' => $log->ip_address,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ])->values()->all();

        $loginHistories = $target->loginHistories()->latest('id')->limit(20)->get()->map(static fn ($h): array => [
            'id' => $h->id,
            'event_type' => $h->event_type,
            'ip_address' => $h->ip_address,
            'user_agent' => Str::limit((string) $h->user_agent, 120),
            'created_at' => $h->created_at->toIso8601String(),
        ])->values()->all();

        return [
            'account' => [
                'id' => $target->id,
                'name' => $target->name,
                'username' => $target->username,
                'email' => $target->email,
                'avatar' => $target->avatarUrl(),
                'headline' => $target->profile?->headline,
                'bio' => $target->profile?->bio,
                'is_verified_badge' => $target->profile !== null && $target->profile->is_verified_badge,
                'role' => $target->role->value,
                'role_label' => $target->rbacRole !== null ? $target->rbacRole->name : $target->role->label(),
                'rbac_role_id' => $target->rbac_role_id,
                'rbac_role' => $target->rbacRole !== null ? $target->rbacRole->slug : $target->role->value,
                'hierarchy_level' => $target->hierarchyLevel(),
                'status' => $target->status->value,
                'status_label' => $target->status->label(),
                'suspended_until' => $target->suspended_until?->toIso8601String(),
                'ban_reason' => $target->ban_reason,
                'warnings_count' => $target->warnings_count,
                'violations_count' => $target->violations_count,
                'timezone' => $target->timezone,
                'preferred_locale' => $target->preferred_locale,
                'email_verified_at' => $target->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $target->hasEnabledTwoFactorAuthentication(),
                'last_login_at' => $target->last_login_at?->toIso8601String(),
                'last_activity_at' => $target->last_activity_at?->toIso8601String(),
                'deactivated_at' => $target->deactivated_at?->toIso8601String(),
                'deleted_at' => $target->deleted_at?->toIso8601String(),
                'created_at' => $target->created_at?->toIso8601String(),
                'capabilities' => [
                    'can_post' => $target->can_post,
                    'post_restricted_until' => $target->post_restricted_until?->toIso8601String(),
                    'can_comment' => $target->can_comment,
                    'comment_restricted_until' => $target->comment_restricted_until?->toIso8601String(),
                    'can_create_community' => $target->can_create_community,
                    'community_restricted_until' => $target->community_restricted_until?->toIso8601String(),
                    'can_message' => $target->can_message,
                    'message_restricted_until' => $target->message_restricted_until?->toIso8601String(),
                    'can_advertise' => $target->can_advertise,
                    'ad_restricted_until' => $target->ad_restricted_until?->toIso8601String(),
                ],
                'wallet' => [
                    'balance' => $target->creditAccount !== null ? $target->creditAccount->balance : 0,
                    'promo_balance' => $target->creditAccount !== null ? $target->creditAccount->promo_balance : 0,
                    'held_balance' => $target->creditAccount !== null ? $target->creditAccount->held_balance : 0,
                    'total_deposited' => $target->creditAccount !== null ? $target->creditAccount->total_deposited : 0,
                    'total_spent' => $target->creditAccount !== null ? $target->creditAccount->total_spent : 0,
                ],
            ],
            'roles' => Role::query()->orderByDesc('hierarchy_level')->get(['id', 'slug', 'name', 'hierarchy_level'])->all(),
            'sessions' => $sessions,
            'loginHistories' => $loginHistories,
            'sanctions' => $sanctions,
            'posts' => $posts,
            'comments' => $comments,
            'communities' => $communities,
            'reportsAgainst' => $reportsAgainst,
            'campaigns' => $campaigns,
            'creditEntries' => $creditEntries,
            'audits' => $audits,
        ];
    }

    /**
     * Apply a recorded sanction or lift an active sanction/restriction with full hierarchy protection.
     */
    public function applySanction(
        User $actor,
        User $target,
        string $action,
        string $reason,
        ?string $duration = null,
        ?string $customUntil = null,
        ?string $capability = null,
    ): UserSanction {
        $this->assertCanModerateTarget($actor, $target);

        $endsAt = $this->resolveDurationEnd($duration, $customUntil);
        $cleanReason = trim($reason);

        return DB::transaction(function () use ($actor, $target, $action, $cleanReason, $endsAt, $capability): UserSanction {
            $sanctionType = match ($action) {
                'warn' => SanctionType::Warning,
                'suspend' => SanctionType::Suspend,
                'ban' => SanctionType::Ban,
                'unban' => SanctionType::Unban,
                'unsuspend' => SanctionType::Unsuspend,
                'restrict_post' => SanctionType::RestrictPost,
                'restrict_comment' => SanctionType::RestrictComment,
                'restrict_community' => SanctionType::RestrictCommunity,
                'restrict_message' => SanctionType::RestrictMessage,
                'restrict_ad' => SanctionType::RestrictAd,
                'lift_restriction' => SanctionType::LiftRestriction,
                default => throw ValidationException::withMessages(['action' => [__('Unsupported sanction action.')]]),
            };

            if ($sanctionType === SanctionType::Ban && ! $actor->hasPermission('users.ban')) {
                abort(403);
            }

            if ($sanctionType === SanctionType::Warning) {
                $target->warnings_count++;
                $target->violations_count++;
                $target->save();
            } elseif ($sanctionType === SanctionType::Suspend) {
                $target->status = UserStatus::Suspended;
                $target->suspended_until = $endsAt;
                $target->violations_count++;
                $target->save();
                $this->invalidateUserSessions($target);
            } elseif ($sanctionType === SanctionType::Ban) {
                $this->assertNotLastSuperAdmin($target);
                $target->status = UserStatus::Banned;
                $target->suspended_until = null;
                $target->ban_reason = $cleanReason;
                $target->violations_count++;
                $target->save();
                $this->invalidateUserSessions($target);

                // Stop active/pending Boost and Banner campaigns when an advertiser is banned.
                AdCampaign::query()
                    ->where('user_id', $target->id)
                    ->whereIn('status', ['pending_payment', 'pending_review', 'active', 'paused'])
                    ->update(['status' => 'cancelled', 'rejection_reason' => __('Account banned: campaign stopped.')]);

                if (Schema::hasTable('banner_campaigns')) {
                    BannerCampaign::query()
                        ->where('user_id', $target->id)
                        ->whereIn('status', ['pending_payment', 'pending_review', 'scheduled', 'active'])
                        ->update([
                            'status' => 'paused',
                            'emergency_paused_at' => now(),
                            'rejection_reason' => __('Account banned: banner paused.'),
                        ]);
                }
            } elseif ($sanctionType === SanctionType::Unban || $sanctionType === SanctionType::Unsuspend) {
                $target->status = UserStatus::Active;
                $target->suspended_until = null;
                $target->ban_reason = null;
                $target->deactivated_at = null;
                $target->save();

                UserSanction::query()
                    ->where('user_id', $target->id)
                    ->whereIn('type', [SanctionType::Ban->value, SanctionType::Suspend->value])
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $actor->id]);
            } elseif ($sanctionType === SanctionType::RestrictPost) {
                $target->forceFill(['can_post' => false, 'post_restricted_until' => $endsAt])->save();
            } elseif ($sanctionType === SanctionType::RestrictComment) {
                $target->forceFill(['can_comment' => false, 'comment_restricted_until' => $endsAt])->save();
            } elseif ($sanctionType === SanctionType::RestrictCommunity) {
                $target->forceFill(['can_create_community' => false, 'community_restricted_until' => $endsAt])->save();
            } elseif ($sanctionType === SanctionType::RestrictMessage) {
                $target->forceFill(['can_message' => false, 'message_restricted_until' => $endsAt])->save();
            } elseif ($sanctionType === SanctionType::RestrictAd) {
                $target->forceFill(['can_advertise' => false, 'ad_restricted_until' => $endsAt])->save();
            } elseif ($sanctionType === SanctionType::LiftRestriction) {
                $map = [
                    'post' => ['can_post' => true, 'post_restricted_until' => null, 'type' => SanctionType::RestrictPost->value],
                    'comment' => ['can_comment' => true, 'comment_restricted_until' => null, 'type' => SanctionType::RestrictComment->value],
                    'community' => ['can_create_community' => true, 'community_restricted_until' => null, 'type' => SanctionType::RestrictCommunity->value],
                    'message' => ['can_message' => true, 'message_restricted_until' => null, 'type' => SanctionType::RestrictMessage->value],
                    'advertise' => ['can_advertise' => true, 'ad_restricted_until' => null, 'type' => SanctionType::RestrictAd->value],
                ];

                if ($capability !== null && isset($map[$capability])) {
                    $cfg = $map[$capability];
                    $target->forceFill(array_diff_key($cfg, ['type' => true]))->save();
                    UserSanction::query()
                        ->where('user_id', $target->id)
                        ->where('type', $cfg['type'])
                        ->where('is_active', true)
                        ->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $actor->id]);
                } else {
                    $target->forceFill([
                        'can_post' => true,
                        'post_restricted_until' => null,
                        'can_comment' => true,
                        'comment_restricted_until' => null,
                        'can_create_community' => true,
                        'community_restricted_until' => null,
                        'can_message' => true,
                        'message_restricted_until' => null,
                        'can_advertise' => true,
                        'ad_restricted_until' => null,
                    ])->save();
                    UserSanction::query()
                        ->where('user_id', $target->id)
                        ->whereIn('type', [
                            SanctionType::RestrictPost->value,
                            SanctionType::RestrictComment->value,
                            SanctionType::RestrictCommunity->value,
                            SanctionType::RestrictMessage->value,
                            SanctionType::RestrictAd->value,
                        ])
                        ->where('is_active', true)
                        ->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $actor->id]);
                }
            }

            $sanction = new UserSanction([
                'type' => $sanctionType->value,
                'reason' => $cleanReason,
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'is_active' => ! in_array($sanctionType, [SanctionType::Unban, SanctionType::Unsuspend, SanctionType::LiftRestriction, SanctionType::Warning], true),
                'metadata' => $capability !== null ? ['capability' => $capability] : null,
            ]);
            $sanction->user_id = $target->id;
            $sanction->actor_id = $actor->id;
            $sanction->save();

            $this->auditLogger->log(
                "admin.user.{$sanctionType->value}",
                $target,
                ['sanction_id' => $sanction->id, 'reason' => $cleanReason, 'ends_at' => $endsAt?->toIso8601String()],
                $actor,
            );

            $target->notify(new SocialActivityNotification(
                eventKey: "account.{$sanctionType->value}",
                titleVi: "Thông báo tài khoản ({$sanctionType->label()}): {$cleanReason}",
                titleEn: "Account notice ({$sanctionType->label()}): {$cleanReason}",
                url: route('dashboard'),
                actorName: config('app.name', 'SyncViet'),
                actorUsername: 'system',
            ));

            return $sanction;
        });
    }

    /**
     * @param  array{name?: string, username?: string, timezone?: string, is_verified_badge?: bool}  $data
     */
    public function updateAccount(User $actor, User $target, array $data): User
    {
        $this->assertCanModerateTarget($actor, $target);

        $target->forceFill([
            'name' => isset($data['name']) ? trim($data['name']) : $target->name,
            'username' => isset($data['username']) ? Str::lower(trim($data['username'])) : $target->username,
            'timezone' => isset($data['timezone']) ? trim($data['timezone']) : $target->timezone,
        ])->save();

        if (array_key_exists('is_verified_badge', $data)) {
            $profile = $target->profile()->firstOrCreate(['user_id' => $target->id]);
            $profile->forceFill(['is_verified_badge' => (bool) $data['is_verified_badge']])->save();
        }

        $this->auditLogger->log('admin.user.updated', $target, ['fields' => array_keys($data)], $actor);

        return $target->fresh(['profile']) ?? $target;
    }

    public function revokeSessions(User $actor, User $target): void
    {
        $this->assertCanModerateTarget($actor, $target);
        $this->invalidateUserSessions($target);
        $this->auditLogger->log('admin.user.sessions_revoked', $target, [], $actor);
    }

    public function sendPasswordReset(User $actor, User $target): void
    {
        $this->assertCanModerateTarget($actor, $target);
        Password::broker()->sendResetLink(['email' => $target->email]);
        $this->auditLogger->log('admin.user.password_reset_sent', $target, [], $actor);
    }

    public function manageLifecycle(User $actor, User $target, string $mode, string $reason): void
    {
        $this->assertCanModerateTarget($actor, $target);

        if ($mode === 'deactivate') {
            $this->assertNotLastSuperAdmin($target);
            $target->forceFill(['deactivated_at' => now()])->save();
            $this->invalidateUserSessions($target);
            $this->auditLogger->log('admin.user.deactivated', $target, ['reason' => $reason], $actor);
        } elseif ($mode === 'soft_delete') {
            $this->assertNotLastSuperAdmin($target);
            $this->invalidateUserSessions($target);
            $target->delete();
            $this->auditLogger->log('admin.user.soft_deleted', $target, ['reason' => $reason], $actor);
        } elseif ($mode === 'restore') {
            $target->restore();
            $target->forceFill(['deactivated_at' => null, 'status' => UserStatus::Active])->save();
            $this->auditLogger->log('admin.user.restored', $target, ['reason' => $reason], $actor);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verificationsPayload(string $status = 'pending'): array
    {
        $items = VerificationRequest::query()
            ->with(['user:id,name,username,email', 'reviewer:id,username'])
            ->when($status !== 'all', static fn (Builder $q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return [
            'requests' => [
                'data' => array_values(collect($items->items())->map(static fn (VerificationRequest $r): array => [
                    'id' => $r->id,
                    'status' => $r->status,
                    'category' => $r->category,
                    'reason' => $r->reason,
                    'evidence_note' => $r->evidence_note,
                    'review_note' => $r->review_note,
                    'user' => $r->user === null ? null : [
                        'id' => $r->user->id,
                        'name' => $r->user->name,
                        'username' => $r->user->username,
                        'email' => $r->user->email,
                    ],
                    'reviewer_username' => $r->reviewer?->username,
                    'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                    'created_at' => $r->created_at?->toIso8601String(),
                ])->all()),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
            ],
            'status' => $status,
        ];
    }

    public function reviewVerification(User $actor, VerificationRequest $request, string $decision, ?string $note): void
    {
        DB::transaction(function () use ($actor, $request, $decision, $note): void {
            $approved = $decision === 'approved';
            $request->forceFill([
                'status' => $approved ? 'approved' : 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note !== null ? trim($note) : null,
            ])->save();

            if ($approved && $request->user !== null) {
                $profile = $request->user->profile()->firstOrCreate(['user_id' => $request->user->id]);
                $profile->forceFill(['is_verified_badge' => true])->save();
            }

            $this->auditLogger->log(
                "admin.verification.{$decision}",
                $request,
                ['target_user_id' => $request->user_id, 'note' => $note],
                $actor,
            );
        });
    }

    private function assertCanModerateTarget(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'user' => [__('You cannot apply administrative sanctions to your own account.')],
            ]);
        }

        if ($actor->hierarchyLevel() <= $target->hierarchyLevel() && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'user' => [__('You cannot moderate an account with equal or higher privilege level.')],
            ]);
        }
    }

    private function assertNotLastSuperAdmin(User $target): void
    {
        if (! $target->isSuperAdmin()) {
            return;
        }

        $count = User::query()
            ->whereHas('rbacRole', static fn (Builder $q) => $q->where('slug', 'super_admin'))
            ->count();

        if ($count <= 1) {
            throw ValidationException::withMessages([
                'user' => [__('Cannot ban, deactivate, or delete the last Super Administrator.')],
            ]);
        }
    }

    private function invalidateUserSessions(User $target): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $target->id)->delete();
        }

        $target->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    private function resolveDurationEnd(?string $duration, ?string $customUntil): ?CarbonImmutable
    {
        if ($duration === null || $duration === '' || $duration === 'permanent') {
            return null;
        }

        return match ($duration) {
            '1h' => now()->addHour(),
            '24h' => now()->addHours(24),
            '3d' => now()->addDays(3),
            '7d' => now()->addDays(7),
            '30d' => now()->addDays(30),
            'custom' => $customUntil !== null && $customUntil !== '' ? CarbonImmutable::parse($customUntil)->utc() : null,
            default => null,
        };
    }
}
