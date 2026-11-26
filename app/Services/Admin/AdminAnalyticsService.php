<?php

namespace App\Services\Admin;

use App\Enums\AccountType;
use App\Enums\AdCampaignStatus;
use App\Enums\ContentOrigin;
use App\Enums\PaymentStatus;
use App\Enums\PolicyStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AdCampaign;
use App\Models\AdCreditEntry;
use App\Models\AiAgent;
use App\Models\AiCredential;
use App\Models\Appeal;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\Comment;
use App\Models\Community;
use App\Models\GrowthFraudReview;
use App\Models\LoginHistory;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Report;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Ai\AiAutomationControlService;
use App\Services\Seo\SeoConfigService;
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

        $humanUsers = static fn () => User::query()->where('account_type', AccountType::Human->value);

        $metrics = [
            'total_users' => $humanUsers()->count(),
            'new_users_7d' => $humanUsers()->where('created_at', '>=', $weekAgo)->count(),
            'active_users' => $humanUsers()->where('status', UserStatus::Active->value)->count(),
            'verified_users' => $humanUsers()->whereNotNull('email_verified_at')->count(),
            'unverified_users' => $humanUsers()->whereNull('email_verified_at')->count(),
            'staff_users' => $humanUsers()->whereIn('role', [UserRole::Admin->value, UserRole::Moderator->value])->count(),
            'suspended_users' => $humanUsers()->where('status', UserStatus::Suspended->value)->count(),
            'banned_users' => $humanUsers()->where('status', UserStatus::Banned->value)->count(),
            'dau' => $humanUsers()->where('last_activity_at', '>=', $dayAgo)->count(),
            'mau' => $humanUsers()->where('last_activity_at', '>=', $monthAgo)->count(),
            'ai_agents_count' => Schema::hasTable('ai_agents') ? AiAgent::query()->where('is_enabled', true)->count() : 0,
            'ai_posts_count' => Post::query()->where('content_origin', ContentOrigin::AiGenerated->value)->count(),
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

        $curUsers = User::query()->where('account_type', AccountType::Human->value)->where('is_test_account', false)->where('created_at', '>=', $currentStart)->count();
        $prevUsers = User::query()->where('account_type', AccountType::Human->value)->where('is_test_account', false)->whereBetween('created_at', [$prevStart, $currentStart])->count();
        $curPosts = Post::query()->where('created_at', '>=', $currentStart)->count();
        $prevPosts = Post::query()->whereBetween('created_at', [$prevStart, $currentStart])->count();

        return [
            'period' => $safeDays,
            'totals' => [
                'users' => User::query()->where('account_type', AccountType::Human->value)->where('is_test_account', false)->count(),
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

        $cacheOk = false;
        try {
            $probeKey = 'syncviet.health.cache_probe';
            Cache::put($probeKey, 'ok', now()->addSeconds(30));
            $cacheOk = Cache::get($probeKey) === 'ok';
        } catch (\Throwable) {
            $cacheOk = false;
        }

        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $activeSessions = Schema::hasTable('sessions') ? DB::table('sessions')->where('last_activity', '>=', now()->subMinutes(30)->timestamp)->count() : 0;
        $backupsCount = Schema::hasTable('backup_records') ? BackupRecord::query()->count() : 0;
        $lastBackup = Schema::hasTable('backup_records') ? BackupRecord::query()->where('status', 'completed')->latest('id')->first() : null;

        $diskFreeBytes = false;
        $diskTotalBytes = false;
        try {
            if (function_exists('disk_free_space')) {
                $diskFreeBytes = @disk_free_space(storage_path());
            }
            if (function_exists('disk_total_space')) {
                $diskTotalBytes = @disk_total_space(storage_path());
            }
        } catch (\Throwable) {
            $diskFreeBytes = false;
            $diskTotalBytes = false;
        }

        $enabledPaymentMethods = Schema::hasTable('payment_methods')
            ? PaymentMethod::query()->where('is_enabled', true)->pluck('code')->all()
            : [];
        $gscConnected = Schema::hasTable('seo_settings')
            && app(SeoConfigService::class)->bool('gsc_enabled', false)
            && app(SeoConfigService::class)->string('gsc_property_url', '') !== '';
        $aiHealthyKeys = Schema::hasTable('ai_credentials')
            ? AiCredential::query()->where('is_enabled', true)->where('health_status', 'healthy')->count()
            : 0;
        $aiEmergencyStopped = Schema::hasTable('ai_automation_settings')
            && app(AiAutomationControlService::class)->isEmergencyStopped();

        $failedLogins24h = Schema::hasTable('login_histories')
            ? LoginHistory::query()->where('event_type', 'failed')->where('created_at', '>=', now()->subHours(24))->count()
            : 0;
        $pendingFraudReviews = Schema::hasTable('growth_fraud_reviews')
            ? GrowthFraudReview::query()->where('status', 'pending')->count()
            : 0;

        return [
            'health' => [
                'app_status' => 'healthy',
                'db_ok' => true,
                'cache_ok' => $cacheOk,
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
                'trusted_proxies_configured' => config('syncviet.trusted_proxies') !== null && trim((string) (is_array(config('syncviet.trusted_proxies')) ? implode(',', config('syncviet.trusted_proxies')) : config('syncviet.trusted_proxies'))) !== '',
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'active_sessions_30m' => $activeSessions,
                'media_files_count' => PostMedia::query()->count(),
                'media_storage_bytes' => (int) PostMedia::query()->sum('size_bytes'),
                'disk_free_bytes' => is_float($diskFreeBytes) ? (int) $diskFreeBytes : null,
                'disk_total_bytes' => is_float($diskTotalBytes) ? (int) $diskTotalBytes : null,
                'backups_count' => $backupsCount,
                'last_backup_at' => $lastBackup?->created_at?->toIso8601String(),
                'last_backup_type' => $lastBackup?->type,
                'last_backup_status' => $lastBackup !== null ? $lastBackup->status : 'none',
                'cron_last_heartbeat' => Cache::get('syncviet.cron.last_heartbeat'),
                'integrations' => [
                    'payment_methods_enabled' => $enabledPaymentMethods,
                    'webhook_secret_configured' => trim((string) config('syncviet.payment_webhook_secret', '')) !== '',
                    'gsc_connected' => $gscConnected,
                    'ai_healthy_keys' => $aiHealthyKeys,
                    'ai_emergency_stopped' => $aiEmergencyStopped,
                ],
                'security_alerts' => [
                    'failed_logins_24h' => $failedLogins24h,
                    'pending_fraud_reviews' => $pendingFraudReviews,
                ],
                'host_telemetry' => [
                    'cpu_usage' => null,
                    'ram_usage' => null,
                    'php_workers' => null,
                    'note' => 'Unavailable via Shared Hosting PHP runtime — inspect real-time CPU, RAM, Entry Processes, and PHP Workers directly in Hostinger hPanel.',
                ],
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
        $query = DB::table($table)
            ->where('created_at', '>=', $from);

        if ($table === 'users') {
            $query->where('account_type', AccountType::Human->value)
                ->where('is_test_account', false);
        }

        $rows = $query
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
