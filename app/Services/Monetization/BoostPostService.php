<?php

namespace App\Services\Monetization;

use App\Enums\AdBudgetType;
use App\Enums\AdCampaignStatus;
use App\Enums\AdObjective;
use App\Enums\ModerationStatus;
use App\Enums\PaymentServiceType;
use App\Enums\PostVisibility;
use App\Models\AdCampaign;
use App\Models\BoostPackage;
use App\Models\Payment;
use App\Models\Post;
use App\Models\User;
use App\Services\Billing\WalletService;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BoostPostService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly MonetizationPaymentService $paymentService,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function publicPackages(): array
    {
        if (! Schema::hasTable('boost_packages')) {
            return [];
        }

        MonetizationSeeder::seedDefaultsIfEmpty();

        return array_values(
            BoostPackage::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('duration_hours')
                ->get()
                ->map(static fn (BoostPackage $pkg): array => self::serializePackage($pkg))
                ->all(),
        );
    }

    /**
     * Create a fixed-package Boost Post campaign paid via Advertising Balance or direct payment checkout.
     *
     * @return array{campaign: AdCampaign, payment: Payment|null}
     */
    public function purchaseBoost(
        User $user,
        Post $post,
        BoostPackage $package,
        string $paymentSource,
        string $currency,
        string $provider,
        string $idempotencyKey,
        string $platform = 'web',
    ): array {
        if (! $this->settings->featureEnabled('feature_boost_post') || ! $this->settings->featureEnabled('feature_ads')) {
            throw ValidationException::withMessages([
                'boost_package_id' => [__('Boost Post is currently disabled by the administrator.')],
            ]);
        }

        if ($user->isRestrictedFrom('advertise')) {
            throw ValidationException::withMessages([
                'post_id' => [__('Your account is currently restricted from running advertisements.')],
            ]);
        }

        if (! $package->is_active) {
            throw ValidationException::withMessages([
                'boost_package_id' => [__('The selected boost package is no longer available.')],
            ]);
        }

        if (
            $post->user_id !== $user->id
            || $post->deleted_at !== null
            || $post->visibility !== PostVisibility::Public
            || $post->moderation_status !== ModerationStatus::Approved
            || $post->published_at === null
        ) {
            throw ValidationException::withMessages([
                'post_id' => [__('Only your own public, approved posts can be boosted.')],
            ]);
        }

        $hasOngoing = AdCampaign::query()
            ->where('post_id', $post->id)
            ->whereIn('status', [
                AdCampaignStatus::PendingReview->value,
                AdCampaignStatus::Active->value,
            ])
            ->exists();

        if ($hasOngoing) {
            throw ValidationException::withMessages([
                'post_id' => [__('This post already has an active or pending boost campaign.')],
            ]);
        }

        $cleanCurrency = strtoupper(trim($currency));
        $useAdBalance = $paymentSource === 'ad_balance';

        return DB::transaction(function () use (
            $user,
            $post,
            $package,
            $useAdBalance,
            $cleanCurrency,
            $provider,
            $idempotencyKey,
            $platform,
        ): array {
            $durationDays = max(1, (int) ceil($package->duration_hours / 24));
            $priceVnd = $package->price_vnd;
            $priceForCurrency = $package->priceForCurrency($cleanCurrency);

            $campaign = new AdCampaign([
                'name' => mb_substr('[Boost] '.($post->title ?: $post->content), 0, 140),
                'objective' => AdObjective::Reach->value,
                'target_topics' => null,
                'target_url' => $post->link_url,
                'cta_label' => __('View post'),
                'schedule_mode' => 'immediate',
                'starts_at' => null,
                'ends_at' => null,
                'budget_type' => AdBudgetType::Total->value,
                'daily_budget' => null,
                'duration_days' => $durationDays,
            ]);
            $campaign->user_id = $user->id;
            $campaign->post_id = $post->id;
            $campaign->boost_package_id = $package->id;
            $campaign->billing_mode = 'fixed_package';
            $campaign->is_featured_slot = $package->is_featured_slot;
            $campaign->total_budget = $useAdBalance ? $priceVnd : $priceForCurrency;
            $campaign->held_amount = 0;
            $campaign->spent_amount = 0;

            $payment = null;

            if ($useAdBalance) {
                $campaign->status = AdCampaignStatus::PendingReview;
                $campaign->save();

                $this->walletService->holdForCampaign($user, $campaign, $priceVnd);
            } else {
                $campaign->status = AdCampaignStatus::PendingPayment;
                $campaign->save();

                $payment = $this->paymentService->createOrder(
                    user: $user,
                    serviceType: PaymentServiceType::Boost,
                    amount: $priceForCurrency,
                    currency: $cleanCurrency,
                    provider: $provider,
                    idempotencyKey: $idempotencyKey,
                    platform: $platform,
                    payableType: AdCampaign::class,
                    payableId: $campaign->id,
                    metadata: [
                        'ad_campaign_id' => $campaign->id,
                        'post_id' => $post->id,
                        'package_slug' => $package->slug,
                        'package_name' => $package->name,
                        'duration_hours' => $package->duration_hours,
                    ],
                );

                $campaign->payment_id = $payment->id;
                $campaign->save();
            }

            $this->auditLogger->log(
                'boost.campaign_created',
                $campaign,
                [
                    'post_id' => $post->id,
                    'package_slug' => $package->slug,
                    'payment_source' => $useAdBalance ? 'ad_balance' : $provider,
                    'amount' => $campaign->total_budget,
                    'currency' => $useAdBalance ? 'VND' : $cleanCurrency,
                ],
                $user,
            );

            return ['campaign' => $campaign->fresh(['boostPackage']) ?? $campaign, 'payment' => $payment];
        });
    }

    public function markPaymentSettled(Payment $payment): ?AdCampaign
    {
        /** @var AdCampaign|null $campaign */
        $campaign = AdCampaign::query()
            ->where('payment_id', $payment->id)
            ->lockForUpdate()
            ->first();

        if ($campaign === null) {
            return null;
        }

        if ($campaign->status === AdCampaignStatus::PendingPayment) {
            $campaign->status = AdCampaignStatus::PendingReview;
            $campaign->save();
        }

        return $campaign;
    }

    /**
     * Cron task: complete elapsed fixed-package boost campaigns and settle held balance into spent_amount.
     */
    public function completeElapsedBoosts(): int
    {
        $completed = 0;

        AdCampaign::query()
            ->where('billing_mode', 'fixed_package')
            ->where('status', AdCampaignStatus::Active->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->chunkById(100, function ($campaigns) use (&$completed): void {
                foreach ($campaigns as $campaign) {
                    if ($campaign->held_amount > 0) {
                        $this->walletService->chargeCampaignSpend(
                            $campaign,
                            $campaign->held_amount,
                            "fixed-boost-complete:{$campaign->id}",
                        );
                    }

                    $campaign->forceFill(['status' => AdCampaignStatus::Completed])->save();
                    $completed++;
                }
            });

        return $completed;
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializePackage(BoostPackage $pkg): array
    {
        return [
            'id' => $pkg->id,
            'slug' => $pkg->slug,
            'name' => $pkg->name,
            'description' => $pkg->description,
            'duration_hours' => $pkg->duration_hours,
            'price_usd_cents' => $pkg->price_usd_cents,
            'price_vnd' => $pkg->price_vnd,
            'is_featured_slot' => $pkg->is_featured_slot,
            'priority_weight' => $pkg->priority_weight,
            'benefits' => $pkg->benefits ?? [],
            'is_active' => $pkg->is_active,
            'sort_order' => $pkg->sort_order,
        ];
    }
}
