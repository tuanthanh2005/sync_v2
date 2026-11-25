<?php

namespace App\Services\Ads;

use App\Enums\AdBudgetType;
use App\Enums\AdCampaignStatus;
use App\Enums\AdObjective;
use App\Enums\CreditEntryType;
use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use App\Models\AdCampaign;
use App\Models\AdDailyStat;
use App\Models\Post;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Billing\WalletService;
use App\Services\Notification\NotificationService;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdCampaignService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly NotificationService $notificationService,
        private readonly AuditLogger $auditLogger,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboardPayload(User $user): array
    {
        $walletPayload = $this->walletService->walletPayload($user);

        $campaigns = AdCampaign::query()
            ->where('user_id', $user->id)
            ->with(['post:id,user_id,title,content,category,reactions_count,comments_count,shares_count,published_at', 'post.media'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(static fn (AdCampaign $campaign): array => self::serializeCampaign($campaign))
            ->values()
            ->all();

        $eligiblePosts = Post::query()
            ->where('user_id', $user->id)
            ->where('visibility', PostVisibility::Public->value)
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->whereNotNull('published_at')
            ->with(['media'])
            ->latest('published_at')
            ->limit(25)
            ->get()
            ->map(static fn (Post $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'content' => $post->content,
                'category' => $post->category->value,
                'category_label' => $post->category->label(),
                'reactions_count' => $post->reactions_count,
                'comments_count' => $post->comments_count,
                'shares_count' => $post->shares_count,
                'published_at' => $post->published_at?->toIso8601String(),
                'image' => $post->media->first()?->url(),
            ])
            ->values()
            ->all();

        $campaignIds = AdCampaign::query()->where('user_id', $user->id)->pluck('id');

        $dailyStats = $campaignIds->isEmpty()
            ? []
            : AdDailyStat::query()
                ->whereIn('ad_campaign_id', $campaignIds)
                ->where('stat_date', '>=', now()->subDays(14)->toDateString())
                ->orderBy('stat_date')
                ->get()
                ->groupBy(static fn (AdDailyStat $stat): string => $stat->stat_date->toDateString())
                ->map(static fn ($group, string $date): array => [
                    'date' => $date,
                    'impressions' => (int) $group->sum('impressions'),
                    'clicks' => (int) $group->sum('clicks'),
                    'spent' => (int) $group->sum('spent'),
                ])
                ->values()
                ->all();

        $totalImpressions = (int) AdCampaign::query()->where('user_id', $user->id)->sum('impressions_count');
        $totalClicks = (int) AdCampaign::query()->where('user_id', $user->id)->sum('clicks_count');
        $totalSpent = (int) AdCampaign::query()->where('user_id', $user->id)->sum('spent_amount');
        $activeCount = AdCampaign::query()
            ->where('user_id', $user->id)
            ->where('status', AdCampaignStatus::Active->value)
            ->count();

        return [
            'wallet' => $walletPayload['account'],
            'ledgerEntries' => $walletPayload['entries'],
            'payments' => $walletPayload['payments'],
            'campaigns' => $campaigns,
            'eligiblePosts' => $eligiblePosts,
            'dailyStats' => $dailyStats,
            'statsSummary' => [
                'active_campaigns' => $activeCount,
                'total_impressions' => $totalImpressions,
                'total_clicks' => $totalClicks,
                'total_spent' => $totalSpent,
                'ctr_percent' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0.0,
            ],
            'objectiveOptions' => AdObjective::options(),
            'topicSuggestions' => PostCategory::options(),
            'pricing' => config('syncviet.ad_pricing'),
        ];
    }

    /**
     * @param  array{
     *     post_id: int,
     *     name?: string|null,
     *     objective: string,
     *     target_topics?: list<string>|null,
     *     target_url?: string|null,
     *     cta_label?: string|null,
     *     schedule_mode: string,
     *     starts_at?: string|null,
     *     ends_at?: string|null,
     *     budget_type: string,
     *     daily_budget?: int|null,
     *     duration_days?: int|null,
     *     total_budget?: int|null
     * }  $data
     */
    public function createCampaign(User $user, array $data): AdCampaign
    {
        if (! $this->settings->featureEnabled('feature_ads')) {
            throw ValidationException::withMessages([
                'post_id' => [__('Advertising campaigns are currently disabled by the administrator.')],
            ]);
        }

        if ($user->isRestrictedFrom('advertise')) {
            throw ValidationException::withMessages([
                'post_id' => [__('Your account is currently restricted from running advertisements.')],
            ]);
        }

        /** @var Post|null $post */
        $post = Post::query()->find((int) $data['post_id']);

        if (
            $post === null
            || $post->user_id !== $user->id
            || $post->visibility !== PostVisibility::Public
            || $post->moderation_status !== ModerationStatus::Approved
            || $post->published_at === null
        ) {
            throw ValidationException::withMessages([
                'post_id' => [__('You can only promote your own public approved posts.')],
            ]);
        }

        $objective = AdObjective::tryFrom($data['objective']) ?? AdObjective::Reach;
        $budgetType = AdBudgetType::tryFrom($data['budget_type']) ?? AdBudgetType::Daily;
        $durationDays = max(1, min(90, (int) ($data['duration_days'] ?? 7)));

        $minDaily = (int) $this->settings->get('ad_min_daily_budget_vnd', (int) config('syncviet.ad_pricing.min_daily_budget_vnd', 50_000));
        $maxDaily = (int) $this->settings->get('ad_max_daily_budget_vnd', (int) config('syncviet.ad_pricing.max_daily_budget_vnd', 10_000_000));
        $minTotal = (int) $this->settings->get('ad_min_total_budget_vnd', (int) config('syncviet.ad_pricing.min_total_budget_vnd', 100_000));
        $maxTotal = (int) $this->settings->get('ad_max_total_budget_vnd', (int) config('syncviet.ad_pricing.max_total_budget_vnd', 50_000_000));

        $dailyBudget = null;

        if ($budgetType === AdBudgetType::Daily) {
            $dailyBudget = (int) ($data['daily_budget'] ?? 0);

            if ($dailyBudget < $minDaily || $dailyBudget > $maxDaily) {
                throw ValidationException::withMessages([
                    'daily_budget' => [__('Daily budget is outside allowed limits.')],
                ]);
            }

            $totalBudget = $dailyBudget * $durationDays;
        } else {
            $totalBudget = (int) ($data['total_budget'] ?? 0);
        }

        if ($totalBudget < $minTotal || $totalBudget > $maxTotal) {
            throw ValidationException::withMessages([
                'total_budget' => [__('Total campaign budget is outside allowed limits.')],
            ]);
        }

        $scheduleMode = $data['schedule_mode'] === 'scheduled' ? 'scheduled' : 'immediate';
        $startsAt = $scheduleMode === 'scheduled' && ! empty($data['starts_at'])
            ? CarbonImmutable::parse($data['starts_at'])->utc()
            : now();
        $endsAt = $scheduleMode === 'scheduled' && ! empty($data['ends_at'])
            ? CarbonImmutable::parse($data['ends_at'])->utc()
            : $startsAt->addDays($durationDays);

        $topics = array_slice(
            array_map(
                static fn (string $topic): string => mb_substr(trim($topic), 0, 40),
                array_filter($data['target_topics'] ?? [], static fn (string $t): bool => trim($t) !== ''),
            ),
            0,
            5,
        );

        $campaignName = isset($data['name']) && trim($data['name']) !== ''
            ? mb_substr(trim($data['name']), 0, 140)
            : mb_substr($post->title ?: $post->content, 0, 120);

        $targetUrl = isset($data['target_url']) && trim($data['target_url']) !== ''
            ? trim($data['target_url'])
            : $post->link_url;

        $ctaLabel = isset($data['cta_label']) && trim($data['cta_label']) !== ''
            ? mb_substr(trim($data['cta_label']), 0, 60)
            : __('Learn more');

        return DB::transaction(function () use (
            $user,
            $post,
            $campaignName,
            $objective,
            $topics,
            $targetUrl,
            $ctaLabel,
            $scheduleMode,
            $startsAt,
            $endsAt,
            $budgetType,
            $dailyBudget,
            $durationDays,
            $totalBudget,
        ): AdCampaign {
            $campaign = new AdCampaign([
                'name' => $campaignName,
                'objective' => $objective->value,
                'target_topics' => $topics === [] ? null : $topics,
                'target_url' => $targetUrl,
                'cta_label' => $ctaLabel,
                'schedule_mode' => $scheduleMode,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'budget_type' => $budgetType->value,
                'daily_budget' => $dailyBudget,
                'duration_days' => $durationDays,
            ]);
            $campaign->user_id = $user->id;
            $campaign->post_id = $post->id;
            $campaign->total_budget = $totalBudget;
            $campaign->held_amount = 0;
            $campaign->spent_amount = 0;
            $campaign->status = AdCampaignStatus::PendingReview;
            $campaign->save();

            $this->walletService->holdForCampaign($user, $campaign, $totalBudget);

            $this->auditLogger->log(
                'ads.campaign_created',
                $campaign,
                ['post_id' => $post->id, 'total_budget' => $totalBudget, 'objective' => $objective->value],
                $user,
            );

            return $campaign;
        });
    }

    public function updateCampaignStatus(User $user, AdCampaign $campaign, string $action): AdCampaign
    {
        return DB::transaction(function () use ($user, $campaign, $action): AdCampaign {
            /** @var AdCampaign $locked */
            $locked = AdCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($action === 'pause') {
                if ($locked->status !== AdCampaignStatus::Active) {
                    throw ValidationException::withMessages([
                        'action' => [__('Only active campaigns can be paused.')],
                    ]);
                }

                $locked->status = AdCampaignStatus::Paused;
                $locked->save();
            } elseif ($action === 'resume') {
                if ($locked->status !== AdCampaignStatus::Paused || $locked->held_amount <= 0) {
                    throw ValidationException::withMessages([
                        'action' => [__('This campaign cannot be resumed.')],
                    ]);
                }

                $locked->status = AdCampaignStatus::Active;
                $locked->save();
            } elseif ($action === 'cancel') {
                if (! in_array($locked->status, [AdCampaignStatus::PendingReview, AdCampaignStatus::Active, AdCampaignStatus::Paused], true)) {
                    throw ValidationException::withMessages([
                        'action' => [__('This campaign is already closed.')],
                    ]);
                }

                $locked->status = AdCampaignStatus::Cancelled;
                $locked->save();

                $this->walletService->releaseCampaignHold($locked, CreditEntryType::Refund, __('Refunded cancelled campaign :name', ['name' => $locked->name]));
            }

            $this->auditLogger->log(
                "ads.campaign_{$action}",
                $locked,
                ['status' => $locked->status->value],
                $user,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewQueuePayload(): array
    {
        $campaigns = AdCampaign::query()
            ->with([
                'owner:id,name,username',
                'reviewer:id,username',
                'post:id,title,content,category,user_id',
                'post.media',
            ])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static function (AdCampaign $campaign): array {
                $data = self::serializeCampaign($campaign);
                $data['owner_name'] = $campaign->owner?->name;
                $data['owner_username'] = $campaign->owner?->username;
                $data['reviewer_username'] = $campaign->reviewer?->username;

                return $data;
            })
            ->values()
            ->all();

        return [
            'campaigns' => $campaigns,
        ];
    }

    public function reviewCampaign(User $reviewer, AdCampaign $campaign, string $decision, ?string $reason = null): AdCampaign
    {
        $updated = DB::transaction(function () use ($reviewer, $campaign, $decision, $reason): AdCampaign {
            /** @var AdCampaign $locked */
            $locked = AdCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AdCampaignStatus::PendingReview) {
                throw ValidationException::withMessages([
                    'decision' => [__('Only pending campaigns can be reviewed.')],
                ]);
            }

            $cleanReason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

            if ($decision === 'approve') {
                $locked->status = AdCampaignStatus::Active;
                $locked->starts_at ??= now();
                $locked->rejection_reason = null;
            } else {
                $locked->status = AdCampaignStatus::Rejected;
                $locked->rejection_reason = $cleanReason ?: __('Campaign did not meet advertising guidelines.');
            }

            $locked->reviewed_by = $reviewer->id;
            $locked->reviewed_at = now();
            $locked->save();

            if ($decision === 'reject') {
                $this->walletService->releaseCampaignHold(
                    $locked,
                    CreditEntryType::Refund,
                    __('Refunded rejected campaign :name', ['name' => $locked->name]),
                );
            }

            $this->auditLogger->log(
                "ads.campaign_reviewed_{$decision}",
                $locked,
                ['reason' => $cleanReason],
                $reviewer,
            );

            return $locked->fresh(['owner']) ?? $locked;
        });

        if ($updated->owner !== null) {
            $approved = $decision === 'approve';
            $this->notificationService->notifyIfAllowed(
                $updated->owner,
                $reviewer,
                new SocialActivityNotification(
                    eventKey: $approved ? 'ad_approved' : 'ad_rejected',
                    titleVi: $approved
                        ? "Chiến dịch \"{$updated->name}\" đã được phê duyệt và bắt đầu phân phối."
                        : "Chiến dịch \"{$updated->name}\" bị từ chối và ngân sách đã hoàn lại ví.",
                    titleEn: $approved
                        ? "Your ad campaign \"{$updated->name}\" was approved and is now active."
                        : "Your ad campaign \"{$updated->name}\" was rejected and funds were refunded to your wallet.",
                    url: route('ads.index'),
                    actorName: $reviewer->name,
                    actorUsername: $reviewer->username,
                ),
            );
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeCampaign(AdCampaign $campaign): array
    {
        $post = $campaign->post;

        return [
            'id' => $campaign->id,
            'post_id' => $campaign->post_id,
            'name' => $campaign->name,
            'objective' => $campaign->objective->value,
            'objective_label' => $campaign->objective->label(),
            'target_topics' => $campaign->target_topics ?? [],
            'target_url' => $campaign->target_url,
            'cta_label' => $campaign->cta_label,
            'schedule_mode' => $campaign->schedule_mode,
            'starts_at' => $campaign->starts_at?->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
            'budget_type' => $campaign->budget_type->value,
            'daily_budget' => $campaign->daily_budget,
            'duration_days' => $campaign->duration_days,
            'total_budget' => $campaign->total_budget,
            'held_amount' => $campaign->held_amount,
            'spent_amount' => $campaign->spent_amount,
            'impressions_count' => $campaign->impressions_count,
            'clicks_count' => $campaign->clicks_count,
            'ctr_percent' => $campaign->impressions_count > 0
                ? round(($campaign->clicks_count / $campaign->impressions_count) * 100, 2)
                : 0.0,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'rejection_reason' => $campaign->rejection_reason,
            'created_at' => $campaign->created_at?->toIso8601String(),
            'post' => $post === null ? null : [
                'id' => $post->id,
                'title' => $post->title,
                'excerpt' => mb_substr($post->content, 0, 140),
                'image' => $post->relationLoaded('media') ? $post->media->first()?->url() : null,
            ],
        ];
    }
}
