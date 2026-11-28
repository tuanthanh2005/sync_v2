<?php

namespace App\Services\Admin;

use App\Enums\MemberBadgeType;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\Security\AuditLogger;
use App\Services\Social\UserBadgeResolverService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdminBadgeManagementService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly UserBadgeResolverService $resolver,
    ) {}

    public function assertCanManageBadges(User $actor): void
    {
        if (
            ! $actor->isActive()
            || ! $actor->isSuperAdmin()
            || (! $actor->hasPermission('badges.manage') && ! $actor->hasPermission('badges.grant') && ! $actor->hasPermission('badges.revoke'))
        ) {
            abort(403, __('Only Super Administrators with badge management permission can manage official and staff badges.'));
        }
    }

    /**
     * Grant an explicit badge inside a locked transaction while enforcing eligibility
     * and preventing duplicate active grants or privilege escalation.
     */
    public function grantBadge(
        User $actor,
        User $target,
        MemberBadgeType $type,
        string $reason,
        bool $isPublic = true,
        ?CarbonInterface $expiresAt = null,
    ): UserBadge {
        $this->assertCanManageBadges($actor);

        if (! $type->isManuallyGrantable()) {
            throw ValidationException::withMessages([
                'badge_type' => [__('This badge type is computed automatically from account subscription or AI status and cannot be manually granted.')],
            ]);
        }

        $cleanReason = trim($reason);
        if (mb_strlen($cleanReason) < 3) {
            throw ValidationException::withMessages([
                'reason' => [__('Please provide a clear reason (at least 3 characters) for the audit log.')],
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $type, $cleanReason, $isPublic, $expiresAt): UserBadge {
            /** @var User $locked */
            $locked = User::query()
                ->withTrashed()
                ->with('rbacRole:id,slug,name')
                ->lockForUpdate()
                ->findOrFail($target->id);

            $this->assertTargetEligible($locked, $type);

            $alreadyActive = UserBadge::query()
                ->where('user_id', $locked->id)
                ->where('badge_type', $type->value)
                ->active()
                ->exists();

            if ($alreadyActive) {
                throw ValidationException::withMessages([
                    'badge_type' => [__('This account already holds an active :badge badge.', ['badge' => $type->label()])],
                ]);
            }

            $badge = new UserBadge;
            $badge->forceFill([
                'user_id' => $locked->id,
                'badge_type' => $type->value,
                'status' => UserBadge::STATUS_ACTIVE,
                'label_key' => $type->labelKey(),
                'icon' => $type->iconKey(),
                'color' => $type->colorHex(),
                'grant_source' => 'admin_grant',
                'campaign_id' => null,
                'granted_by' => $actor->id,
                'granted_at' => now(),
                'grant_reason' => $cleanReason,
                'expires_at' => $expiresAt,
                'revoked_by' => null,
                'revoked_at' => null,
                'revoke_reason' => null,
                'is_public' => $isPublic,
                'metadata' => null,
            ]);
            $badge->save();

            $this->auditLogger->log(
                'admin.badge.granted',
                $badge,
                [
                    'target_user_id' => $locked->id,
                    'target_username' => $locked->username,
                    'badge_type' => $type->value,
                    'previous_status' => null,
                    'new_status' => UserBadge::STATUS_ACTIVE,
                    'is_public' => $isPublic,
                    'reason' => $cleanReason,
                    'expires_at' => $expiresAt?->toIso8601String(),
                ],
                $actor,
            );

            $this->resolver->invalidateForUser($locked->id);

            return $badge->fresh(['user', 'grantedByUser']) ?? $badge;
        });
    }

    /**
     * Revoke an active badge while preserving full grant/revoke audit history.
     */
    public function revokeBadge(User $actor, UserBadge $badge, string $reason): UserBadge
    {
        $this->assertCanManageBadges($actor);

        $cleanReason = trim($reason);
        if (mb_strlen($cleanReason) < 3) {
            throw ValidationException::withMessages([
                'reason' => [__('Please provide a clear revocation reason (at least 3 characters).')],
            ]);
        }

        return DB::transaction(function () use ($actor, $badge, $cleanReason): UserBadge {
            /** @var UserBadge $locked */
            $locked = UserBadge::query()->lockForUpdate()->findOrFail($badge->id);

            if ($locked->status === UserBadge::STATUS_REVOKED || $locked->revoked_at !== null) {
                throw ValidationException::withMessages([
                    'badge' => [__('This badge has already been revoked.')],
                ]);
            }

            $previousStatus = $locked->status ?: UserBadge::STATUS_ACTIVE;

            $locked->forceFill([
                'status' => UserBadge::STATUS_REVOKED,
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
                'revoke_reason' => $cleanReason,
            ])->save();

            $this->auditLogger->log(
                'admin.badge.revoked',
                $locked,
                [
                    'target_user_id' => $locked->user_id,
                    'badge_type' => $locked->badge_type,
                    'previous_status' => $previousStatus,
                    'new_status' => UserBadge::STATUS_REVOKED,
                    'reason' => $cleanReason,
                ],
                $actor,
            );

            $this->resolver->invalidateForUser($locked->user_id);

            return $locked->fresh(['user', 'revokedByUser']) ?? $locked;
        });
    }

    /**
     * Toggle public display visibility (supports non-public internal/staff accounts).
     */
    public function toggleVisibility(User $actor, UserBadge $badge, bool $isPublic): UserBadge
    {
        $this->assertCanManageBadges($actor);

        return DB::transaction(function () use ($actor, $badge, $isPublic): UserBadge {
            /** @var UserBadge $locked */
            $locked = UserBadge::query()->lockForUpdate()->findOrFail($badge->id);
            $previous = (bool) $locked->is_public;

            $locked->forceFill(['is_public' => $isPublic])->save();

            $this->auditLogger->log(
                'admin.badge.visibility_updated',
                $locked,
                [
                    'target_user_id' => $locked->user_id,
                    'badge_type' => $locked->badge_type,
                    'previous_is_public' => $previous,
                    'new_is_public' => $isPublic,
                ],
                $actor,
            );

            $this->resolver->invalidateForUser($locked->user_id);

            return $locked;
        });
    }

    /**
     * Reconcile stored Staff / Moderator / Official badges whenever an account's role
     * or standing changes, immediately purging badge cache.
     */
    public function syncEligibilityForUser(
        User $target,
        ?User $actor = null,
        string $trigger = 'role_or_status_change',
        bool $revokeAllAuthority = false,
    ): int {
        if (! Schema::hasTable('user_badges')) {
            return 0;
        }

        $revokedCount = DB::transaction(function () use ($target, $actor, $trigger, $revokeAllAuthority): int {
            /** @var User $freshTarget */
            $freshTarget = User::query()->withTrashed()->with('rbacRole:id,slug,name')->findOrFail($target->id);

            $activeAuthorityBadges = UserBadge::query()
                ->where('user_id', $freshTarget->id)
                ->whereIn('badge_type', [
                    MemberBadgeType::OfficialAccount->value,
                    MemberBadgeType::OfficialStaff->value,
                    MemberBadgeType::Moderator->value,
                ])
                ->active()
                ->lockForUpdate()
                ->get();

            $count = 0;
            foreach ($activeAuthorityBadges as $badge) {
                $shouldRevoke = $revokeAllAuthority;
                $reason = 'Automatic revocation: account banned or removed';

                if (! $shouldRevoke && $badge->badge_type === MemberBadgeType::OfficialStaff->value && ! $freshTarget->isEligibleForOfficialStaffBadge()) {
                    // Preserve badge record during temporary suspensions (resolver hides while suspended);
                    // permanently revoke when required staff/admin role is removed.
                    if ($freshTarget->status !== UserStatus::Suspended) {
                        $shouldRevoke = true;
                        $reason = 'Automatic revocation: required staff role removed';
                    }
                }

                if (! $shouldRevoke && $badge->badge_type === MemberBadgeType::Moderator->value && ! $freshTarget->isEligibleForModeratorBadge()) {
                    if ($freshTarget->status !== UserStatus::Suspended) {
                        $shouldRevoke = true;
                        $reason = 'Automatic revocation: required platform moderator role removed';
                    }
                }

                if (! $shouldRevoke) {
                    continue;
                }

                $badge->forceFill([
                    'status' => UserBadge::STATUS_REVOKED,
                    'revoked_by' => $actor?->id,
                    'revoked_at' => now(),
                    'revoke_reason' => $reason,
                ])->save();

                $this->auditLogger->log(
                    'admin.badge.auto_revoked',
                    $badge,
                    [
                        'target_user_id' => $freshTarget->id,
                        'badge_type' => $badge->badge_type,
                        'trigger' => $trigger,
                        'previous_status' => UserBadge::STATUS_ACTIVE,
                        'new_status' => UserBadge::STATUS_REVOKED,
                        'reason' => $reason,
                    ],
                    $actor,
                );

                $count++;
            }

            return $count;
        });

        $this->resolver->invalidateForUser($target->id);

        return $revokedCount;
    }

    /**
     * Build Admin Badge Management index payload.
     *
     * @param  array{search?: string, badge_type?: string, status?: string, role?: string}  $filters
     * @return array<string, mixed>
     */
    public function indexPayload(User $viewer, array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $badgeTypeFilter = trim((string) ($filters['badge_type'] ?? ''));
        $statusFilter = trim((string) ($filters['status'] ?? 'all'));
        $roleFilter = trim((string) ($filters['role'] ?? ''));

        $query = UserBadge::query()
            ->with([
                'user' => static fn ($q) => $q->withTrashed()->with(['profile:id,user_id,avatar_disk,avatar_path', 'rbacRole:id,slug,name']),
                'grantedByUser:id,name,username',
                'revokedByUser:id,name,username',
            ]);

        if ($badgeTypeFilter !== '' && $badgeTypeFilter !== 'all') {
            $query->where('badge_type', $badgeTypeFilter);
        }

        if ($statusFilter === 'active') {
            $query->active();
        } elseif ($statusFilter === 'revoked') {
            $query->where(static function (Builder $q): void {
                $q->where('status', UserBadge::STATUS_REVOKED)->orWhereNotNull('revoked_at');
            });
        } elseif ($statusFilter === 'hidden') {
            $query->active()->where('is_public', false);
        }

        if ($roleFilter !== '' && $roleFilter !== 'all') {
            $query->whereHas('user', static function (Builder $uq) use ($roleFilter): void {
                $uq->withTrashed()->where(static function (Builder $rq) use ($roleFilter): void {
                    $rq->where('role', $roleFilter)
                        ->orWhereHas('rbacRole', static fn (Builder $sub) => $sub->where('slug', $roleFilter));
                });
            });
        }

        if ($search !== '') {
            $escaped = addcslashes($search, '\\%_');
            $pattern = "%{$escaped}%";
            $query->whereHas('user', static function (Builder $uq) use ($pattern, $search): void {
                $uq->withTrashed()->where(static function (Builder $sub) use ($pattern, $search): void {
                    $sub->where('name', 'like', $pattern)
                        ->orWhere('username', 'like', $pattern)
                        ->orWhere('email', 'like', $pattern);
                    if (ctype_digit($search)) {
                        $sub->orWhere('id', (int) $search);
                    }
                });
            });
        }

        $paginator = $query->latest('id')->paginate(20)->withQueryString();

        $rows = array_values(
            collect($paginator->items())->map(function (UserBadge $b): array {
                $u = $b->user;
                $enum = MemberBadgeType::tryFrom($b->badge_type);
                $effectiveEligible = true;
                if ($u !== null && $enum === MemberBadgeType::OfficialStaff) {
                    $effectiveEligible = $u->isEligibleForOfficialStaffBadge();
                } elseif ($u !== null && $enum === MemberBadgeType::Moderator) {
                    $effectiveEligible = $u->isEligibleForModeratorBadge();
                } elseif ($u !== null) {
                    $effectiveEligible = $u->isActive();
                }

                return [
                    'id' => $b->id,
                    'badge_type' => $b->badge_type,
                    'label' => $enum?->label() ?? $b->badge_type,
                    'color' => $enum?->colorHex() ?? '#4F46E5',
                    'icon' => $enum?->iconKey() ?? $b->icon,
                    'status' => $b->isActive() ? UserBadge::STATUS_ACTIVE : ($b->revoked_at !== null ? UserBadge::STATUS_REVOKED : $b->status),
                    'is_active' => $b->isActive(),
                    'is_public' => (bool) $b->is_public,
                    'is_effectively_visible' => $b->isActive() && (bool) $b->is_public && $effectiveEligible,
                    'effective_eligible' => $effectiveEligible,
                    'grant_source' => $b->grant_source,
                    'grant_reason' => $b->grant_reason,
                    'granted_at' => $b->granted_at->toIso8601String(),
                    'granted_by_username' => $b->grantedByUser?->username,
                    'expires_at' => $b->expires_at?->toIso8601String(),
                    'revoked_at' => $b->revoked_at?->toIso8601String(),
                    'revoked_by_username' => $b->revokedByUser?->username,
                    'revoke_reason' => $b->revoke_reason,
                    'user' => $u === null ? null : [
                        'id' => $u->id,
                        'name' => $u->name,
                        'username' => $u->username,
                        'email' => $u->email,
                        'avatar' => $u->avatarUrl(),
                        'role' => $u->role->value,
                        'rbac_role' => $u->rbacRole !== null ? $u->rbacRole->slug : $u->role->value,
                        'role_label' => $u->rbacRole !== null ? $u->rbacRole->name : $u->role->label(),
                        'status' => $u->status->value,
                        'is_ai_agent' => $u->isAiAgent(),
                    ],
                ];
            })->all(),
        );

        $auditLogs = AuditLog::query()
            ->where('action', 'like', 'admin.badge.%')
            ->with('actor:id,name,username')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_username' => $log->actor?->username,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ])->values()->all();

        $counts = [
            'active_official_account' => UserBadge::query()->where('badge_type', MemberBadgeType::OfficialAccount->value)->active()->count(),
            'active_official_staff' => UserBadge::query()->where('badge_type', MemberBadgeType::OfficialStaff->value)->active()->count(),
            'active_moderator' => UserBadge::query()->where('badge_type', MemberBadgeType::Moderator->value)->active()->count(),
            'active_early_member' => UserBadge::query()->where('badge_type', MemberBadgeType::EarlyMember->value)->active()->count(),
            'active_premium' => User::query()->where('status', UserStatus::Active->value)->where('account_type', '!=', 'ai_agent')->whereNotNull('premium_until')->where('premium_until', '>', now())->whereNull('premium_suspended_at')->count(),
            'ai_accounts' => User::query()->where('status', UserStatus::Active->value)->where('account_type', 'ai_agent')->count(),
        ];

        $grantableOptions = array_map(
            static fn (MemberBadgeType $t): array => [
                'value' => $t->value,
                'label_key' => $t->labelKey(),
                'color' => $t->colorHex(),
                'icon' => $t->iconKey(),
            ],
            MemberBadgeType::adminManageableCases(),
        );

        return [
            'badges' => [
                'data' => $rows,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'stats' => $counts,
            'grantableOptions' => $grantableOptions,
            'auditLogs' => $auditLogs,
            'canManage' => $viewer->isSuperAdmin() && $viewer->isActive() && ($viewer->hasPermission('badges.manage') || $viewer->hasPermission('badges.grant')),
            'filters' => [
                'search' => $search,
                'badge_type' => $badgeTypeFilter ?: 'all',
                'status' => $statusFilter ?: 'all',
                'role' => $roleFilter ?: 'all',
            ],
        ];
    }

    /**
     * Build badge diagnostics & history section for Admin User Detail page.
     *
     * @return array<string, mixed>
     */
    public function userDetailPayload(User $target, User $viewer): array
    {
        $resolved = $this->resolver->resolveForUser($target);

        $history = UserBadge::query()
            ->where('user_id', $target->id)
            ->with(['grantedByUser:id,username', 'revokedByUser:id,username'])
            ->latest('id')
            ->get()
            ->map(static function (UserBadge $b) use ($target): array {
                $enum = MemberBadgeType::tryFrom($b->badge_type);
                $eligible = match ($enum) {
                    MemberBadgeType::OfficialStaff => $target->isEligibleForOfficialStaffBadge(),
                    MemberBadgeType::Moderator => $target->isEligibleForModeratorBadge(),
                    default => $target->isActive(),
                };

                return [
                    'id' => $b->id,
                    'badge_type' => $b->badge_type,
                    'label' => $enum?->label() ?? $b->badge_type,
                    'label_key' => $enum?->labelKey() ?? $b->label_key,
                    'color' => $enum?->colorHex() ?? '#4F46E5',
                    'icon' => $enum?->iconKey() ?? $b->icon,
                    'status' => $b->isActive() ? UserBadge::STATUS_ACTIVE : ($b->revoked_at !== null ? UserBadge::STATUS_REVOKED : $b->status),
                    'is_active' => $b->isActive(),
                    'is_public' => (bool) $b->is_public,
                    'effective_eligible' => $eligible,
                    'grant_source' => $b->grant_source,
                    'grant_reason' => $b->grant_reason,
                    'granted_at' => $b->granted_at->toIso8601String(),
                    'granted_by_username' => $b->grantedByUser?->username,
                    'expires_at' => $b->expires_at?->toIso8601String(),
                    'revoked_at' => $b->revoked_at?->toIso8601String(),
                    'revoked_by_username' => $b->revokedByUser?->username,
                    'revoke_reason' => $b->revoke_reason,
                ];
            })->values()->all();

        return [
            'resolved' => $resolved,
            'history' => $history,
            'eligibility' => [
                'official_account' => $target->isActive(),
                'official_staff' => $target->isEligibleForOfficialStaffBadge(),
                'moderator' => $target->isEligibleForModeratorBadge(),
                'premium_member' => ! $target->isAiAgent() && $target->isPremium(),
                'ai_account' => $target->isAiAgent(),
            ],
            'canManage' => $viewer->isSuperAdmin() && $viewer->isActive() && ($viewer->hasPermission('badges.manage') || $viewer->hasPermission('badges.grant')),
            'grantableOptions' => array_map(
                static fn (MemberBadgeType $t): array => [
                    'value' => $t->value,
                    'label_key' => $t->labelKey(),
                    'color' => $t->colorHex(),
                    'icon' => $t->iconKey(),
                ],
                MemberBadgeType::adminManageableCases(),
            ),
        ];
    }

    private function assertTargetEligible(User $target, MemberBadgeType $type): void
    {
        if (! $target->isActive()) {
            throw ValidationException::withMessages([
                'user' => [__('Suspended, banned, deactivated, or deleted accounts cannot be granted badges.')],
            ]);
        }

        if ($type === MemberBadgeType::OfficialStaff && ! $target->isEligibleForOfficialStaffBadge()) {
            throw ValidationException::withMessages([
                'badge_type' => [__('Official Staff badge requires an active human account holding an eligible Staff or Administrator role.')],
            ]);
        }

        if ($type === MemberBadgeType::Moderator && ! $target->isEligibleForModeratorBadge()) {
            throw ValidationException::withMessages([
                'badge_type' => [__('Moderator badge requires an active human account holding the Platform Moderator role.')],
            ]);
        }

        if (
            in_array($type, [
                MemberBadgeType::EarlyMember,
                MemberBadgeType::FoundingMember,
                MemberBadgeType::ReferralAmbassador,
                MemberBadgeType::CommunityBuilder,
            ], true)
            && ! $target->isHumanMember()
        ) {
            throw ValidationException::withMessages([
                'badge_type' => [__('This recognition badge is only eligible for verified human member accounts.')],
            ]);
        }
    }
}
