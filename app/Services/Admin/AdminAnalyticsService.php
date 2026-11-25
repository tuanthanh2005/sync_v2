<?php

namespace App\Services\Admin;

use App\Enums\AdCampaignStatus;
use App\Enums\PaymentStatus;
use App\Enums\PolicyStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AdCampaign;
use App\Models\AdCreditEntry;
use App\Models\Appeal;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Payment;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Report;
use App\Models\User;
use App\Models\VerificationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAnalyticsService
{
    /**
     * Comprehensive KPI dashboard powered by real indexed aggregates.
     *
     * @return array<string, mixed>
     */
    public function dashboardPayload(): array
    {
        $now = now();
        $weekAgo = $now->subDays(7);
        $dayAgo = $now->subDay();
        $monthAgo = $now->subDays(30);

        $metrics = [
            'total_users' => User::query()->count(),
            'new_users_7d' => User::query()->where('created_at', '>=', $weekAgo)->count(),
            'active_users' => User::query()->where('status', UserStatus::Active->value)->count(),
            'verified_users' => User::query()->whereNotNull('email_verified_at')->count(),
            'unverified_users' => User::query()->whereNull('email_verified_at')->count(),
            'staff_users' => User::query()->whereIn('role', [UserRole::Admin->value, UserRole::Moderator->value])->count(),
            'suspended_users' => User::query()->where('status', UserStatus::Suspended->value)->count(),
            'banned_users' => User::query()->where('status', UserStatus::Banned->value)->count(),
            'dau' => User::query()->where('last_activity_at', '>=', $dayAgo)->count(),
            'mau' => User::query()->where('last_activity_at', '>=', $monthAgo)->count(),
            'total_posts' => Post::query()->count(),
            'total_comments' => Comment::query()->count(),
            'total_communities' => Community::query()->count(),
            'pending_reports' => Report::query()->whereIn('status', [ReportStatus::Pending->value, ReportStatus::UnderReview->value])->count(),
            'pending_ads' => AdCampaign::query()->where('status', AdCampaignStatus::PendingReview->value)->count(),
            'active_ads' => AdCampaign::query()->where('status', AdCampaignStatus::Active->value)->count(),
            'pending_appeals' => Schema::hasTable('appeals') ? Appeal::query()->where('status', 'pending')->count() : 0,
            'pending_verifications' => Schema::hasTable('verification_requests') ? VerificationRequest::query()->where('status', 'pending')->count() : 0,
            'total_revenue_vnd' => (int) Payment::query()->where('status', PaymentStatus::Paid->value)->sum('amount'),
            'media_storage_bytes' => (int) PostMedia::query()->sum('size_bytes'),
        ];

        $series = $this->buildDailySeries(14);

        $recentUsers = array_values(
            User::query()
                ->select(['id', 'name', 'username', 'role', 'status', 'email_verified_at', 'created_at'])
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(static fn (User $u): array => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'username' => $u->username,
                    'role' => $u->role->value,
                    'role_label' => $u->role->label(),
                    'status' => $u->status->value,
                    'status_label' => $u->status->label(),
                    'email_verified' => $u->hasVerifiedEmail(),
                    'created_at' => $u->created_at?->toIso8601String(),
                ])->all(),
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
                ])->all(),
        );

        return [
            'metrics' => $metrics,
            'platform_status' => [
                'app_env' => (string) config('app.env'),
                'locale' => app()->getLocale(),
                'hashing_driver' => (string) config('hashing.driver'),
                'session_driver' => (string) config('session.driver'),
                'cache_store' => (string) config('cache.default'),
                'queue_connection' => (string) config('queue.default'),
                'media_disk' => (string) config('syncviet.media_disk'),
                'ssr_enabled' => (bool) config('inertia.ssr.enabled', false),
                'admin_two_factor_enforced' => true,
                'published_policies' => PolicyPage::query()->where('status', PolicyStatus::Published->value)->where('is_visible', true)->count(),
                'draft_policies' => PolicyPage::query()->where('status', PolicyStatus::Draft->value)->count(),
            ],
            'growth_chart' => $series,
            'recent_users' => $recentUsers,
            'recent_audits' => $recentAudits,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function analyticsPayload(int $days = 30): array
    {
        $safeDays = in_array($days, [7, 30, 90], true) ? $days : 30;
        $series = $this->buildDailySeries($safeDays);

        $currentStart = now()->subDays($safeDays);
        $prevStart = now()->subDays($safeDays * 2);

        $curUsers = User::query()->where('created_at', '>=', $currentStart)->count();
        $prevUsers = User::query()->whereBetween('created_at', [$prevStart, $currentStart])->count();
        $curPosts = Post::query()->where('created_at', '>=', $currentStart)->count();
        $prevPosts = Post::query()->whereBetween('created_at', [$prevStart, $currentStart])->count();

        return [
            'period' => $safeDays,
            'totals' => [
                'users' => User::query()->count(),
                'posts' => Post::query()->count(),
                'comments' => Comment::query()->count(),
                'communities' => Community::query()->count(),
                'activeCampaigns' => AdCampaign::query()->where('status', AdCampaignStatus::Active->value)->count(),
                'revenueVnd' => (int) Payment::query()->where('status', PaymentStatus::Paid->value)->where('paid_at', '>=', $currentStart)->sum('amount'),
            ],
            'growth' => [
                'users_pct' => $prevUsers > 0 ? round((($curUsers - $prevUsers) / $prevUsers) * 100, 1) : ($curUsers > 0 ? 100.0 : 0.0),
                'posts_pct' => $prevPosts > 0 ? round((($curPosts - $prevPosts) / $prevPosts) * 100, 1) : ($curPosts > 0 ? 100.0 : 0.0),
            ],
            'series' => $series,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function systemHealthPayload(): array
    {
        $dbStart = microtime(true);
        DB::select('SELECT 1');
        $dbLatencyMs = round((microtime(true) - $dbStart) * 1000, 2);

        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $activeSessions = Schema::hasTable('sessions') ? DB::table('sessions')->where('last_activity', '>=', now()->subMinutes(30)->timestamp)->count() : 0;
        $backupsCount = Schema::hasTable('backup_records') ? BackupRecord::query()->count() : 0;
        $lastBackup = Schema::hasTable('backup_records') ? BackupRecord::query()->latest('id')->first() : null;

        return [
            'health' => [
                'db_ok' => true,
                'db_connection' => (string) config('database.default'),
                'db_latency_ms' => $dbLatencyMs,
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'app_env' => (string) config('app.env'),
                'app_debug' => (bool) config('app.debug'),
                'session_driver' => (string) config('session.driver'),
                'cache_store' => (string) config('cache.default'),
                'queue_connection' => (string) config('queue.default'),
                'media_disk' => (string) config('syncviet.media_disk'),
                'hashing_driver' => (string) config('hashing.driver'),
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'active_sessions_30m' => $activeSessions,
                'media_files_count' => PostMedia::query()->count(),
                'media_storage_bytes' => (int) PostMedia::query()->sum('size_bytes'),
                'backups_count' => $backupsCount,
                'last_backup_at' => $lastBackup?->created_at?->toIso8601String(),
                'cron_last_heartbeat' => Cache::get('syncviet.cron.last_heartbeat'),
            ],
        ];
    }

    /**
     * @return array{
     *     labels: list<string>,
     *     users: list<int>,
     *     posts: list<int>,
     *     comments: list<int>,
     *     deposits: list<int>,
     *     adSpend: list<int>
     * }
     */
    private function buildDailySeries(int $days): array
    {
        $from = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $labels = [];

        for ($i = 0; $i < $days; $i++) {
            $labels[] = $from->addDays($i)->toDateString();
        }

        $usersMap = $this->aggregateDailyCounts('users', $from);
        $postsMap = $this->aggregateDailyCounts('posts', $from);
        $commentsMap = $this->aggregateDailyCounts('comments', $from);

        $depositsMap = [];
        $depositRows = Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->where('created_at', '>=', $from)
            ->get(['created_at', 'amount']);

        foreach ($depositRows as $row) {
            $day = $row->created_at?->toDateString();
            if ($day !== null) {
                $depositsMap[$day] = ($depositsMap[$day] ?? 0) + $row->amount;
            }
        }

        $spendMap = [];
        $spendRows = AdCreditEntry::query()
            ->where('type', 'spend')
            ->where('created_at', '>=', $from)
            ->get(['created_at', 'amount']);

        foreach ($spendRows as $row) {
            $day = $row->created_at->toDateString();
            $spendMap[$day] = ($spendMap[$day] ?? 0) + $row->amount;
        }

        return [
            'labels' => $labels,
            'users' => array_map(static fn (string $d): int => $usersMap[$d] ?? 0, $labels),
            'posts' => array_map(static fn (string $d): int => $postsMap[$d] ?? 0, $labels),
            'comments' => array_map(static fn (string $d): int => $commentsMap[$d] ?? 0, $labels),
            'deposits' => array_map(static fn (string $d): int => $depositsMap[$d] ?? 0, $labels),
            'adSpend' => array_map(static fn (string $d): int => $spendMap[$d] ?? 0, $labels),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function aggregateDailyCounts(string $table, CarbonImmutable $from): array
    {
        $rows = DB::table($table)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->day] = (int) $row->total;
        }

        return $map;
    }
}
