<?php

namespace App\Services\Ads;

use App\Enums\AdCampaignStatus;
use App\Enums\AdObjective;
use App\Enums\ModerationStatus;
use App\Enums\PostVisibility;
use App\Enums\UserStatus;
use App\Models\AdCampaign;
use App\Models\AdDailyStat;
use App\Models\User;
use App\Services\Billing\WalletService;
use App\Services\Social\FeedService;
use App\Services\System\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdDeliveryService
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly WalletService $walletService,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Select an active, approved, funded sponsored campaign for the News Feed and log deduplicated impression.
     *
     * @return array<string, mixed>|null
     */
    public function pickSponsoredPostForFeed(?User $viewer, Request $request): ?array
    {
        if (! $this->settings->featureEnabled('feature_ads')) {
            return null;
        }
        $now = now();
        $blockedIds = $viewer !== null ? $this->feedService->mutualBlockedUserIds($viewer) : [];

        $query = AdCampaign::query()
            ->where('status', AdCampaignStatus::Active->value)
            ->where('held_amount', '>', 0)
            ->where(static function ($q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(static function ($q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->whereHas('owner', static function ($q): void {
                $q->where('status', UserStatus::Active->value);
            })
            ->whereHas('post', static function ($q): void {
                $q->where('visibility', PostVisibility::Public->value)
                    ->where('moderation_status', ModerationStatus::Approved->value)
                    ->whereNotNull('published_at');
            })
            ->with([
                'post.author:id,name,username,status',
                'post.author.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
                'post.community:id,name,slug',
                'post.hashtags:id,name,slug',
                'post.media',
                'post.poll.options',
            ])
            ->orderByDesc('held_amount')
            ->orderBy('impressions_count');

        if ($blockedIds !== []) {
            $query->whereNotIn('user_id', $blockedIds);
        }

        /** @var AdCampaign|null $campaign */
        $campaign = $query->first();

        if ($campaign === null || $campaign->post === null) {
            return null;
        }

        $this->recordImpression($campaign, $viewer, $request);

        $serialized = $this->feedService->serializeCollection(collect([$campaign->post]), $viewer);

        if ($serialized === []) {
            return null;
        }

        $postData = $serialized[0];
        $postData['is_sponsored'] = true;
        $postData['ad_campaign'] = [
            'id' => $campaign->id,
            'cta_label' => $campaign->cta_label ?: __('Learn more'),
            'click_url' => route('ads.click', $campaign),
            'has_external_url' => $campaign->target_url !== null && $campaign->target_url !== '',
        ];

        return $postData;
    }

    public function recordImpression(AdCampaign $campaign, ?User $viewer, Request $request): void
    {
        if ($viewer?->id === $campaign->user_id) {
            return;
        }

        $cost = $campaign->objective === AdObjective::Reach
            ? (int) $this->settings->get('ad_impression_cost_vnd', (int) config('syncviet.ad_pricing.impression_cost_vnd', 25))
            : 0;

        $this->recordUniqueEvent($campaign, $viewer, $request, 'impression', 'Y-m-d-H', $cost);
    }

    public function recordClickAndResolveDestination(AdCampaign $campaign, ?User $viewer, Request $request): string
    {
        $fallbackUrl = route('posts.show', $campaign->post_id);
        $destination = $this->resolveSafeDestination($campaign->target_url) ?? $fallbackUrl;

        if (
            $campaign->status !== AdCampaignStatus::Active
            || $campaign->held_amount <= 0
            || $viewer?->id === $campaign->user_id
        ) {
            return $destination;
        }

        $cost = (int) $this->settings->get('ad_click_cost_vnd', (int) config('syncviet.ad_pricing.click_cost_vnd', 1_250));
        $this->recordUniqueEvent($campaign, $viewer, $request, 'click', 'Y-m-d', $cost);

        return $destination;
    }

    private function recordUniqueEvent(
        AdCampaign $campaign,
        ?User $viewer,
        Request $request,
        string $eventType,
        string $bucketFormat,
        int $eventCostVnd,
    ): void {
        $bucket = now()->format($bucketFormat);
        $prefix = $eventType === 'impression' ? 'imp' : 'clk';
        $actorKey = $viewer !== null ? "u:{$viewer->id}" : 'ip:'.sha1((string) $request->ip());
        $dedupHash = hash('sha256', "{$prefix}:{$campaign->id}:{$actorKey}:{$bucket}");

        $inserted = DB::table('ad_events')->insertOrIgnore([
            'ad_campaign_id' => $campaign->id,
            'viewer_id' => $viewer?->id,
            'event_type' => $eventType,
            'dedup_hash' => $dedupHash,
            'cost_vnd' => 0,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'created_at' => now(),
        ]);

        if ($inserted !== 1) {
            return;
        }

        $charged = 0;

        if ($eventCostVnd > 0) {
            $charged = $this->walletService->chargeCampaignSpend($campaign, $eventCostVnd, "{$prefix}:{$dedupHash}");

            if ($charged > 0) {
                DB::table('ad_events')
                    ->where('ad_campaign_id', $campaign->id)
                    ->where('event_type', $eventType)
                    ->where('dedup_hash', $dedupHash)
                    ->update(['cost_vnd' => $charged]);
            }
        }

        $isImpression = $eventType === 'impression';
        $campaign->increment($isImpression ? 'impressions_count' : 'clicks_count');

        $this->recordDailyStat(
            $campaign->id,
            impressions: $isImpression ? 1 : 0,
            clicks: $isImpression ? 0 : 1,
            spent: $charged,
        );
    }

    private function recordDailyStat(int $campaignId, int $impressions, int $clicks, int $spent): void
    {
        $statDate = now()->toDateString();

        DB::table('ad_daily_stats')->insertOrIgnore([
            'ad_campaign_id' => $campaignId,
            'stat_date' => $statDate,
            'impressions' => 0,
            'clicks' => 0,
            'spent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var AdDailyStat $stat */
        $stat = AdDailyStat::query()
            ->where('ad_campaign_id', $campaignId)
            ->whereDate('stat_date', $statDate)
            ->firstOrFail();

        if ($impressions > 0) {
            $stat->increment('impressions', $impressions);
        }

        if ($clicks > 0) {
            $stat->increment('clicks', $clicks);
        }

        if ($spent > 0) {
            $stat->increment('spent', $spent);
        }
    }

    private function resolveSafeDestination(?string $targetUrl): ?string
    {
        if ($targetUrl === null || trim($targetUrl) === '') {
            return null;
        }

        $parsed = parse_url($targetUrl);

        if (! is_array($parsed)) {
            return null;
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || empty($parsed['host'])) {
            return null;
        }

        return $targetUrl;
    }
}
