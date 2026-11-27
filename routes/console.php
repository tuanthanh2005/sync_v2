<?php

use App\Enums\SanctionType;
use App\Enums\UserStatus;
use App\Jobs\Ai\GenerateAiContentJob;
use App\Models\AdminNotification;
use App\Models\AiContentPlan;
use App\Models\User;
use App\Models\UserSanction;
use App\Notifications\SocialActivityNotification;
use App\Services\Admin\DatabaseBackupService;
use App\Services\Ai\AiAutomationControlService;
use App\Services\Ai\AiPublishingAndSchedulerService;
use App\Services\Auth\PendingRegistrationService;
use App\Services\Growth\GrowthAnalyticsService;
use App\Services\Growth\ReferralService;
use App\Services\Growth\RetentionAndDigestService;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Monetization\BoostPostService;
use App\Services\Monetization\MonetizationPaymentService;
use App\Services\Monetization\PremiumSubscriptionService;
use App\Services\Policy\PolicyManagementService;
use App\Services\Security\AuditLogger;
use App\Services\Security\RbacService;
use App\Services\Seo\GoogleSearchConsoleService;
use App\Services\Seo\IndexNowService;
use App\Services\Seo\KeywordIntelligenceService;
use App\Services\Seo\SeoAuditService;
use App\Services\Seo\SeoRegistryService;
use App\Services\Seo\SitemapEngineService;
use App\Services\Social\PostViewTrackingService;
use App\Services\System\LanguageService;
use Database\Seeders\AiContentCenterSeeder;
use Database\Seeders\GrowthSeeder;
use Database\Seeders\MonetizationSeeder;
use Database\Seeders\SeoSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:rbac:sync', function (
    RbacService $rbacService,
    LanguageService $languageService,
    PolicyManagementService $policyService,
): void {
    $rbacService->syncCatalog();
    $languageService->ensureSeeded();
    $policyService->ensureDefaultPoliciesExist();
    MonetizationSeeder::seedDefaultsIfEmpty();
    SeoSeeder::seedDefaultsIfEmpty();
    AiContentCenterSeeder::seedDefaultsIfEmpty();
    GrowthSeeder::seedDefaultsIfEmpty();
    $this->info('RBAC catalog, 15 platform languages, draft policies, monetization, SEO engine, AI Content Center, and Growth defaults synced.');
})->purpose('Synchronize RBAC roles, permissions, languages, draft policies, monetization, SEO, and AI defaults');

Artisan::command('app:monetization:sync', function (
    PremiumSubscriptionService $premiumService,
    BannerAdvertisingService $bannerService,
    BoostPostService $boostService,
    MonetizationPaymentService $paymentService,
): void {
    $premium = $premiumService->processExpirationsAndReminders();
    $banners = $bannerService->syncScheduledAndExpiredBanners();
    $completedBoosts = $boostService->completeElapsedBoosts();
    $expiredOrders = $paymentService->expireStalePendingOrders();

    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info(sprintf(
        'Monetization sync: premium_expired=%d reminders=%d banners_activated=%d banners_expired=%d boosts_completed=%d orders_expired=%d',
        $premium['expired'],
        $premium['reminders_sent'],
        $banners['activated'],
        $banners['expired'],
        $completedBoosts,
        $expiredOrders,
    ));
})->purpose('Expire elapsed subscriptions/banners/boosts/orders and dispatch deduplicated Premium reminders');

Artisan::command('app:sanctions:expire', function (AuditLogger $auditLogger): void {
    if (! Schema::hasTable('user_sanctions')) {
        return;
    }

    $now = now();
    $expiredCount = 0;

    User::query()
        ->where('status', UserStatus::Suspended->value)
        ->whereNotNull('suspended_until')
        ->where('suspended_until', '<=', $now)
        ->chunkById(100, static function ($users) use (&$expiredCount, $auditLogger): void {
            foreach ($users as $user) {
                $user->forceFill([
                    'status' => UserStatus::Active,
                    'suspended_until' => null,
                ])->save();

                $auditLogger->log('sanction.suspension_expired', $user, ['automatic' => true]);
                $expiredCount++;
            }
        });

    $restrictionColumns = [
        'can_post' => 'post_restricted_until',
        'can_comment' => 'comment_restricted_until',
        'can_create_community' => 'community_restricted_until',
        'can_message' => 'message_restricted_until',
        'can_advertise' => 'ad_restricted_until',
    ];

    foreach ($restrictionColumns as $flag => $untilCol) {
        User::query()
            ->where($flag, false)
            ->whereNotNull($untilCol)
            ->where($untilCol, '<=', $now)
            ->chunkById(100, static function ($users) use ($flag, $untilCol, &$expiredCount): void {
                foreach ($users as $user) {
                    $user->forceFill([
                        $flag => true,
                        $untilCol => null,
                    ])->save();
                    $expiredCount++;
                }
            });
    }

    UserSanction::query()
        ->where('is_active', true)
        ->whereNotNull('ends_at')
        ->where('ends_at', '<=', $now)
        ->whereIn('type', [
            SanctionType::Suspend->value,
            SanctionType::RestrictPost->value,
            SanctionType::RestrictComment->value,
            SanctionType::RestrictCommunity->value,
            SanctionType::RestrictMessage->value,
            SanctionType::RestrictAd->value,
        ])
        ->update(['is_active' => false]);

    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info("Expired sanctions processed: {$expiredCount}");
})->purpose('Automatically lift elapsed temporary suspensions and capability restrictions');

Artisan::command('app:notifications:dispatch', function (): void {
    if (! Schema::hasTable('admin_notifications')) {
        return;
    }

    $pending = AdminNotification::query()
        ->where('status', 'scheduled')
        ->whereNotNull('scheduled_at')
        ->where('scheduled_at', '<=', now())
        ->limit(10)
        ->get();

    foreach ($pending as $broadcast) {
        $query = User::query()->where('status', UserStatus::Active->value);

        if ($broadcast->audience === 'user' && $broadcast->target_user_id !== null) {
            $query->whereKey($broadcast->target_user_id);
        } elseif ($broadcast->audience === 'staff') {
            $query->whereIn('role', ['admin', 'moderator']);
        } elseif ($broadcast->audience === 'verified') {
            $query->whereNotNull('email_verified_at');
        }

        $count = 0;

        $query->chunkById(200, static function ($users) use ($broadcast, &$count): void {
            foreach ($users as $user) {
                $user->notify(new SocialActivityNotification(
                    eventKey: "admin.{$broadcast->category}",
                    titleVi: $broadcast->title,
                    titleEn: $broadcast->title,
                    url: $broadcast->action_url ?: '/',
                    actorName: config('app.name', 'SyncViet'),
                    actorUsername: 'system',
                ));
                $count++;
            }
        });

        $broadcast->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'recipients_count' => $count,
        ])->save();
    }

    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
})->purpose('Dispatch due scheduled administrative notifications');

Artisan::command('app:seo:backfill', function (
    SeoRegistryService $registry,
    SitemapEngineService $sitemaps,
): void {
    $reg = $registry->backfillAll();
    $map = $sitemaps->rebuildAll();
    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info(sprintf(
        'SEO backfill complete: processed=%d eligible=%d sitemaps=%d shards=%d sitemap_urls=%d',
        $reg['processed'],
        $reg['eligible'],
        $map['sitemaps'],
        $map['shards'],
        $map['urls'],
    ));
})->purpose('Backfill all public entities into seo_url_registry and rebuild all XML sitemap shards');

Artisan::command('app:seo:sync', function (
    SitemapEngineService $sitemaps,
    IndexNowService $indexNow,
): void {
    $map = $sitemaps->rebuildDirty();
    $idx = $indexNow->submitQueuedBatch(100);
    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info(sprintf(
        'SEO sync: dirty_sitemaps=%d shards=%d urls=%d indexnow_submitted=%d indexnow_failed=%d',
        $map['sitemaps'],
        $map['shards'],
        $map['urls'],
        $idx['submitted'],
        $idx['failed'],
    ));
})->purpose('Rebuild dirty sitemap shards and submit queued IndexNow batches');

Artisan::command('app:seo:inspect', function (GoogleSearchConsoleService $gsc): void {
    $res = $gsc->inspectPriorityBatch();
    $this->info(sprintf('SEO inspect: ok=%s inspected=%d remaining_quota=%d (%s)', $res['ok'] ? 'yes' : 'no', $res['inspected'], $res['quota_remaining'], $res['message']));
})->purpose('Run a bounded priority batch against Google Search Console URL Inspection API');

Artisan::command('app:seo:analytics', function (
    GoogleSearchConsoleService $gsc,
    KeywordIntelligenceService $keywords,
): void {
    $res = $gsc->syncSearchAnalytics(28);
    $rec = $res['ok'] ? $keywords->refreshRecommendations(28) : ['generated' => 0];
    $this->info(sprintf('SEO analytics: ok=%s rows=%d recommendations=%d (%s)', $res['ok'] ? 'yes' : 'no', $res['rows_synced'], $rec['generated'], $res['message']));
})->purpose('Sync Google Search Console Search Analytics and refresh keyword intelligence recommendations');

Artisan::command('app:seo:audit', function (SeoAuditService $audit): void {
    $run = $audit->runAudit('scheduled');
    $this->info(sprintf(
        'SEO audit #%d finished: technical_health_score=%d/100 critical=%d high=%d medium=%d low=%d',
        $run->id,
        $run->technical_health_score,
        $run->critical_count,
        $run->high_count,
        $run->medium_count,
        $run->low_count,
    ));
})->purpose('Execute automated technical SEO audit checks and persist findings');

Artisan::command('app:ai:publish-scheduled', function (AiPublishingAndSchedulerService $scheduler): void {
    $res = $scheduler->processDueSchedules(2);
    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info(sprintf(
        'AI scheduler: published=%d skipped_stale=%d failed=%d',
        $res['published'],
        $res['skipped'],
        $res['failed'],
    ));
})->purpose('Publish due human-approved AI scheduled posts respecting Emergency Stop and anti-flood limits');

Artisan::command('app:ai:run-plans', function (AiAutomationControlService $control): void {
    if (! Schema::hasTable('ai_content_plans') || ! $control->isSwitchEnabled('ai_generation_enabled')) {
        $this->info('AI content plan runner skipped (disabled or Emergency Stop active).');

        return;
    }

    $duePlans = AiContentPlan::query()
        ->where('is_active', true)
        ->whereNotNull('agent_id')
        ->orderBy('last_generated_at')
        ->limit(10)
        ->get();

    $dispatched = 0;
    foreach ($duePlans as $plan) {
        if ($dispatched >= 2) {
            break;
        }

        if ($plan->last_generated_at !== null && $plan->last_generated_at->diffInMinutes(now()) < max(60, $plan->min_interval_minutes)) {
            continue;
        }

        if ($plan->agent_id === null) {
            continue;
        }

        $plan->forceFill([
            'last_generated_at' => now(),
        ])->save();

        GenerateAiContentJob::dispatch([
            'agent_id' => $plan->agent_id,
            'plan_id' => $plan->id,
            'community_id' => $plan->community_id,
            'topic' => $plan->topic,
            'language_code' => $plan->target_language,
            'content_type' => $plan->content_type,
            'category' => $plan->category,
            'manual_trigger' => false,
        ]);
        $dispatched++;
    }

    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info("AI content plans dispatched: {$dispatched}");
})->purpose('Dispatch bounded due AI content plan draft generation jobs respecting Emergency Stop');

Artisan::command('app:growth:qualify-referrals', function (ReferralService $referrals): void {
    $res = $referrals->processPendingQualifications(100);
    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info(sprintf(
        'Growth referrals: checked=%d qualified=%d under_review=%d rejected=%d rewards_granted=%d',
        $res['checked'],
        $res['qualified'],
        $res['under_review'],
        $res['rejected'],
        $res['rewards_granted'],
    ));
})->purpose('Evaluate due pending referrals, run fraud signals, and grant cumulative milestone rewards');

Artisan::command('app:growth:snapshot-analytics', function (GrowthAnalyticsService $analytics): void {
    $row = $analytics->snapshotDailyMetrics();
    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info("Growth analytics snapshot saved for {$row->date}: humans={$row->total_human_users} dau={$row->dau_human} qualified_refs={$row->qualified_referrals}");
})->purpose('Precompute daily human-only Growth Analytics and cohort retention metrics');

Artisan::command('app:growth:retention', function (RetentionAndDigestService $retention): void {
    $re = $retention->processInactiveReengagement(50);
    $dig = $retention->processWeeklyDigests(50);
    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info(sprintf('Growth retention: reengagement_sent=%d digest_sent=%d', $re['sent'], $dig['sent']));
})->purpose('Send frequency-capped retention digests and inactive user re-engagement notifications');

Artisan::command('app:growth:cleanup', function (ReferralService $referrals): void {
    $deleted = $referrals->cleanupExpiredVisits();
    $this->info("Growth cleanup: expired referral visits purged={$deleted}");
})->purpose('Purge expired referral visit tracking rows per retention policy');

Artisan::command('app:backup:database', function (DatabaseBackupService $backup): void {
    $record = $backup->createDatabaseBackup(null, 'Automated scheduled database backup');
    $pruned = $backup->pruneOldBackups();
    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info(sprintf(
        'Database backup completed: file=%s size=%d bytes sha256=%s pruned=%d',
        basename($record->path),
        $record->size_bytes,
        substr((string) $record->checksum, 0, 12),
        $pruned,
    ));
})->purpose('Create a private gzip-compressed logical SQL backup with SHA-256 checksum and prune expired archives');

Artisan::command('app:views:prune', function (PostViewTrackingService $views): void {
    $deleted = $views->pruneOldEvents();
    $this->info("Post view events pruned: {$deleted}");
})->purpose('Prune expired raw post view events per retention policy while preserving post view counters');

Artisan::command('app:auth:cleanup-pending', function (PendingRegistrationService $pending): void {
    $res = $pending->cleanupExpired();
    Cache::put('syncviet.cron.last_heartbeat', now()->toIso8601String(), now()->addHours(2));
    $this->info(sprintf(
        'Auth pending cleanup: expired=%d pending_deleted=%d challenges_deleted=%d',
        $res['pending_expired'],
        $res['pending_deleted'],
        $res['challenges_deleted'],
    ));
})->purpose('Expire and batch-purge stale pending registrations and OTP verification challenges without touching real users');

Artisan::command('app:auth:remediate-unverified {--dry-run : Audit only without revoking active sessions}', function (PendingRegistrationService $pending): void {
    $revoke = ! (bool) $this->option('dry-run');
    $res = $pending->auditAndRemediateUnverifiedUsers(revokeSessions: $revoke);
    $this->info(sprintf(
        'Unverified accounts audit: total=%d with_posts=%d with_payments=%d with_rewards=%d sessions_revoked=%d remember_tokens_rotated=%d',
        $res['total_unverified'],
        $res['with_posts'],
        $res['with_payments'],
        $res['with_rewards'],
        $res['sessions_revoked'],
        $res['remember_tokens_rotated'],
    ));
})->purpose('Audit existing unverified accounts and revoke their active sessions & remember tokens without deleting real data');

Schedule::command('app:auth:cleanup-pending')->hourly()->withoutOverlapping();

Schedule::command('app:sanctions:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('app:monetization:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('app:notifications:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('app:seo:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('app:seo:inspect')->hourly()->withoutOverlapping();
Schedule::command('app:seo:analytics')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('app:seo:audit')->weeklyOn(1, '04:00')->withoutOverlapping();
Schedule::command('app:ai:publish-scheduled')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('app:ai:run-plans')->hourly()->withoutOverlapping();
Schedule::command('app:growth:qualify-referrals')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('app:growth:snapshot-analytics')->hourly()->withoutOverlapping();
Schedule::command('app:growth:retention')->dailyAt('09:30')->withoutOverlapping();
Schedule::command('app:growth:cleanup')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('app:views:prune')->dailyAt('02:45')->withoutOverlapping();
Schedule::command('app:backup:database')->dailyAt('02:00')->withoutOverlapping();
