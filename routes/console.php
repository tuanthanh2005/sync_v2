<?php

use App\Enums\SanctionType;
use App\Enums\UserStatus;
use App\Models\AdminNotification;
use App\Models\User;
use App\Models\UserSanction;
use App\Notifications\SocialActivityNotification;
use App\Services\Security\AuditLogger;
use App\Services\Security\RbacService;
use App\Services\System\LanguageService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:rbac:sync', function (RbacService $rbacService, LanguageService $languageService): void {
    $rbacService->syncCatalog();
    $languageService->ensureSeeded();
    $this->info('RBAC catalog and 15 platform languages synced.');
})->purpose('Synchronize RBAC roles, permissions, and supported platform languages');

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

Schedule::command('app:sanctions:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('app:notifications:dispatch')->everyMinute()->withoutOverlapping();
