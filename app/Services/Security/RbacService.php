<?php

namespace App\Services\Security;

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RbacService
{
    public const CACHE_KEY = 'syncviet.rbac.matrix.v1';

    /**
     * @return list<array{slug: string, name: string, description: string, hierarchy_level: int, permissions: list<string>}>
     */
    public static function defaultRoleDefinitions(): array
    {
        return [
            [
                'slug' => 'super_admin',
                'name' => 'Super Admin',
                'description' => 'Unrestricted platform governance including RBAC, financial ledger controls, and system recovery.',
                'hierarchy_level' => 100,
                'permissions' => ['*'],
            ],
            [
                'slug' => 'admin',
                'name' => 'Administrator',
                'description' => 'Full operational administration across users, content, ads, finance, and system settings.',
                'hierarchy_level' => 80,
                'permissions' => [
                    'dashboard.view', 'analytics.view', 'system_health.view', 'system_health.manage',
                    'users.view', 'users.update', 'users.suspend', 'users.ban', 'users.restore', 'users.delete', 'users.manage',
                    'roles.view', 'verification.view', 'verification.approve', 'verification.reject',
                    'posts.view', 'posts.update', 'posts.delete', 'posts.restore', 'posts.manage',
                    'comments.view', 'comments.update', 'comments.delete', 'comments.restore',
                    'communities.view', 'communities.update', 'communities.delete', 'communities.restore', 'communities.manage',
                    'polls.view', 'polls.update', 'polls.delete',
                    'hashtags.view', 'hashtags.update', 'hashtags.manage',
                    'media.view', 'media.update', 'media.delete',
                    'moderation.view', 'moderation.approve', 'moderation.reject', 'moderation.manage',
                    'appeals.view', 'appeals.approve', 'appeals.reject',
                    'keywords.view', 'keywords.create', 'keywords.update', 'keywords.delete',
                    'ads.view', 'ads.update', 'ads.approve', 'ads.reject', 'ads.manage',
                    'finance.view', 'finance.update', 'finance.approve', 'finance.reject', 'finance.export', 'finance.manage',
                    'settings.view', 'settings.update', 'features.view', 'features.update',
                    'languages.view', 'languages.update', 'languages.manage', 'languages.export',
                    'notifications.view', 'notifications.create', 'notifications.update', 'notifications.delete', 'notifications.manage',
                    'policies.view', 'policies.create', 'policies.update', 'policies.delete',
                    'security.view', 'security.export', 'backups.view', 'backups.create', 'backups.export',
                ],
            ],
            [
                'slug' => 'moderator',
                'name' => 'Moderator',
                'description' => 'Community safety, moderation queue, appeals, user suspensions, and content enforcement.',
                'hierarchy_level' => 50,
                'permissions' => [
                    'dashboard.view', 'analytics.view',
                    'users.view', 'users.suspend',
                    'verification.view',
                    'posts.view', 'posts.update', 'posts.delete', 'posts.restore',
                    'comments.view', 'comments.update', 'comments.delete', 'comments.restore',
                    'communities.view', 'communities.update',
                    'polls.view', 'polls.update',
                    'hashtags.view', 'hashtags.update',
                    'media.view', 'media.update',
                    'moderation.view', 'moderation.approve', 'moderation.reject', 'moderation.manage',
                    'appeals.view', 'appeals.approve', 'appeals.reject',
                    'keywords.view', 'keywords.create', 'keywords.update',
                    'ads.view',
                ],
            ],
            [
                'slug' => 'content_manager',
                'name' => 'Content Manager',
                'description' => 'Posts, comments, communities, hashtags, polls, policies, and translations.',
                'hierarchy_level' => 45,
                'permissions' => [
                    'dashboard.view', 'analytics.view',
                    'posts.view', 'posts.update', 'posts.delete', 'posts.restore',
                    'comments.view', 'comments.update', 'comments.delete', 'comments.restore',
                    'communities.view', 'communities.update', 'communities.manage',
                    'polls.view', 'polls.update', 'polls.delete',
                    'hashtags.view', 'hashtags.update', 'hashtags.manage',
                    'media.view', 'media.update',
                    'policies.view', 'policies.update',
                    'languages.view', 'languages.update', 'languages.export',
                ],
            ],
            [
                'slug' => 'finance_manager',
                'name' => 'Finance Manager',
                'description' => 'Ad credit wallets, ledger adjustments, payments, refunds, promotional credits, and revenue reports.',
                'hierarchy_level' => 45,
                'permissions' => [
                    'dashboard.view', 'analytics.view',
                    'ads.view',
                    'finance.view', 'finance.update', 'finance.approve', 'finance.reject', 'finance.export', 'finance.manage',
                ],
            ],
            [
                'slug' => 'advertising_manager',
                'name' => 'Advertising Manager',
                'description' => 'Campaign review, delivery limits, ad frequency settings, and ad performance.',
                'hierarchy_level' => 45,
                'permissions' => [
                    'dashboard.view', 'analytics.view',
                    'posts.view',
                    'ads.view', 'ads.update', 'ads.approve', 'ads.reject', 'ads.manage',
                ],
            ],
            [
                'slug' => 'support_staff',
                'name' => 'Support Staff',
                'description' => 'Read-only user diagnostics, verification review, appeals triage, and support broadcasts.',
                'hierarchy_level' => 30,
                'permissions' => [
                    'dashboard.view',
                    'users.view',
                    'verification.view',
                    'moderation.view',
                    'appeals.view',
                    'notifications.view', 'notifications.create',
                    'policies.view',
                ],
            ],
            [
                'slug' => 'user',
                'name' => 'Member',
                'description' => 'Standard community member account.',
                'hierarchy_level' => 0,
                'permissions' => [],
            ],
        ];
    }

    /**
     * @return list<array{module: string, action: string, label: string}>
     */
    public static function permissionCatalog(): array
    {
        $modules = [
            'dashboard' => ['view'],
            'analytics' => ['view', 'export'],
            'system_health' => ['view', 'manage'],
            'users' => ['view', 'update', 'suspend', 'ban', 'restore', 'delete', 'manage'],
            'roles' => ['view', 'update', 'manage'],
            'verification' => ['view', 'approve', 'reject'],
            'posts' => ['view', 'update', 'delete', 'restore', 'manage'],
            'comments' => ['view', 'update', 'delete', 'restore'],
            'communities' => ['view', 'update', 'delete', 'restore', 'manage'],
            'polls' => ['view', 'update', 'delete'],
            'hashtags' => ['view', 'update', 'manage'],
            'media' => ['view', 'update', 'delete'],
            'moderation' => ['view', 'approve', 'reject', 'manage'],
            'appeals' => ['view', 'approve', 'reject'],
            'keywords' => ['view', 'create', 'update', 'delete'],
            'ads' => ['view', 'update', 'approve', 'reject', 'manage'],
            'finance' => ['view', 'update', 'approve', 'reject', 'export', 'manage'],
            'settings' => ['view', 'update', 'manage'],
            'features' => ['view', 'update'],
            'languages' => ['view', 'update', 'export', 'manage'],
            'notifications' => ['view', 'create', 'update', 'delete', 'manage'],
            'policies' => ['view', 'create', 'update', 'delete'],
            'security' => ['view', 'export', 'manage'],
            'backups' => ['view', 'create', 'export'],
        ];

        $catalog = [];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                $catalog[] = [
                    'module' => $module,
                    'action' => $action,
                    'label' => ucfirst(str_replace('_', ' ', $module)).' · '.ucfirst($action),
                ];
            }
        }

        return $catalog;
    }

    public function ensureSeeded(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        if (Role::query()->exists() && Permission::query()->exists()) {
            return;
        }

        $this->syncCatalog();
    }

    public function syncCatalog(): void
    {
        DB::transaction(function (): void {
            $permissionIds = [];

            foreach (self::permissionCatalog() as $item) {
                $slug = "{$item['module']}.{$item['action']}";
                $perm = Permission::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'module' => $item['module'],
                        'action' => $item['action'],
                        'label' => $item['label'],
                    ],
                );
                $permissionIds[$slug] = $perm->id;
            }

            $allIds = array_values($permissionIds);

            foreach (self::defaultRoleDefinitions() as $def) {
                $role = Role::query()->updateOrCreate(
                    ['slug' => $def['slug']],
                    [
                        'name' => $def['name'],
                        'description' => $def['description'],
                        'hierarchy_level' => $def['hierarchy_level'],
                        'is_system' => true,
                    ],
                );

                if ($def['permissions'] === ['*']) {
                    $role->permissions()->sync($allIds);
                } else {
                    $ids = array_values(array_filter(
                        array_map(
                            static fn (string $slug): ?int => $permissionIds[$slug] ?? null,
                            $def['permissions'],
                        ),
                    ));
                    $role->permissions()->sync($ids);
                }
            }

            $this->flushCache();
        });
    }

    public function assignRole(User $actor, User $target, Role $role, ?AuditLogger $auditLogger = null): User
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'role' => [__('You cannot modify your own role.')],
            ]);
        }

        if (! $actor->hasPermission('roles.manage') && ! $actor->isAdmin()) {
            abort(403);
        }

        if ($actor->hierarchyLevel() <= $target->hierarchyLevel() && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'role' => [__('You cannot modify a user with equal or higher privilege level.')],
            ]);
        }

        if ($role->hierarchy_level >= $actor->hierarchyLevel() && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'role' => [__('You cannot assign a role higher than or equal to your own.')],
            ]);
        }

        if ($target->isSuperAdmin() && $role->slug !== 'super_admin') {
            $superAdminCount = User::query()
                ->whereHas('rbacRole', static fn ($q) => $q->where('slug', 'super_admin'))
                ->count();

            if ($superAdminCount <= 1) {
                throw ValidationException::withMessages([
                    'role' => [__('Cannot demote or remove the last Super Administrator.')],
                ]);
            }
        }

        $previousRole = $target->rbacRole !== null ? $target->rbacRole->slug : $target->role->value;

        $baseRole = match ($role->slug) {
            'super_admin', 'admin' => UserRole::Admin,
            'user' => UserRole::User,
            default => UserRole::Moderator,
        };

        $target->forceFill([
            'role' => $baseRole,
            'rbac_role_id' => $role->id,
        ])->save();

        if ($auditLogger !== null) {
            $auditLogger->log(
                'rbac.role_assigned',
                $target,
                ['from' => $previousRole, 'to' => $role->slug],
                $actor,
            );
        }

        return $target->fresh(['rbacRole.permissions']) ?? $target;
    }

    /**
     * @param  list<int>  $permissionIds
     */
    public function syncRolePermissions(User $actor, Role $role, array $permissionIds, AuditLogger $auditLogger): Role
    {
        if ($role->slug === 'super_admin') {
            throw ValidationException::withMessages([
                'permissions' => [__('Super Admin retains all system permissions and cannot be restricted.')],
            ]);
        }

        if ($role->hierarchy_level >= $actor->hierarchyLevel() && ! $actor->isSuperAdmin()) {
            abort(403);
        }

        /** @var list<int> $validIds */
        $validIds = Permission::query()
            ->whereIn('id', $permissionIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $role->permissions()->sync($validIds);
        $this->flushCache();

        $auditLogger->log(
            'rbac.permissions_updated',
            $role,
            ['role' => $role->slug, 'permission_count' => count($validIds)],
            $actor,
        );

        return $role->fresh(['permissions']) ?? $role;
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
