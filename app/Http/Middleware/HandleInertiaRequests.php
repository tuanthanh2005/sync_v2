<?php

namespace App\Http\Middleware;

use App\Enums\AdCampaignStatus;
use App\Enums\AiDraftStatus;
use App\Enums\BannerCampaignStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReportStatus;
use App\Models\AdCampaign;
use App\Models\AiContentDraft;
use App\Models\Appeal;
use App\Models\BannerCampaign;
use App\Models\GrowthFraudReview;
use App\Models\OnboardingProgress;
use App\Models\Payment;
use App\Models\PolicyPage;
use App\Models\Report;
use App\Models\VerificationRequest;
use App\Services\Community\CommunityService;
use App\Services\Growth\EarlyMemberAndBadgeService;
use App\Services\Messaging\MessagingService;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Monetization\BoostPostService;
use App\Services\Monetization\MonetizationPaymentService;
use App\Services\Monetization\PlatformCapabilityService;
use App\Services\Policy\FooterMenuService;
use App\Services\Social\HashtagService;
use App\Services\System\LanguageService;
use App\Services\System\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly FooterMenuService $footerMenuService,
        private readonly HashtagService $hashtagService,
        private readonly CommunityService $communityService,
        private readonly MessagingService $messagingService,
        private readonly LanguageService $languageService,
        private readonly SystemSettingService $systemSettingService,
        private readonly BannerAdvertisingService $bannerService,
        private readonly BoostPostService $boostService,
        private readonly MonetizationPaymentService $paymentService,
        private readonly PlatformCapabilityService $platformService,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $user?->loadMissing(['profile', 'creditAccount', 'rbacRole.permissions']);
        $locale = app()->getLocale();
        $systemPublic = $this->systemSettingService->publicPayload();

        $adminPendingCounts = null;

        if ($user !== null && $user->isActive() && $user->isStaff()) {
            $adminPendingCounts = [
                'reports' => Report::query()->whereIn('status', [ReportStatus::Pending->value, ReportStatus::UnderReview->value])->count(),
                'ads' => AdCampaign::query()->where('status', AdCampaignStatus::PendingReview->value)->count(),
                'banners' => Schema::hasTable('banner_campaigns') ? BannerCampaign::query()->where('status', BannerCampaignStatus::PendingReview->value)->count() : 0,
                'pending_orders' => Schema::hasTable('payments') ? Payment::query()->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])->count() : 0,
                'appeals' => Schema::hasTable('appeals') ? Appeal::query()->where('status', 'pending')->count() : 0,
                'verifications' => Schema::hasTable('verification_requests') ? VerificationRequest::query()->where('status', 'pending')->count() : 0,
                'ai_drafts' => Schema::hasTable('ai_content_drafts') ? AiContentDraft::query()->where('status', AiDraftStatus::PendingReview->value)->count() : 0,
                'fraud_reviews' => Schema::hasTable('growth_fraud_reviews') ? GrowthFraudReview::query()->where('status', 'pending')->count() : 0,
            ];
        }

        $isAdminOrSensitive = $request->is('admin*', 'wallet*', 'payments*', 'settings*', 'login', 'register', 'forgot-password', 'reset-password*', 'two-factor*');
        $sidebarBanners = $isAdminOrSensitive
            ? ['desktop_sidebar_right' => null, 'desktop_sidebar_premium' => null]
            : $this->bannerService->activeBannersForSlots(['desktop_sidebar_right', 'desktop_sidebar_premium'], $user, $request);

        return [
            ...parent::share($request),
            'name' => $systemPublic['siteName'] ?: (string) config('app.name'),
            'appUrl' => config('app.url'),
            'locale' => $locale,
            'isRtl' => $this->languageService->isRtl($locale),
            'languages' => $this->languageService->publicEnabledLanguages(),
            'translationOverrides' => $this->languageService->overridesForLocale($locale),
            'supportedLocales' => $this->languageService->enabledCodes(),
            'systemSettings' => $systemPublic,
            'footer' => $this->footerMenuService->shared($locale),
            'sidebarHighlights' => [
                'trendingHashtags' => $this->hashtagService->trending(5),
                'featuredCommunities' => $this->communityService->featuredForSidebar($user, 3),
                'banner' => $sidebarBanners['desktop_sidebar_right'] ?? $sidebarBanners['desktop_sidebar_premium'],
            ],
            'monetizationQuick' => $user === null ? null : [
                'boostPackages' => $this->boostService->publicPackages(),
                'paymentMethods' => $this->paymentService->availablePaymentMethods(null, $this->platformService->resolvePlatform($request)),
                'maxPendingOrders' => $this->paymentService->maxPendingPaymentsPerUser(),
            ],
            'unreadNotificationsCount' => $user !== null ? $user->unreadNotifications()->count() : 0,
            'unreadMessagesCount' => $user !== null ? $this->messagingService->totalUnreadCount($user) : 0,
            'walletBalance' => $user !== null ? (int) ($user->creditAccount->balance ?? 0) : 0,
            'adminPendingCounts' => $adminPendingCounts,
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'avatar' => $user->avatarUrl(),
                    'is_verified' => $user->profile !== null && $user->profile->is_verified_badge,
                    'is_premium' => $user->isPremium(),
                    'is_ai_agent' => $user->isAiAgent(),
                    'badges' => app(EarlyMemberAndBadgeService::class)->activeBadgesForUsers([$user->id])[$user->id] ?? [],
                    'needs_onboarding' => $user->isHumanMember() && Schema::hasTable('onboarding_progress')
                        ? OnboardingProgress::query()->where('user_id', $user->id)->whereNull('completed_at')->whereNull('skipped_at')->exists()
                        : false,
                    'premium_until' => $user->premium_until?->toIso8601String(),
                    'role' => $user->role->value,
                    'role_label' => $user->rbacRole !== null ? $user->rbacRole->name : $user->role->label(),
                    'rbac_role' => $user->rbacRole !== null ? $user->rbacRole->slug : $user->role->value,
                    'hierarchy_level' => $user->hierarchyLevel(),
                    'status' => $user->status->value,
                    'status_label' => $user->status->label(),
                    'timezone' => $user->timezone,
                    'preferred_locale' => $user->preferred_locale,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                    'created_at' => $user->created_at?->toIso8601String(),
                    'updated_at' => $user->updated_at?->toIso8601String(),
                    'restrictions' => [
                        'post' => $user->isRestrictedFrom('post'),
                        'comment' => $user->isRestrictedFrom('comment'),
                        'community' => $user->isRestrictedFrom('community'),
                        'message' => $user->isRestrictedFrom('message'),
                        'advertise' => $user->isRestrictedFrom('advertise'),
                    ],
                    'can' => [
                        'accessAdmin' => Gate::forUser($user)->allows('access-admin'),
                        'accessModeration' => Gate::forUser($user)->allows('access-moderation') || $user->hasPermission('moderation.view'),
                        'reviewAds' => Gate::forUser($user)->allows('review-ads') || $user->hasPermission('ads.view'),
                        'managePolicies' => Gate::forUser($user)->allows('viewAny', PolicyPage::class) || $user->hasPermission('policies.view'),
                        'viewAnalytics' => $user->hasPermission('analytics.view'),
                        'viewSystemHealth' => $user->hasPermission('system_health.view'),
                        'manageUsers' => $user->hasPermission('users.view'),
                        'manageRoles' => $user->hasPermission('roles.view'),
                        'manageVerifications' => $user->hasPermission('verification.view'),
                        'managePosts' => $user->hasPermission('posts.view'),
                        'manageComments' => $user->hasPermission('comments.view'),
                        'manageCommunities' => $user->hasPermission('communities.view'),
                        'managePolls' => $user->hasPermission('polls.view'),
                        'manageHashtags' => $user->hasPermission('hashtags.view'),
                        'manageMedia' => $user->hasPermission('media.view'),
                        'manageAppeals' => $user->hasPermission('appeals.view'),
                        'manageKeywords' => $user->hasPermission('keywords.view'),
                        'manageFinance' => $user->hasPermission('finance.view'),
                        'manageMonetization' => $user->hasPermission('monetization.view') || $user->hasPermission('finance.view'),
                        'managePremium' => $user->hasPermission('premium.view') || $user->hasPermission('monetization.view'),
                        'manageBanners' => $user->hasPermission('banners.view') || $user->hasPermission('ads.view'),
                        'manageBoosts' => $user->hasPermission('boosts.view') || $user->hasPermission('ads.view'),
                        'manageAdSense' => $user->hasPermission('adsense.manage') || $user->hasPermission('monetization.manage'),
                        'manageSettings' => $user->hasPermission('settings.view'),
                        'manageLanguages' => $user->hasPermission('languages.view'),
                        'manageNotifications' => $user->hasPermission('notifications.view'),
                        'viewSecurity' => $user->hasPermission('security.view'),
                        'manageBackups' => $user->hasPermission('backups.view'),
                        'viewSeo' => $user->hasPermission('seo.view'),
                        'manageSeo' => $user->hasPermission('seo.manage'),
                        'auditSeo' => $user->hasPermission('seo.audit') || $user->hasPermission('seo.manage'),
                        'manageSeoIntegrations' => $user->hasPermission('seo.integrations') || $user->hasPermission('seo.manage'),
                        'manageSeoTranslations' => $user->hasPermission('seo.translations') || $user->hasPermission('seo.manage'),
                        'manageSeoRedirects' => $user->hasPermission('seo.redirects') || $user->hasPermission('seo.manage'),
                        'viewAi' => $user->hasPermission('ai.view'),
                        'manageAi' => $user->hasPermission('ai.manage'),
                        'manageAiCredentials' => $user->hasPermission('ai.credentials') || $user->hasPermission('ai.manage'),
                        'manageAiAgents' => $user->hasPermission('ai.agents') || $user->hasPermission('ai.manage'),
                        'manageAiPrompts' => $user->hasPermission('ai.prompts') || $user->hasPermission('ai.manage'),
                        'reviewAi' => $user->hasPermission('ai.review') || $user->hasPermission('ai.manage'),
                        'publishAi' => $user->hasPermission('ai.publish') || $user->hasPermission('ai.manage'),
                        'moderateAi' => $user->hasPermission('ai.moderation') || $user->hasPermission('ai.manage'),
                        'emergencyStopAi' => $user->hasPermission('ai.emergency_stop') || $user->hasPermission('ai.manage'),
                        'viewGrowth' => $user->hasPermission('growth.view'),
                        'manageGrowth' => $user->hasPermission('growth.manage'),
                        'manageGrowthReferrals' => $user->hasPermission('growth.referrals') || $user->hasPermission('growth.manage'),
                        'manageGrowthRewards' => $user->hasPermission('growth.rewards') || $user->hasPermission('growth.manage'),
                        'manageGrowthCampaigns' => $user->hasPermission('growth.campaigns') || $user->hasPermission('growth.manage'),
                        'manageGrowthFraud' => $user->hasPermission('growth.fraud') || $user->hasPermission('growth.manage'),
                    ],
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
