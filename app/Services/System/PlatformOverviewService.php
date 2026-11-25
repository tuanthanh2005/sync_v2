<?php

namespace App\Services\System;

use App\Enums\PolicyStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\PolicyPage;
use App\Models\User;

class PlatformOverviewService
{
    /**
     * Gather protected governance, security posture, and audit metrics for authorized staff.
     *
     * @return array{
     *     metrics: array{
     *         total_users: int,
     *         active_users: int,
     *         verified_users: int,
     *         staff_users: int,
     *         suspended_users: int
     *     },
     *     platform_status: array{
     *         app_env: string,
     *         locale: string,
     *         hashing_driver: string,
     *         session_driver: string,
     *         cache_store: string,
     *         queue_connection: string,
     *         media_disk: string,
     *         ssr_enabled: bool,
     *         admin_two_factor_enforced: bool,
     *         published_policies: int,
     *         draft_policies: int
     *     },
     *     recent_users: list<array{
     *         id: int,
     *         name: string,
     *         username: string,
     *         role: string,
     *         role_label: string,
     *         status: string,
     *         status_label: string,
     *         email_verified: bool,
     *         created_at: string|null
     *     }>,
     *     recent_audits: list<array{
     *         id: int,
     *         action: string,
     *         actor_username: string|null,
     *         ip_address: string|null,
     *         created_at: string
     *     }>
     * }
     */
    public function adminOverview(): array
    {
        $metrics = [
            'total_users' => User::query()->count(),
            'active_users' => User::query()->where('status', UserStatus::Active->value)->count(),
            'verified_users' => User::query()->whereNotNull('email_verified_at')->count(),
            'staff_users' => User::query()->whereIn('role', [
                UserRole::Admin->value,
                UserRole::Moderator->value,
            ])->count(),
            'suspended_users' => User::query()->whereIn('status', [
                UserStatus::Suspended->value,
                UserStatus::Banned->value,
            ])->count(),
        ];

        $recentUsers = array_values(
            User::query()
                ->select([
                    'id',
                    'name',
                    'username',
                    'role',
                    'status',
                    'email_verified_at',
                    'created_at',
                ])
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(static fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'status' => $user->status->value,
                    'status_label' => $user->status->label(),
                    'email_verified' => $user->hasVerifiedEmail(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ])
                ->all(),
        );

        $recentAudits = array_values(
            AuditLog::query()
                ->with(['actor:id,username'])
                ->latest('id')
                ->limit(10)
                ->get(['id', 'user_id', 'action', 'ip_address', 'created_at'])
                ->map(static fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actor_username' => $log->actor?->username,
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at->toIso8601String(),
                ])
                ->all(),
        );

        $platformStatus = [
            'app_env' => (string) config('app.env'),
            'locale' => app()->getLocale(),
            'hashing_driver' => (string) config('hashing.driver'),
            'session_driver' => (string) config('session.driver'),
            'cache_store' => (string) config('cache.default'),
            'queue_connection' => (string) config('queue.default'),
            'media_disk' => (string) config('syncviet.media_disk'),
            'ssr_enabled' => (bool) config('inertia.ssr.enabled', false),
            'admin_two_factor_enforced' => true,
            'published_policies' => PolicyPage::query()
                ->where('status', PolicyStatus::Published->value)
                ->where('is_visible', true)
                ->count(),
            'draft_policies' => PolicyPage::query()
                ->where('status', PolicyStatus::Draft->value)
                ->count(),
        ];

        return [
            'metrics' => $metrics,
            'platform_status' => $platformStatus,
            'recent_users' => $recentUsers,
            'recent_audits' => $recentAudits,
        ];
    }
}
