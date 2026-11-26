<?php

namespace App\Services\Monetization;

use App\Enums\BannerCampaignStatus;
use App\Enums\BannerDeliveryMode;
use App\Enums\PaymentServiceType;
use App\Models\BannerCampaign;
use App\Models\BannerEvent;
use App\Models\BannerPlacement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Media\SafeImageUploadService;
use App\Services\Security\AuditLogger;
use App\Services\Social\SafeLinkPreviewService;
use App\Services\System\SystemSettingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BannerAdvertisingService
{
    public function __construct(
        private readonly MonetizationPaymentService $paymentService,
        private readonly PremiumSubscriptionService $premiumService,
        private readonly SafeImageUploadService $imageUploadService,
        private readonly SafeLinkPreviewService $linkGuard,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function publicPlacements(): array
    {
        if (! Schema::hasTable('banner_placements')) {
            return [];
        }

        MonetizationSeeder::seedDefaultsIfEmpty();

        $now = now();

        return array_values(
            BannerPlacement::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(static function (BannerPlacement $placement) use ($now): array {
                    $activeNow = BannerCampaign::query()
                        ->where('banner_placement_id', $placement->id)
                        ->whereIn('status', [BannerCampaignStatus::Active->value, BannerCampaignStatus::Scheduled->value])
                        ->whereNull('emergency_paused_at')
                        ->where('starts_at', '<=', $now)
                        ->where('ends_at', '>=', $now)
                        ->count();

                    $data = self::serializePlacement($placement);
                    $data['active_now'] = $activeNow;
                    $data['is_available_now'] = $placement->delivery_mode === BannerDeliveryMode::Exclusive
                        ? $activeNow === 0
                        : $activeNow < $placement->max_active_banners;

                    return $data;
                })
                ->all(),
        );
    }

    /**
     * Strictly verify slot availability for a date range (must be called inside a transaction with locked placement).
     */
    public function assertSlotAvailable(
        BannerPlacement $placement,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $ignoreCampaignId = null,
    ): void {
        $query = BannerCampaign::query()
            ->where('banner_placement_id', $placement->id)
            ->whereIn('status', [
                BannerCampaignStatus::PendingReview->value,
                BannerCampaignStatus::Scheduled->value,
                BannerCampaignStatus::Active->value,
            ])
            ->whereNull('emergency_paused_at')
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        if ($ignoreCampaignId !== null) {
            $query->where('id', '!=', $ignoreCampaignId);
        }

        $overlappingCount = $query->count();
        $capacity = $placement->delivery_mode === BannerDeliveryMode::Exclusive
            ? 1
            : max(1, $placement->max_active_banners);

        if ($overlappingCount >= $capacity) {
            throw ValidationException::withMessages([
                'starts_at' => [
                    $placement->delivery_mode === BannerDeliveryMode::Exclusive
                        ? __('This exclusive banner placement is already booked during the selected date range.')
                        : __('This rotation banner placement has reached its maximum concurrent slots during the selected date range.'),
                ],
            ]);
        }
    }

    /**
     * Create a Banner Advertising campaign (either self-serve with checkout payment, or Admin-managed).
     *
     * @param  array<string, mixed>  $data
     * @return array{campaign: BannerCampaign, payment: Payment|null}
     */
    public function createCampaign(
        User $owner,
        array $data,
        ?UploadedFile $desktopImage = null,
        ?UploadedFile $mobileImage = null,
        ?UploadedFile $logoFile = null,
        ?User $adminActor = null,
    ): array {
        if ($adminActor === null && ! $this->settings->featureEnabled('feature_banner_ads')) {
            throw ValidationException::withMessages([
                'banner_placement_id' => [__('Banner advertising is currently disabled.')],
            ]);
        }

        if ($adminActor === null && $owner->isRestrictedFrom('advertise')) {
            throw ValidationException::withMessages([
                'banner_placement_id' => [__('Your account is currently restricted from creating advertisements.')],
            ]);
        }

        $targetUrl = trim($data['target_url']);
        if (! $this->linkGuard->isSafePublicUrl($targetUrl)) {
            throw ValidationException::withMessages([
                'target_url' => [__('Destination URL must be a valid public HTTP/HTTPS address.')],
            ]);
        }

        $desktopAsset = $this->resolveCreativeAsset(
            $desktopImage,
            $data['desktop_image_url'] ?? null,
            'banners/desktop',
            'desktop_image',
        );
        $mobileAsset = $this->resolveCreativeAsset(
            $mobileImage,
            $data['mobile_image_url'] ?? null,
            'banners/mobile',
            'mobile_image',
        );
        $logoAsset = $logoFile !== null
            ? $this->imageUploadService->storeImage($logoFile, 'banners/logos')
            : null;

        if ($desktopAsset === null && $mobileAsset === null) {
            throw ValidationException::withMessages([
                'desktop_image' => [__('Please upload at least one banner image (Desktop or Mobile).')],
            ]);
        }

        $durationDays = max(1, min(365, (int) $data['duration_days']));
        $startsAt = ! empty($data['starts_at'])
            ? CarbonImmutable::parse($data['starts_at'])->utc()
            : now()->toImmutable();
        $endsAt = $startsAt->addDays($durationDays);
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'VND')));
        if (! in_array($currency, ['VND', 'USD'], true)) {
            $currency = 'VND';
        }

        return DB::transaction(function () use (
            $owner,
            $data,
            $targetUrl,
            $desktopAsset,
            $mobileAsset,
            $logoAsset,
            $durationDays,
            $startsAt,
            $endsAt,
            $currency,
            $adminActor,
        ): array {
            /** @var BannerPlacement $placement */
            $placement = BannerPlacement::query()
                ->whereKey((int) $data['banner_placement_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $placement->is_active && $adminActor === null) {
                throw ValidationException::withMessages([
                    'banner_placement_id' => [__('The selected banner placement is currently unavailable.')],
                ]);
            }

            $this->assertSlotAvailable($placement, $startsAt, $endsAt);

            $priceAmount = $placement->priceForDuration($durationDays, $currency);

            $campaign = new BannerCampaign([
                'name' => mb_substr(trim($data['name']), 0, 140),
                'business_name' => isset($data['business_name']) && trim($data['business_name']) !== ''
                    ? mb_substr(trim($data['business_name']), 0, 120)
                    : null,
                'headline' => isset($data['headline']) && trim($data['headline']) !== ''
                    ? mb_substr(trim($data['headline']), 0, 160)
                    : null,
                'cta_label' => isset($data['cta_label']) && trim($data['cta_label']) !== ''
                    ? mb_substr(trim($data['cta_label']), 0, 60)
                    : __('Learn more'),
                'target_url' => $targetUrl,
                'alt_text' => mb_substr(trim($data['alt_text']), 0, 180),
                'duration_days' => $durationDays,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
            $campaign->user_id = $owner->id;
            $campaign->banner_placement_id = $placement->id;
            $campaign->desktop_image_disk = $desktopAsset['disk'] ?? null;
            $campaign->desktop_image_path = $desktopAsset['path'] ?? null;
            $campaign->mobile_image_disk = $mobileAsset['disk'] ?? null;
            $campaign->mobile_image_path = $mobileAsset['path'] ?? null;
            $campaign->logo_disk = $logoAsset['disk'] ?? null;
            $campaign->logo_path = $logoAsset['path'] ?? null;
            $campaign->currency = $currency;
            $campaign->price_amount = $priceAmount;

            $payment = null;

            if ($adminActor !== null) {
                $campaign->created_by_admin_id = $adminActor->id;
                $campaign->payment_status = $data['payment_status'] ?? 'manual_paid';
                $autoApprove = (bool) ($data['auto_approve'] ?? true);

                if ($autoApprove) {
                    $campaign->status = $startsAt->isFuture()
                        ? BannerCampaignStatus::Scheduled
                        : BannerCampaignStatus::Active;
                    $campaign->reviewed_by = $adminActor->id;
                    $campaign->reviewed_at = now();
                } else {
                    $campaign->status = BannerCampaignStatus::PendingReview;
                }

                $campaign->save();

                $this->auditLogger->log(
                    'admin.banner.created',
                    $campaign,
                    [
                        'placement' => $placement->code,
                        'status' => $campaign->status->value,
                        'starts_at' => $startsAt->toIso8601String(),
                        'ends_at' => $endsAt->toIso8601String(),
                    ],
                    $adminActor,
                );
            } else {
                $campaign->payment_status = 'pending';
                $campaign->status = BannerCampaignStatus::PendingPayment;
                $campaign->save();

                $provider = (string) ($data['provider'] ?? 'vietqr');
                $idempotencyKey = (string) ($data['idempotency_key'] ?? '');
                $platform = (string) ($data['platform'] ?? 'web');

                $payment = $this->paymentService->createOrder(
                    user: $owner,
                    serviceType: PaymentServiceType::Banner,
                    amount: $priceAmount,
                    currency: $currency,
                    provider: $provider,
                    idempotencyKey: $idempotencyKey,
                    platform: $platform,
                    payableType: BannerCampaign::class,
                    payableId: $campaign->id,
                    metadata: [
                        'banner_campaign_id' => $campaign->id,
                        'placement_code' => $placement->code,
                        'placement_name' => $placement->name,
                        'duration_days' => $durationDays,
                    ],
                );

                $campaign->payment_id = $payment->id;
                $campaign->save();
            }

            return ['campaign' => $campaign->fresh(['placement']) ?? $campaign, 'payment' => $payment];
        });
    }

    public function markPaymentSettled(Payment $payment): ?BannerCampaign
    {
        /** @var BannerCampaign|null $campaign */
        $campaign = BannerCampaign::query()
            ->where('payment_id', $payment->id)
            ->lockForUpdate()
            ->first();

        if ($campaign === null) {
            return null;
        }

        if ($campaign->status === BannerCampaignStatus::PendingPayment) {
            $campaign->payment_status = 'paid';
            $campaign->status = BannerCampaignStatus::PendingReview;
            $campaign->save();
        }

        return $campaign;
    }

    public function reviewCampaign(
        User $reviewer,
        BannerCampaign $campaign,
        string $decision,
        ?string $reason = null,
    ): BannerCampaign {
        $updated = DB::transaction(function () use ($reviewer, $campaign, $decision, $reason): BannerCampaign {
            /** @var BannerCampaign $locked */
            $locked = BannerCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [BannerCampaignStatus::PendingReview, BannerCampaignStatus::Paused], true)) {
                throw ValidationException::withMessages([
                    'decision' => [__('Only pending or paused banner campaigns can be reviewed.')],
                ]);
            }

            /** @var BannerPlacement $placement */
            $placement = BannerPlacement::query()
                ->whereKey($locked->banner_placement_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($decision === 'approve') {
                $this->assertSlotAvailable($placement, $locked->starts_at, $locked->ends_at, $locked->id);
                $locked->status = $locked->starts_at->isFuture()
                    ? BannerCampaignStatus::Scheduled
                    : BannerCampaignStatus::Active;
                $locked->emergency_paused_at = null;
                $locked->rejection_reason = null;
            } else {
                $locked->status = BannerCampaignStatus::Rejected;
                $locked->rejection_reason = trim((string) $reason) ?: __('Banner creative did not meet SyncViet advertising guidelines.');
            }

            $locked->reviewed_by = $reviewer->id;
            $locked->reviewed_at = now();
            $locked->save();

            $this->auditLogger->log(
                "admin.banner.reviewed_{$decision}",
                $locked,
                ['placement' => $placement->code, 'reason' => $locked->rejection_reason],
                $reviewer,
            );

            return $locked->fresh(['owner', 'placement']) ?? $locked;
        });

        if ($updated->owner !== null) {
            $approved = $decision === 'approve';
            $updated->owner->notify(new SocialActivityNotification(
                eventKey: $approved ? 'banner_approved' : 'banner_rejected',
                titleVi: $approved
                    ? "Banner \"{$updated->name}\" đã được duyệt và sẵn sàng hiển thị."
                    : "Banner \"{$updated->name}\" bị từ chối: {$updated->rejection_reason}",
                titleEn: $approved
                    ? "Your banner \"{$updated->name}\" was approved and scheduled for display."
                    : "Your banner \"{$updated->name}\" was rejected: {$updated->rejection_reason}",
                url: route('monetization.banner'),
                actorName: $reviewer->name,
                actorUsername: $reviewer->username,
            ));
        }

        return $updated;
    }

    public function manageCampaignStatus(User $actor, BannerCampaign $campaign, string $action, string $reason): BannerCampaign
    {
        $cleanReason = trim($reason);

        return DB::transaction(function () use ($actor, $campaign, $action, $cleanReason): BannerCampaign {
            /** @var BannerCampaign $locked */
            $locked = BannerCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            match ($action) {
                'emergency_pause' => (function () use ($locked, $cleanReason): void {
                    $locked->status = BannerCampaignStatus::Paused;
                    $locked->emergency_paused_at = now();
                    $locked->rejection_reason = $cleanReason !== '' ? $cleanReason : __('Emergency paused by administrator.');
                    $locked->save();
                })(),
                'pause' => (function () use ($locked): void {
                    $locked->status = BannerCampaignStatus::Paused;
                    $locked->save();
                })(),
                'resume' => (function () use ($locked): void {
                    if ($locked->ends_at->isPast()) {
                        throw ValidationException::withMessages([
                            'action' => [__('Cannot resume an expired banner campaign.')],
                        ]);
                    }
                    /** @var BannerPlacement $placement */
                    $placement = BannerPlacement::query()->whereKey($locked->banner_placement_id)->lockForUpdate()->firstOrFail();
                    $this->assertSlotAvailable($placement, $locked->starts_at, $locked->ends_at, $locked->id);
                    $locked->status = $locked->starts_at->isFuture() ? BannerCampaignStatus::Scheduled : BannerCampaignStatus::Active;
                    $locked->emergency_paused_at = null;
                    $locked->save();
                })(),
                'cancel' => (function () use ($locked, $cleanReason): void {
                    $locked->status = BannerCampaignStatus::Cancelled;
                    $locked->rejection_reason = $cleanReason !== '' ? $cleanReason : null;
                    $locked->save();
                })(),
                default => throw ValidationException::withMessages([
                    'action' => [__('Unsupported banner campaign action.')],
                ]),
            };

            $this->auditLogger->log(
                "admin.banner.{$action}",
                $locked,
                ['status' => $locked->status->value, 'reason' => $cleanReason],
                $actor,
            );

            return $locked->fresh(['placement']) ?? $locked;
        });
    }

    /**
     * Resolve active deliverable banners for one or more placement codes, respecting Premium ad-free mode,
     * hidden ads per viewer, emergency pause, and exact start/end timestamps.
     *
     * @param  list<string>  $placementCodes
     * @return array<string, array<string, mixed>|null>
     */
    public function activeBannersForSlots(array $placementCodes, ?User $viewer, Request $request): array
    {
        $result = array_fill_keys($placementCodes, null);

        if (
            ! $this->settings->featureEnabled('feature_banner_ads')
            || ! Schema::hasTable('banner_placements')
            || ! Schema::hasTable('banner_campaigns')
        ) {
            return $result;
        }

        if ($this->premiumService->userAdReductionMode($viewer) === 'ad_free') {
            return $result;
        }

        $now = now();
        $hiddenIds = $viewer !== null
            ? BannerEvent::query()
                ->where('viewer_id', $viewer->id)
                ->where('event_type', 'hide')
                ->pluck('banner_campaign_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        $placements = BannerPlacement::query()
            ->whereIn('code', $placementCodes)
            ->where('is_active', true)
            ->get()
            ->keyBy('code');

        foreach ($placementCodes as $code) {
            /** @var BannerPlacement|null $placement */
            $placement = $placements->get($code);
            if ($placement === null) {
                continue;
            }

            $query = BannerCampaign::query()
                ->where('banner_placement_id', $placement->id)
                ->where('status', BannerCampaignStatus::Active->value)
                ->whereNull('emergency_paused_at')
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>', $now)
                ->orderBy('impressions_count')
                ->orderBy('id');

            if ($hiddenIds !== []) {
                $query->whereNotIn('id', $hiddenIds);
            }

            /** @var BannerCampaign|null $campaign */
            $campaign = $query->first();

            if ($campaign === null) {
                continue;
            }

            $this->recordEvent($campaign, $viewer, $request, 'impression', 'Y-m-d-H');

            $result[$code] = [
                'id' => $campaign->id,
                'placement_code' => $placement->code,
                'aspect_ratio' => $placement->aspect_ratio,
                'platform' => $placement->platform,
                'name' => $campaign->name,
                'business_name' => $campaign->business_name,
                'headline' => $campaign->headline,
                'cta_label' => $campaign->cta_label ?: __('Learn more'),
                'alt_text' => $campaign->alt_text,
                'desktop_image_url' => $campaign->desktopImageUrl(),
                'mobile_image_url' => $campaign->mobileImageUrl(),
                'logo_url' => $campaign->logoUrl(),
                'click_url' => route('banners.click', $campaign->id),
            ];
        }

        return $result;
    }

    public function recordClickAndResolveDestination(BannerCampaign $campaign, ?User $viewer, Request $request): string
    {
        $fallback = route('home');
        $destination = $this->linkGuard->isSafePublicUrl($campaign->target_url) ? $campaign->target_url : $fallback;

        if ($campaign->isDeliverableNow() && $viewer?->id !== $campaign->user_id) {
            $this->recordEvent($campaign, $viewer, $request, 'click', 'Y-m-d');
        }

        return $destination;
    }

    public function recordViewerFeedback(
        BannerCampaign $campaign,
        ?User $viewer,
        Request $request,
        string $eventType,
        ?string $reason = null,
    ): void {
        if (! in_array($eventType, ['hide', 'report'], true)) {
            return;
        }

        $actorKey = $viewer !== null ? "u:{$viewer->id}" : 'ip:'.sha1((string) $request->ip());
        $dedupHash = hash('sha256', "{$eventType}:{$campaign->id}:{$actorKey}");

        DB::table('banner_events')->insertOrIgnore([
            'banner_campaign_id' => $campaign->id,
            'viewer_id' => $viewer?->id,
            'event_type' => $eventType,
            'dedup_hash' => $dedupHash,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'metadata' => $reason !== null ? json_encode(['reason' => mb_substr(trim($reason), 0, 300)]) : null,
            'created_at' => now(),
        ]);

        if ($eventType === 'report') {
            $this->auditLogger->log(
                'banner.reported',
                $campaign,
                ['reason' => $reason],
                $viewer,
            );
        }
    }

    /**
     * Cron task: activate scheduled banners when starts_at arrives and expire ended banners.
     *
     * @return array{activated: int, expired: int}
     */
    public function syncScheduledAndExpiredBanners(): array
    {
        if (! Schema::hasTable('banner_campaigns')) {
            return ['activated' => 0, 'expired' => 0];
        }

        $now = now();

        $activated = BannerCampaign::query()
            ->where('status', BannerCampaignStatus::Scheduled->value)
            ->whereNull('emergency_paused_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->update(['status' => BannerCampaignStatus::Active->value]);

        $expired = BannerCampaign::query()
            ->whereIn('status', [BannerCampaignStatus::Active->value, BannerCampaignStatus::Scheduled->value])
            ->where('ends_at', '<=', $now)
            ->update(['status' => BannerCampaignStatus::Expired->value]);

        return ['activated' => $activated, 'expired' => $expired];
    }

    private function recordEvent(
        BannerCampaign $campaign,
        ?User $viewer,
        Request $request,
        string $eventType,
        string $bucketFormat,
    ): void {
        $ua = strtolower((string) $request->userAgent());
        $isBot = $ua !== '' && (bool) preg_match('/bot|crawler|spider|slurp|googlebot|bingbot|facebookexternalhit|curl|wget|python-requests/i', $ua);

        if ($viewer?->id === $campaign->user_id || ($viewer !== null && ! $viewer->isHumanMember()) || $isBot) {
            return;
        }

        $bucket = now()->format($bucketFormat);
        $actorKey = $viewer !== null ? "u:{$viewer->id}" : 'ip:'.sha1((string) $request->ip());
        $dedupHash = hash('sha256', "{$eventType}:{$campaign->id}:{$actorKey}:{$bucket}");

        $inserted = DB::table('banner_events')->insertOrIgnore([
            'banner_campaign_id' => $campaign->id,
            'viewer_id' => $viewer?->id,
            'event_type' => $eventType,
            'dedup_hash' => $dedupHash,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'created_at' => now(),
        ]);

        if ($inserted === 1) {
            $campaign->increment($eventType === 'impression' ? 'impressions_count' : 'clicks_count');
        }
    }

    /**
     * @return array{disk: string, path: string, mime_type: string, width: int, height: int, size_bytes: int}|null
     */
    private function resolveCreativeAsset(
        ?UploadedFile $file,
        ?string $imageUrl,
        string $directory,
        string $errorField,
    ): ?array {
        if ($file !== null) {
            return $this->imageUploadService->storeImage($file, $directory);
        }

        if (is_string($imageUrl) && trim($imageUrl) !== '') {
            return $this->imageUploadService->importImageFromUrl(
                trim($imageUrl),
                $this->linkGuard,
                $directory,
                $errorField,
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializePlacement(BannerPlacement $placement): array
    {
        return [
            'id' => $placement->id,
            'code' => $placement->code,
            'name' => $placement->name,
            'description' => $placement->description,
            'platform' => $placement->platform,
            'recommended_width' => $placement->recommended_width,
            'recommended_height' => $placement->recommended_height,
            'aspect_ratio' => $placement->aspect_ratio,
            'delivery_mode' => $placement->delivery_mode->value,
            'delivery_mode_label' => $placement->delivery_mode->label(),
            'max_active_banners' => $placement->max_active_banners,
            'price_7d_vnd' => $placement->price_7d_vnd,
            'price_15d_vnd' => $placement->price_15d_vnd,
            'price_30d_vnd' => $placement->price_30d_vnd,
            'price_90d_vnd' => $placement->price_90d_vnd,
            'price_7d_usd_cents' => $placement->price_7d_usd_cents,
            'price_15d_usd_cents' => $placement->price_15d_usd_cents,
            'price_30d_usd_cents' => $placement->price_30d_usd_cents,
            'price_90d_usd_cents' => $placement->price_90d_usd_cents,
            'is_active' => $placement->is_active,
            'sort_order' => $placement->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeCampaign(BannerCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'user_id' => $campaign->user_id,
            'owner_name' => $campaign->relationLoaded('owner') ? $campaign->owner?->name : null,
            'owner_username' => $campaign->relationLoaded('owner') ? $campaign->owner?->username : null,
            'banner_placement_id' => $campaign->banner_placement_id,
            'placement_code' => $campaign->relationLoaded('placement') ? $campaign->placement?->code : null,
            'placement_name' => $campaign->relationLoaded('placement') ? $campaign->placement?->name : null,
            'payment_id' => $campaign->payment_id,
            'name' => $campaign->name,
            'business_name' => $campaign->business_name,
            'headline' => $campaign->headline,
            'cta_label' => $campaign->cta_label,
            'target_url' => $campaign->target_url,
            'alt_text' => $campaign->alt_text,
            'desktop_image_url' => $campaign->desktopImageUrl(),
            'mobile_image_url' => $campaign->mobileImageUrl(),
            'logo_url' => $campaign->logoUrl(),
            'duration_days' => $campaign->duration_days,
            'starts_at' => $campaign->starts_at->toIso8601String(),
            'ends_at' => $campaign->ends_at->toIso8601String(),
            'currency' => $campaign->currency,
            'price_amount' => $campaign->price_amount,
            'payment_status' => $campaign->payment_status,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'impressions_count' => $campaign->impressions_count,
            'clicks_count' => $campaign->clicks_count,
            'ctr_percent' => $campaign->impressions_count > 0
                ? round(($campaign->clicks_count / $campaign->impressions_count) * 100, 2)
                : 0.0,
            'rejection_reason' => $campaign->rejection_reason,
            'emergency_paused_at' => $campaign->emergency_paused_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }
}
