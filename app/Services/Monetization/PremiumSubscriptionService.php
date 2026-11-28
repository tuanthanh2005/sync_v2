<?php

namespace App\Services\Monetization;

use App\Enums\PaymentServiceType;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\PremiumPlan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PremiumSubscriptionService
{
    public function __construct(
        private readonly MonetizationPaymentService $paymentService,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function publicPlans(): array
    {
        if (! Schema::hasTable('premium_plans')) {
            return [];
        }

        MonetizationSeeder::seedDefaultsIfEmpty();

        return array_values(
            PremiumPlan::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('duration_days')
                ->get()
                ->map(static fn (PremiumPlan $plan): array => self::serializePlan($plan))
                ->all(),
        );
    }

    /**
     * Create a direct checkout payment order for a SysNoxis Premium plan.
     * Premium is NEVER auto-deducted from Advertising Balance.
     */
    public function createPurchaseOrder(
        User $user,
        PremiumPlan $plan,
        string $currency,
        string $provider,
        string $idempotencyKey,
        string $platform = 'web',
    ): Payment {
        if (! $this->settings->featureEnabled('feature_premium')) {
            throw ValidationException::withMessages([
                'plan_id' => [__('SysNoxis Premium subscriptions are currently disabled.')],
            ]);
        }

        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan_id' => [__('The selected Premium plan is no longer available.')],
            ]);
        }

        $cleanCurrency = strtoupper(trim($currency));
        $price = $plan->effectivePrice($cleanCurrency);

        return $this->paymentService->createOrder(
            user: $user,
            serviceType: PaymentServiceType::Premium,
            amount: $price,
            currency: $cleanCurrency,
            provider: $provider,
            idempotencyKey: $idempotencyKey,
            platform: $platform,
            payableType: PremiumPlan::class,
            payableId: $plan->id,
            metadata: [
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'plan_name' => $plan->name,
                'duration_days' => $plan->duration_days,
            ],
        );
    }

    /**
     * Activate or extend a user's Premium membership strictly once per settled payment.
     * Extending an active membership adds days onto existing expires_at (zero lost days).
     */
    public function activateFromPayment(Payment $payment, ?User $actor = null): Subscription
    {
        return DB::transaction(function () use ($payment, $actor): Subscription {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($payment->user_id)->lockForUpdate()->firstOrFail();

            /** @var SubscriptionEvent|null $existingEvent */
            $existingEvent = SubscriptionEvent::query()
                ->where('payment_id', $payment->id)
                ->whereIn('event_type', ['activated', 'extended'])
                ->first();

            if ($existingEvent !== null) {
                return Subscription::query()->findOrFail($existingEvent->subscription_id);
            }

            $planId = (int) ($payment->payable_id ?? ($payment->metadata['plan_id'] ?? 0));
            /** @var PremiumPlan $plan */
            $plan = PremiumPlan::query()->findOrFail($planId);

            $now = now();
            $previousExpiresAt = $lockedUser->premium_until;
            $hasActiveTime = $previousExpiresAt !== null && $previousExpiresAt->isFuture() && $lockedUser->premium_suspended_at === null;
            $baseStart = $hasActiveTime ? $previousExpiresAt->copy() : $now->copy();
            $newExpiresAt = $baseStart->copy()->addDays($plan->duration_days);

            $subscription = new Subscription([
                'metadata' => [
                    'plan_slug' => $plan->slug,
                    'order_code' => $payment->order_code,
                    'extended_from' => $hasActiveTime ? $previousExpiresAt->toIso8601String() : null,
                ],
            ]);
            $subscription->user_id = $lockedUser->id;
            $subscription->premium_plan_id = $plan->id;
            $subscription->payment_id = $payment->id;
            $subscription->status = SubscriptionStatus::Active;
            $subscription->source = $hasActiveTime ? 'renewal' : 'purchase';
            $subscription->currency = $payment->currency;
            $subscription->amount_paid = $payment->amount;
            $subscription->starts_at = $now;
            $subscription->expires_at = $newExpiresAt;
            $subscription->save();

            // Supersede older active subscription rows so only the newest active row tracks expiry reminders.
            Subscription::query()
                ->where('user_id', $lockedUser->id)
                ->where('id', '!=', $subscription->id)
                ->where('status', SubscriptionStatus::Active->value)
                ->update(['status' => SubscriptionStatus::Expired->value]);

            $lockedUser->forceFill([
                'premium_until' => $newExpiresAt,
                'premium_suspended_at' => null,
            ])->save();

            $event = new SubscriptionEvent([
                'event_type' => $hasActiveTime ? 'extended' : 'activated',
                'days_delta' => $plan->duration_days,
                'previous_expires_at' => $previousExpiresAt,
                'new_expires_at' => $newExpiresAt,
                'note' => $hasActiveTime
                    ? __('Extended Premium by :days days via order :code', ['days' => $plan->duration_days, 'code' => $payment->order_code ?? $payment->id])
                    : __('Activated Premium (:plan) via order :code', ['plan' => $plan->name, 'code' => $payment->order_code ?? $payment->id]),
            ]);
            $event->subscription_id = $subscription->id;
            $event->user_id = $lockedUser->id;
            $event->payment_id = $payment->id;
            $event->actor_id = $actor !== null ? $actor->id : $lockedUser->id;
            $event->created_at = $now;
            $event->save();

            $this->auditLogger->log(
                $hasActiveTime ? 'premium.extended' : 'premium.activated',
                $subscription,
                [
                    'user_id' => $lockedUser->id,
                    'plan_slug' => $plan->slug,
                    'duration_days' => $plan->duration_days,
                    'new_expires_at' => $newExpiresAt->toIso8601String(),
                    'payment_id' => $payment->id,
                ],
                $actor ?? $lockedUser,
            );

            $lockedUser->notify(new SocialActivityNotification(
                eventKey: 'premium_activated',
                titleVi: $hasActiveTime
                    ? "Đã gia hạn SysNoxis Premium thêm {$plan->duration_days} ngày (hết hạn {$newExpiresAt->format('d/m/Y')})."
                    : "SysNoxis Premium ({$plan->name}) đã được kích hoạt đến {$newExpiresAt->format('d/m/Y')}.",
                titleEn: $hasActiveTime
                    ? "Your SysNoxis Premium was extended by {$plan->duration_days} days (expires {$newExpiresAt->format('Y-m-d')})."
                    : "SysNoxis Premium ({$plan->name}) is now active until {$newExpiresAt->format('Y-m-d')}.",
                url: route('account.premium'),
                actorName: (string) config('app.name', 'SysNoxis'),
                actorUsername: 'system',
                titleKey: $hasActiveTime
                    ? 'Your SysNoxis Premium was extended by :days days (expires :date).'
                    : 'SysNoxis Premium (:plan) is now active until :date.',
                titleParams: $hasActiveTime
                    ? ['days' => $plan->duration_days, 'date' => $newExpiresAt->format('Y-m-d')]
                    : ['plan' => $plan->name, 'date' => $newExpiresAt->format('Y-m-d')],
            ));

            return $subscription;
        });
    }

    /**
     * Grant promotional / referral / early-member Premium time through the existing Subscription system.
     * - NEVER creates a fake Payment or inflates financial revenue.
     * - Stacks cleanly on top of any existing active paid Premium (zero lost paid days).
     * - Tracks `promotional_days` explicitly so selective revocation never removes paid Premium time.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function grantPromotionalPremium(
        User $target,
        int $durationDays,
        string $source,
        string $reason,
        ?User $actor = null,
        array $metadata = [],
    ): Subscription {
        $allowedSources = ['referral_reward', 'early_member_reward', 'founding_member', 'promotional_campaign', 'admin_grant'];
        $cleanSource = in_array($source, $allowedSources, true) ? $source : 'promotional_campaign';
        $days = max(1, min(1095, $durationDays));

        MonetizationSeeder::seedDefaultsIfEmpty();

        /** @var PremiumPlan $plan */
        $plan = PremiumPlan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->firstOrFail();

        return DB::transaction(function () use ($target, $plan, $days, $cleanSource, $reason, $actor, $metadata): Subscription {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $now = now();
            $previousExpiresAt = $lockedUser->premium_until;
            $hasActiveTime = $previousExpiresAt !== null && $previousExpiresAt->isFuture() && $lockedUser->premium_suspended_at === null;
            $baseStart = $hasActiveTime ? $previousExpiresAt->copy() : $now->copy();
            $newExpiresAt = $baseStart->copy()->addDays($days);

            $subscription = new Subscription([
                'reason' => trim($reason),
                'metadata' => [
                    ...$metadata,
                    'source' => $cleanSource,
                    'promotional_days' => $days,
                    'stacked_on_existing' => $hasActiveTime,
                ],
            ]);
            $subscription->user_id = $lockedUser->id;
            $subscription->premium_plan_id = $plan->id;
            $subscription->payment_id = null;
            $subscription->status = SubscriptionStatus::Active;
            $subscription->source = $cleanSource;
            $subscription->currency = 'USD';
            $subscription->amount_paid = 0;
            $subscription->promotional_days = $days;
            $subscription->starts_at = $baseStart;
            $subscription->expires_at = $newExpiresAt;
            $subscription->granted_by = $actor?->id;
            $subscription->save();

            $lockedUser->forceFill([
                'premium_until' => $newExpiresAt,
                'premium_suspended_at' => null,
            ])->save();

            $event = new SubscriptionEvent([
                'event_type' => 'promotional_grant',
                'days_delta' => $days,
                'previous_expires_at' => $previousExpiresAt,
                'new_expires_at' => $newExpiresAt,
                'note' => "[{$cleanSource}] {$reason}",
            ]);
            $event->subscription_id = $subscription->id;
            $event->user_id = $lockedUser->id;
            $event->actor_id = $actor !== null ? $actor->id : $lockedUser->id;
            $event->created_at = $now;
            $event->save();

            $this->auditLogger->log(
                "premium.{$cleanSource}.granted",
                $subscription,
                [
                    'target_user_id' => $lockedUser->id,
                    'source' => $cleanSource,
                    'days' => $days,
                    'new_expires_at' => $newExpiresAt->toIso8601String(),
                    'reason' => $reason,
                ],
                $actor ?? $lockedUser,
            );

            $lockedUser->notify(new SocialActivityNotification(
                eventKey: 'premium_reward_granted',
                titleVi: "Bạn nhận được +{$days} ngày SysNoxis Premium miễn phí ({$reason}).",
                titleEn: "You received +{$days} complimentary days of SysNoxis Premium ({$reason}).",
                url: route('account.premium'),
                actorName: (string) config('app.name', 'SysNoxis'),
                actorUsername: 'system',
                titleKey: 'You received +:days complimentary days of SysNoxis Premium (:reason).',
                titleParams: ['days' => $days, 'reason' => $reason],
            ));

            return $subscription;
        });
    }

    /**
     * Selectively revoke a promotional Premium grant without EVER removing legally purchased Premium time.
     */
    public function revokePromotionalPremium(Subscription $subscription, string $reason, User $actor): Subscription
    {
        if ($subscription->payment_id !== null || $subscription->amount_paid > 0) {
            throw ValidationException::withMessages([
                'subscription' => [__('Cannot revoke a paid Premium subscription via promotional clawback.')],
            ]);
        }

        $cleanReason = trim($reason) !== '' ? trim($reason) : 'Promotional reward revoked';

        return DB::transaction(function () use ($subscription, $cleanReason, $actor): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SubscriptionStatus::Revoked) {
                return $locked;
            }

            $now = now();
            $daysToDeduct = max(0, $locked->promotional_days);
            $previousUntil = $lockedUser->premium_until;

            $locked->status = SubscriptionStatus::Revoked;
            $locked->revoked_at = $now;
            $locked->reason = $cleanReason;
            $locked->save();

            // Preserve any active paid subscription expiration floor strictly!
            /** @var Subscription|null $latestPaid */
            $latestPaid = Subscription::query()
                ->where('user_id', $lockedUser->id)
                ->whereNotNull('payment_id')
                ->where('amount_paid', '>', 0)
                ->whereNotIn('status', [SubscriptionStatus::Revoked->value, SubscriptionStatus::Cancelled->value])
                ->orderByDesc('expires_at')
                ->first();

            $paidFloor = $latestPaid !== null && $latestPaid->expires_at->isFuture()
                ? $latestPaid->expires_at->copy()
                : null;

            $newUntil = $previousUntil?->copy()->subDays($daysToDeduct);

            if ($paidFloor !== null) {
                if ($newUntil === null || $newUntil->lessThan($paidFloor)) {
                    $newUntil = $paidFloor;
                }
            } elseif ($newUntil !== null && $newUntil->isPast()) {
                $newUntil = null;
            }

            $lockedUser->forceFill(['premium_until' => $newUntil])->save();

            $event = new SubscriptionEvent([
                'event_type' => 'promotional_revoked',
                'days_delta' => -$daysToDeduct,
                'previous_expires_at' => $previousUntil,
                'new_expires_at' => $newUntil ?? $now,
                'note' => $cleanReason,
            ]);
            $event->subscription_id = $locked->id;
            $event->user_id = $lockedUser->id;
            $event->actor_id = $actor->id;
            $event->created_at = $now;
            $event->save();

            $this->auditLogger->log(
                'premium.promotional.revoked',
                $locked,
                [
                    'target_user_id' => $lockedUser->id,
                    'days_deducted' => $daysToDeduct,
                    'preserved_paid_floor' => $paidFloor?->toIso8601String(),
                    'new_expires_at' => $newUntil?->toIso8601String(),
                    'reason' => $cleanReason,
                ],
                $actor,
            );

            return $locked;
        });
    }

    /**
     * Administratively grant promotional/comp Premium without fabricating any fake Payment record.
     */
    public function adminGrantPremium(
        User $actor,
        User $target,
        PremiumPlan $plan,
        int $durationDays,
        string $source,
        string $reason,
    ): Subscription {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages([
                'reason' => [__('An administrative reason is required.')],
            ]);
        }

        $cleanSource = in_array($source, ['admin_grant', 'promotion'], true) ? $source : 'admin_grant';
        $days = max(1, min(1095, $durationDays));

        return DB::transaction(function () use ($actor, $target, $plan, $days, $cleanSource, $cleanReason): Subscription {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $now = now();
            $previousExpiresAt = $lockedUser->premium_until;
            $hasActiveTime = $previousExpiresAt !== null && $previousExpiresAt->isFuture() && $lockedUser->premium_suspended_at === null;
            $newExpiresAt = ($hasActiveTime ? $previousExpiresAt->copy() : $now->copy())->addDays($days);

            $subscription = new Subscription([
                'reason' => $cleanReason,
                'metadata' => ['granted_days' => $days, 'source' => $cleanSource],
            ]);
            $subscription->user_id = $lockedUser->id;
            $subscription->premium_plan_id = $plan->id;
            $subscription->payment_id = null;
            $subscription->status = SubscriptionStatus::Active;
            $subscription->source = $cleanSource;
            $subscription->currency = 'VND';
            $subscription->amount_paid = 0;
            $subscription->starts_at = $now;
            $subscription->expires_at = $newExpiresAt;
            $subscription->granted_by = $actor->id;
            $subscription->save();

            Subscription::query()
                ->where('user_id', $lockedUser->id)
                ->where('id', '!=', $subscription->id)
                ->where('status', SubscriptionStatus::Active->value)
                ->update(['status' => SubscriptionStatus::Expired->value]);

            $lockedUser->forceFill([
                'premium_until' => $newExpiresAt,
                'premium_suspended_at' => null,
            ])->save();

            $event = new SubscriptionEvent([
                'event_type' => 'admin_grant',
                'days_delta' => $days,
                'previous_expires_at' => $previousExpiresAt,
                'new_expires_at' => $newExpiresAt,
                'note' => "[{$cleanSource}] {$cleanReason}",
            ]);
            $event->subscription_id = $subscription->id;
            $event->user_id = $lockedUser->id;
            $event->actor_id = $actor->id;
            $event->created_at = $now;
            $event->save();

            $this->auditLogger->log(
                'admin.premium.granted',
                $subscription,
                [
                    'target_user_id' => $lockedUser->id,
                    'source' => $cleanSource,
                    'days' => $days,
                    'new_expires_at' => $newExpiresAt->toIso8601String(),
                    'reason' => $cleanReason,
                ],
                $actor,
            );

            return $subscription;
        });
    }

    public function adminManageSubscription(
        User $actor,
        Subscription $subscription,
        string $action,
        string $reason,
        ?int $extraDays = null,
    ): Subscription {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages([
                'reason' => [__('A reason is required for subscription changes.')],
            ]);
        }

        return DB::transaction(function () use ($actor, $subscription, $action, $cleanReason, $extraDays): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            $previousExpiresAt = $locked->expires_at->copy();
            $daysDelta = 0;

            match ($action) {
                'extend' => (function () use ($locked, $lockedUser, $extraDays, &$daysDelta): void {
                    $daysDelta = max(1, min(365, (int) ($extraDays ?? 30)));
                    $base = $locked->expires_at->isFuture() ? $locked->expires_at->copy() : now();
                    $locked->expires_at = $base->addDays($daysDelta);
                    $locked->status = SubscriptionStatus::Active;
                    $locked->suspended_at = null;
                    $locked->revoked_at = null;
                    $locked->save();

                    $lockedUser->forceFill([
                        'premium_until' => $locked->expires_at,
                        'premium_suspended_at' => null,
                    ])->save();
                })(),
                'suspend' => (function () use ($locked, $lockedUser, $cleanReason): void {
                    $locked->status = SubscriptionStatus::Suspended;
                    $locked->suspended_at = now();
                    $locked->reason = $cleanReason;
                    $locked->save();

                    $lockedUser->forceFill(['premium_suspended_at' => now()])->save();
                })(),
                'restore' => (function () use ($locked, $lockedUser): void {
                    if ($locked->expires_at->isPast()) {
                        throw ValidationException::withMessages([
                            'action' => [__('Cannot restore an already expired subscription; extend it instead.')],
                        ]);
                    }
                    $locked->status = SubscriptionStatus::Active;
                    $locked->suspended_at = null;
                    $locked->revoked_at = null;
                    $locked->save();

                    $lockedUser->forceFill([
                        'premium_until' => $locked->expires_at,
                        'premium_suspended_at' => null,
                    ])->save();
                })(),
                'revoke' => (function () use ($locked, $lockedUser, $cleanReason): void {
                    $locked->status = SubscriptionStatus::Revoked;
                    $locked->revoked_at = now();
                    $locked->reason = $cleanReason;
                    $locked->save();

                    $lockedUser->forceFill([
                        'premium_until' => null,
                        'premium_suspended_at' => null,
                    ])->save();
                })(),
                default => throw ValidationException::withMessages([
                    'action' => [__('Unsupported subscription action.')],
                ]),
            };

            $event = new SubscriptionEvent([
                'event_type' => $action,
                'days_delta' => $daysDelta,
                'previous_expires_at' => $previousExpiresAt,
                'new_expires_at' => $locked->expires_at,
                'note' => $cleanReason,
            ]);
            $event->subscription_id = $locked->id;
            $event->user_id = $lockedUser->id;
            $event->actor_id = $actor->id;
            $event->created_at = now();
            $event->save();

            $this->auditLogger->log(
                "admin.premium.{$action}",
                $locked,
                ['target_user_id' => $lockedUser->id, 'days_delta' => $daysDelta, 'reason' => $cleanReason],
                $actor,
            );

            return $locked->fresh(['plan', 'user']) ?? $locked;
        });
    }

    public function userAdReductionMode(?User $user): string
    {
        if ($user === null || ! $user->isPremium() || ! Schema::hasTable('subscriptions')) {
            return 'none';
        }

        /** @var Subscription|null $active */
        $active = Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::Active->value)
            ->where('expires_at', '>', now())
            ->with('plan:id,ad_reduction_mode')
            ->latest('expires_at')
            ->first();

        return $active !== null && $active->plan !== null
            ? $active->plan->ad_reduction_mode
            : 'none';
    }

    /**
     * Cron task: expire elapsed subscriptions and send deduplicated 7d / 3d / 1d / expired reminders.
     *
     * @return array{expired: int, reminders_sent: int}
     */
    public function processExpirationsAndReminders(): array
    {
        if (! Schema::hasTable('subscriptions')) {
            return ['expired' => 0, 'reminders_sent' => 0];
        }

        $now = now();
        $expiredCount = 0;
        $remindersSent = 0;

        // 1. Mark expired subscriptions and clear user premium_until when no active future sub exists.
        Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('expires_at', '<=', $now)
            ->with('user')
            ->chunkById(100, static function ($subs) use (&$expiredCount, $now): void {
                foreach ($subs as $sub) {
                    $sub->status = SubscriptionStatus::Expired;
                    $sub->expired_notified_at ??= $now;
                    $sub->save();

                    if ($sub->user !== null && ($sub->user->premium_until === null || $sub->user->premium_until->isPast())) {
                        $sub->user->forceFill(['premium_until' => null])->save();
                        $sub->user->notify(new SocialActivityNotification(
                            eventKey: 'premium_expired',
                            titleVi: 'Gói SysNoxis Premium của bạn đã hết hạn. Gia hạn ngay để giữ huy hiệu thành viên Premium.',
                            titleEn: 'Your SysNoxis Premium membership has expired. Renew anytime to restore your blue badge.',
                            url: route('monetization.premium'),
                            actorName: (string) config('app.name', 'SysNoxis'),
                            actorUsername: 'system',
                            titleKey: 'Your SysNoxis Premium membership has expired. Renew anytime to restore your blue badge.',
                        ));
                    }

                    $expiredCount++;
                }
            });

        // 2. Send deduplicated 7d / 3d / 1d expiry reminders.
        $windows = [
            ['days' => 7, 'column' => 'reminder_7d_sent_at'],
            ['days' => 3, 'column' => 'reminder_3d_sent_at'],
            ['days' => 1, 'column' => 'reminder_1d_sent_at'],
        ];

        foreach ($windows as $window) {
            $days = $window['days'];
            $column = $window['column'];

            Subscription::query()
                ->where('status', SubscriptionStatus::Active->value)
                ->whereNull($column)
                ->where('expires_at', '>', $now)
                ->where('expires_at', '<=', $now->copy()->addDays($days))
                ->with('user')
                ->chunkById(100, static function ($subs) use ($days, $column, $now, &$remindersSent): void {
                    foreach ($subs as $sub) {
                        $sub->forceFill([$column => $now])->save();

                        if ($sub->user !== null) {
                            $sub->user->notify(new SocialActivityNotification(
                                eventKey: "premium_reminder_{$days}d",
                                titleVi: "SysNoxis Premium của bạn sẽ hết hạn sau {$days} ngày.",
                                titleEn: "Your SysNoxis Premium membership expires in {$days} day(s).",
                                url: route('account.premium'),
                                actorName: (string) config('app.name', 'SysNoxis'),
                                actorUsername: 'system',
                                titleKey: 'Your SysNoxis Premium membership expires in :days day(s).',
                                titleParams: ['days' => $days],
                            ));
                        }

                        $remindersSent++;
                    }
                });
        }

        return ['expired' => $expiredCount, 'reminders_sent' => $remindersSent];
    }

    /**
     * @return array<string, mixed>
     */
    public function accountPayload(User $user): array
    {
        $activeSub = Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::Active->value)
            ->where('expires_at', '>', now())
            ->with('plan')
            ->latest('expires_at')
            ->first();

        $history = Subscription::query()
            ->where('user_id', $user->id)
            ->with(['plan:id,slug,name,duration_days', 'payment:id,order_code,provider,status'])
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(static fn (Subscription $s): array => [
                'id' => $s->id,
                'plan_name' => $s->plan !== null ? $s->plan->name : 'Premium',
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'source' => $s->source,
                'currency' => $s->currency,
                'amount_paid' => $s->amount_paid,
                'starts_at' => $s->starts_at->toIso8601String(),
                'expires_at' => $s->expires_at->toIso8601String(),
                'order_code' => $s->payment?->order_code,
            ])
            ->values()
            ->all();

        $daysRemaining = $user->isPremium() && $user->premium_until !== null
            ? (int) max(0, ceil(now()->diffInHours($user->premium_until, false) / 24))
            : 0;

        return [
            'isPremium' => $user->isPremium(),
            'isSuspended' => $user->premium_suspended_at !== null,
            'premiumUntil' => $user->premium_until?->toIso8601String(),
            'daysRemaining' => $daysRemaining,
            'activeSubscription' => $activeSub === null ? null : [
                'id' => $activeSub->id,
                'plan_name' => $activeSub->plan?->name,
                'plan_slug' => $activeSub->plan?->slug,
                'ad_reduction_mode' => $activeSub->plan !== null ? $activeSub->plan->ad_reduction_mode : 'none',
                'benefits' => $activeSub->plan !== null ? ($activeSub->plan->benefits ?? []) : [],
                'starts_at' => $activeSub->starts_at->toIso8601String(),
                'expires_at' => $activeSub->expires_at->toIso8601String(),
                'source' => $activeSub->source,
            ],
            'history' => $history,
            'plans' => $this->publicPlans(),
            'paymentMethods' => $this->paymentService->availablePaymentMethods(),
            'pendingOrdersCount' => $this->paymentService->openPendingCount($user, PaymentServiceType::Premium),
            'maxPendingOrders' => $this->paymentService->maxPendingPaymentsPerUser(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializePlan(PremiumPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'duration_days' => $plan->duration_days,
            'price_usd_cents' => $plan->price_usd_cents,
            'price_vnd' => $plan->price_vnd,
            'promo_price_usd_cents' => $plan->promo_price_usd_cents,
            'promo_price_vnd' => $plan->promo_price_vnd,
            'effective_usd_cents' => $plan->effectivePrice('USD'),
            'effective_vnd' => $plan->effectivePrice('VND'),
            'benefits' => $plan->benefits ?? [],
            'ad_reduction_mode' => $plan->ad_reduction_mode,
            'sort_order' => $plan->sort_order,
            'is_active' => $plan->is_active,
            'is_featured' => $plan->is_featured,
        ];
    }
}
