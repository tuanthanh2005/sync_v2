<?php

use App\Http\Controllers\Admin\AdReviewController;
use App\Http\Controllers\Admin\Ai\AiAgentsAndPromptsController;
use App\Http\Controllers\Admin\Ai\AiAssistantAndTranslationController;
use App\Http\Controllers\Admin\Ai\AiContentWorkflowController;
use App\Http\Controllers\Admin\Ai\AiCredentialsAndModelsController;
use App\Http\Controllers\Admin\Ai\AiOverviewController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AppealController;
use App\Http\Controllers\Admin\BadgeManagementController;
use App\Http\Controllers\Admin\ContentModerationController;
use App\Http\Controllers\Admin\ContentSettingsAndAnalyticsController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\Growth\GrowthCampaignsAndProgramsController;
use App\Http\Controllers\Admin\Growth\GrowthOverviewController;
use App\Http\Controllers\Admin\Growth\GrowthReferralsAndFraudController;
use App\Http\Controllers\Admin\LanguageManagementController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\Monetization\BannerAdminController;
use App\Http\Controllers\Admin\Monetization\BoostAdminController;
use App\Http\Controllers\Admin\Monetization\MonetizationSettingsAdminController;
use App\Http\Controllers\Admin\Monetization\PremiumAdminController;
use App\Http\Controllers\Admin\Monetization\RevenueDashboardController;
use App\Http\Controllers\Admin\NotificationCenterController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PolicyManagementController;
use App\Http\Controllers\Admin\RoleManagementController;
use App\Http\Controllers\Admin\SecurityAuditController;
use App\Http\Controllers\Admin\Seo\SeoIntegrationsController;
use App\Http\Controllers\Admin\Seo\SeoInternationalAndAuditController;
use App\Http\Controllers\Admin\Seo\SeoOverviewController;
use App\Http\Controllers\Admin\Seo\SeoUrlsAndSitemapsController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Ads\AdCampaignController;
use App\Http\Controllers\Auth\EmailVerificationChallengeController;
use App\Http\Controllers\Auth\PendingRegistrationController;
use App\Http\Controllers\Auth\SecureForgotPasswordController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Billing\PaymentWebhookController;
use App\Http\Controllers\Billing\WalletController;
use App\Http\Controllers\Community\CommunityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Growth\ReferralController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Messaging\MessageController;
use App\Http\Controllers\Moderation\ReportController;
use App\Http\Controllers\Monetization\BannerOrderController;
use App\Http\Controllers\Monetization\BoostPostController;
use App\Http\Controllers\Monetization\PaymentHistoryController;
use App\Http\Controllers\Monetization\PremiumController;
use App\Http\Controllers\Monetization\ServicesCenterController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\PolicyPageController;
use App\Http\Controllers\Seo\SeoPublicController;
use App\Http\Controllers\Seo\TopicController;
use App\Http\Controllers\Social\CommentController;
use App\Http\Controllers\Social\PostController;
use App\Http\Controllers\Social\PostInteractionController;
use App\Http\Controllers\Social\ProfileViewController;
use App\Http\Controllers\Social\SearchController;
use App\Services\System\LanguageService;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/feed/more', [HomeController::class, 'more'])
    ->middleware('throttle:60,1')
    ->name('feed.more');
Route::get('/search', SearchController::class)
    ->middleware('throttle:60,1')
    ->name('search');
Route::get('/search/suggestions', [SearchController::class, 'suggestions'])
    ->middleware('throttle:90,1')
    ->name('search.suggestions');
Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');
Route::get('/posts/{post}/reactions', [PostInteractionController::class, 'reactions'])
    ->middleware('throttle:90,1')
    ->name('posts.reactions.index');
Route::post('/posts/views/batch', [PostInteractionController::class, 'trackViews'])
    ->middleware('throttle:60,1')
    ->name('posts.views.batch');
Route::get('/u/{user:username}', [ProfileViewController::class, 'show'])->name('users.show');
Route::get('/u/{user:username}/followers', [ProfileViewController::class, 'followers'])
    ->middleware('throttle:90,1')
    ->name('users.followers');
Route::get('/u/{user:username}/following', [ProfileViewController::class, 'following'])
    ->middleware('throttle:90,1')
    ->name('users.following');
Route::get('/communities', [CommunityController::class, 'index'])->name('communities.index');
Route::get('/communities/{community:slug}', [CommunityController::class, 'show'])->name('communities.show');
Route::get('/ads/{adCampaign}/click', [AdCampaignController::class, 'click'])
    ->middleware('throttle:60,1')
    ->name('ads.click');
Route::post('/webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');

Route::post('/locale', LocaleController::class)
    ->middleware('throttle:30,1')
    ->name('locale.update');

// ─── Public Referral Landing Redirect & Signed Unsubscribe ───────────────────
Route::get('/r/{code}', [ReferralController::class, 'visit'])
    ->where('code', '[A-Za-z0-9_-]{4,24}')
    ->middleware('throttle:60,1')
    ->name('referral.visit');
Route::get('/growth/unsubscribe/{user}/{channel}', [ReferralController::class, 'unsubscribe'])
    ->middleware('signed')
    ->name('growth.unsubscribe');

// ─── Social OAuth ────────────────────────────────────────────────────────────
Route::prefix('auth/{provider}')
    ->middleware('throttle:20,1')
    ->group(function () {
        Route::get('redirect', [SocialAuthController::class, 'redirect'])->name('auth.social.redirect');
        Route::get('callback', [SocialAuthController::class, 'callback'])->name('auth.social.callback');
    });

// ─── Hardened Authentication, Pending Registration & OTP Verification V1.0 ───
Route::post('/register', [PendingRegistrationController::class, 'store'])
    ->middleware(['guest', 'throttle:register'])
    ->name('register.store');

Route::post('/forgot-password', [SecureForgotPasswordController::class, 'store'])
    ->middleware(['guest', 'throttle:15,1'])
    ->name('password.email');

Route::get('/email/verify', [EmailVerificationChallengeController::class, 'show'])
    ->name('verification.notice');
Route::post('/email/verify/otp', [EmailVerificationChallengeController::class, 'verifyOtp'])
    ->middleware('throttle:20,1')
    ->name('auth.verify.otp');
Route::post('/email/verify/resend', [EmailVerificationChallengeController::class, 'resend'])
    ->middleware('throttle:10,1')
    ->name('auth.verify.resend');
Route::post('/email/verify/change-email', [EmailVerificationChallengeController::class, 'changeEmail'])
    ->middleware('throttle:10,1')
    ->name('auth.verify.change-email');
Route::post('/email/verify/cancel', [EmailVerificationChallengeController::class, 'cancel'])
    ->middleware('throttle:10,1')
    ->name('auth.verify.cancel');
Route::get('/verify-email/confirm/{token}', [EmailVerificationChallengeController::class, 'showConfirmLink'])
    ->where('token', '[a-f0-9]{64}')
    ->name('auth.verify.confirm.show');
Route::post('/verify-email/confirm/{token}', [EmailVerificationChallengeController::class, 'confirmLink'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:15,1')
    ->name('auth.verify.confirm.store');

Route::get('/policies/{policyPage:slug}', [PolicyPageController::class, 'show'])
    ->name('policies.show');

// ─── Public Services Center, Banners & SEO Endpoints ─────────────────────────
Route::get('/services', [ServicesCenterController::class, 'index'])->name('monetization.services');
Route::get('/premium', [ServicesCenterController::class, 'premium'])->name('monetization.premium');
Route::get('/advertising', [ServicesCenterController::class, 'advertising'])->name('monetization.advertising');
Route::get('/advertising/banner', [ServicesCenterController::class, 'banner'])->name('monetization.banner');
Route::get('/advertising/boost', [ServicesCenterController::class, 'boost'])->name('monetization.boost');
Route::get('/banners/{bannerCampaign}/click', [ServicesCenterController::class, 'bannerClick'])
    ->middleware('throttle:60,1')
    ->name('banners.click');
Route::post('/banners/{bannerCampaign}/event', [ServicesCenterController::class, 'bannerEvent'])
    ->middleware('throttle:30,1')
    ->name('banners.event');
Route::get('/ads.txt', [ServicesCenterController::class, 'adsTxt'])->name('seo.ads-txt');
Route::get('/robots.txt', [SeoPublicController::class, 'robots'])->name('seo.robots');
Route::get('/sitemap.xml', [SeoPublicController::class, 'sitemapIndex'])->name('seo.sitemap');
Route::get('/sitemap-{shard}.xml', [SeoPublicController::class, 'sitemapShard'])
    ->where('shard', '[a-zA-Z0-9_-]+')
    ->name('seo.sitemap.shard');
Route::get('/og-default.svg', [SeoPublicController::class, 'defaultOgImage'])->name('seo.og-default');
Route::get('/google{token}.html', [SeoPublicController::class, 'googleVerificationFile'])
    ->where('token', '[a-zA-Z0-9_-]{8,80}')
    ->name('seo.google-verification');
Route::get('/{key}.txt', [SeoPublicController::class, 'indexNowKeyFile'])
    ->where('key', '[a-zA-Z0-9]{16,128}')
    ->name('seo.indexnow-key');

// ─── Public Topic Clusters & Localized International SEO Routes ──────────────
$seoLocaleRegex = implode('|', LanguageService::allCodes());

Route::get('/topics', [TopicController::class, 'index'])->name('topics.index');
Route::get('/topics/{slug}', [TopicController::class, 'show'])->name('topics.show');

Route::prefix('{locale}')
    ->where(['locale' => $seoLocaleRegex])
    ->group(function (): void {
        Route::get('/posts/{postId}-{slug}', [PostController::class, 'showLocalized'])
            ->whereNumber('postId')
            ->where('slug', '[a-zA-Z0-9_-]+')
            ->name('posts.localized.slug');
        Route::get('/posts/{postId}', [PostController::class, 'showLocalized'])
            ->whereNumber('postId')
            ->name('posts.localized');
        Route::get('/topics', [TopicController::class, 'index'])->name('topics.localized.index');
        Route::get('/topics/{slug}', [TopicController::class, 'show'])->name('topics.localized.show');
        Route::get('/communities/{community:slug}', [CommunityController::class, 'showLocalized'])->name('communities.localized.show');
        Route::get('/policies/{policyPage:slug}', [PolicyPageController::class, 'showLocalized'])->name('policies.localized.show');
    });

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('/profile', [ProfileViewController::class, 'me'])->name('profile.me');
    Route::patch('/profile/details', [ProfileViewController::class, 'updateDetails'])->name('profile.details.update');

    Route::post('/u/{user:username}/follow', [ProfileViewController::class, 'toggleFollow'])
        ->middleware('throttle:30,1')
        ->name('users.follow');
    Route::post('/u/{user:username}/block', [ProfileViewController::class, 'toggleBlock'])
        ->middleware('throttle:20,1')
        ->name('users.block');

    Route::post('/posts', [PostController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('posts.store');
    Route::patch('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
    Route::post('/posts/link-preview', [PostController::class, 'previewLink'])
        ->middleware('throttle:20,1')
        ->name('posts.link-preview');

    Route::post('/posts/{post}/react', [PostInteractionController::class, 'react'])
        ->middleware('throttle:60,1')
        ->name('posts.react');
    Route::post('/posts/{post}/bookmark', [PostInteractionController::class, 'bookmark'])
        ->middleware('throttle:60,1')
        ->name('posts.bookmark');
    Route::post('/posts/{post}/vote', [PostInteractionController::class, 'votePoll'])
        ->middleware('throttle:30,1')
        ->name('posts.vote');
    Route::post('/posts/{post}/share', [PostInteractionController::class, 'share'])
        ->middleware('throttle:30,1')
        ->name('posts.share');
    Route::post('/posts/{post}/hide-from-feed', [PostInteractionController::class, 'hideFromFeed'])
        ->middleware('throttle:60,1')
        ->name('posts.hide-from-feed');

    Route::post('/posts/{post}/comments', [CommentController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('comments.store');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

    Route::post('/communities', [CommunityController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('communities.store');
    Route::patch('/communities/{community:slug}', [CommunityController::class, 'update'])
        ->name('communities.update');
    Route::post('/communities/{community:slug}/membership', [CommunityController::class, 'toggleMembership'])
        ->middleware('throttle:30,1')
        ->name('communities.membership');
    Route::post('/communities/{community:slug}/posts/{post}/pin', [CommunityController::class, 'togglePin'])
        ->name('communities.posts.pin');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{conversation}', [MessageController::class, 'index'])->name('messages.show');
    Route::post('/messages/start/{user:username}', [MessageController::class, 'start'])
        ->middleware('throttle:20,1')
        ->name('messages.start');
    Route::post('/messages/{conversation}', [MessageController::class, 'send'])
        ->middleware('throttle:60,1')
        ->name('messages.send');

    Route::post('/reports', [ReportController::class, 'store'])
        ->middleware('throttle:15,1')
        ->name('reports.store');

    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/top-up', [WalletController::class, 'storeTopUp'])
        ->middleware('throttle:15,1')
        ->name('wallet.top-up');

    Route::get('/ads', [AdCampaignController::class, 'index'])->name('ads.index');
    Route::post('/ads/campaigns', [AdCampaignController::class, 'store'])
        ->middleware('throttle:15,1')
        ->name('ads.campaigns.store');
    Route::post('/ads/campaigns/{adCampaign}/status', [AdCampaignController::class, 'updateStatus'])
        ->middleware('throttle:30,1')
        ->name('ads.campaigns.status');

    // ── Authenticated Monetization: Premium, Banner Orders, Boost Post & Payments ─
    Route::get('/account/premium', [PremiumController::class, 'account'])->name('account.premium');
    Route::post('/premium/checkout', [PremiumController::class, 'checkout'])
        ->middleware('throttle:15,1')
        ->name('premium.checkout');
    Route::post('/advertising/banner/campaigns', [BannerOrderController::class, 'store'])
        ->middleware('throttle:15,1')
        ->name('banners.campaigns.store');
    Route::post('/posts/{post}/boost', [BoostPostController::class, 'store'])
        ->middleware('throttle:15,1')
        ->name('posts.boost');

    Route::get('/payments', [PaymentHistoryController::class, 'index'])->name('payments.index');
    Route::get('/payments/{payment}', [PaymentHistoryController::class, 'show'])->name('payments.show');
    Route::post('/payments/{payment}/cancel', [PaymentHistoryController::class, 'cancel'])
        ->middleware('throttle:20,1')
        ->name('payments.cancel');
    Route::post('/payments/{payment}/sandbox-confirm', [PaymentHistoryController::class, 'sandboxConfirm'])
        ->middleware('throttle:15,1')
        ->name('payments.sandbox-confirm');

    // ── Member Growth: Referral Hub, Onboarding & Retention Preferences ──────
    Route::get('/referral', [ReferralController::class, 'index'])->name('referral.index');
    Route::post('/referral/preferences', [ReferralController::class, 'updateNotificationPreferences'])
        ->middleware('throttle:20,1')
        ->name('referral.preferences');
    Route::get('/onboarding', [ReferralController::class, 'onboarding'])->name('onboarding.index');
    Route::post('/onboarding', [ReferralController::class, 'updateOnboarding'])
        ->middleware('throttle:30,1')
        ->name('onboarding.update');

    Route::prefix('admin')
        ->name('admin.')
        ->middleware(['admin.2fa', 'can:access-admin'])
        ->group(function () {
            // ── Overview, Analytics & System Health ──────────────────────────────
            Route::get('/', OverviewController::class)->name('overview');
            Route::get('/analytics', [AnalyticsController::class, 'index'])
                ->middleware('permission:analytics.view')
                ->name('analytics');
            Route::get('/system-health', [AnalyticsController::class, 'health'])
                ->middleware('permission:system_health.view')
                ->name('health');

            // ── User Management & RBAC ───────────────────────────────────────────
            Route::get('/users', [UserManagementController::class, 'index'])
                ->middleware('permission:users.view')
                ->name('users.index');
            Route::get('/users/{userId}', [UserManagementController::class, 'show'])
                ->whereNumber('userId')
                ->middleware('permission:users.view')
                ->name('users.show');
            Route::patch('/users/{userId}', [UserManagementController::class, 'update'])
                ->whereNumber('userId')
                ->middleware('permission:users.update')
                ->name('users.update');
            Route::post('/users/{userId}/sanction', [UserManagementController::class, 'sanction'])
                ->whereNumber('userId')
                ->middleware('permission:users.suspend,users.ban')
                ->name('users.sanction');
            Route::post('/users/{userId}/role', [UserManagementController::class, 'updateRole'])
                ->whereNumber('userId')
                ->middleware('permission:roles.manage')
                ->name('users.role');
            Route::post('/users/{userId}/revoke-sessions', [UserManagementController::class, 'revokeSessions'])
                ->whereNumber('userId')
                ->middleware('permission:users.update,users.suspend')
                ->name('users.revoke-sessions');
            Route::post('/users/{userId}/password-reset', [UserManagementController::class, 'sendPasswordReset'])
                ->whereNumber('userId')
                ->middleware('permission:users.update')
                ->name('users.password-reset');
            Route::post('/users/{userId}/lifecycle', [UserManagementController::class, 'lifecycle'])
                ->whereNumber('userId')
                ->middleware('permission:users.delete,users.restore')
                ->name('users.lifecycle');

            Route::get('/verifications', [UserManagementController::class, 'verifications'])
                ->middleware('permission:verification.view')
                ->name('verifications.index');
            Route::post('/verifications/{verificationRequest}', [UserManagementController::class, 'reviewVerification'])
                ->middleware('permission:verification.approve,verification.reject')
                ->name('verifications.review');

            Route::get('/roles', [RoleManagementController::class, 'index'])
                ->middleware('permission:roles.view')
                ->name('roles.index');
            Route::patch('/roles/{role}/permissions', [RoleManagementController::class, 'syncPermissions'])
                ->middleware('permission:roles.manage')
                ->name('roles.permissions');

            Route::get('/badges', [BadgeManagementController::class, 'index'])
                ->middleware('permission:badges.view')
                ->name('badges.index');
            Route::post('/badges/grant', [BadgeManagementController::class, 'grant'])
                ->middleware('permission:badges.grant,badges.manage')
                ->name('badges.grant');
            Route::post('/badges/{badge}/revoke', [BadgeManagementController::class, 'revoke'])
                ->middleware('permission:badges.revoke,badges.manage')
                ->name('badges.revoke');
            Route::post('/badges/{badge}/visibility', [BadgeManagementController::class, 'toggleVisibility'])
                ->middleware('permission:badges.manage')
                ->name('badges.visibility');

            // ── Content Management ───────────────────────────────────────────────
            Route::get('/content/posts', [ContentModerationController::class, 'posts'])
                ->middleware('permission:posts.view')
                ->name('content.posts');
            Route::post('/content/posts/{postId}/moderate', [ContentModerationController::class, 'moderatePost'])
                ->whereNumber('postId')
                ->middleware('permission:posts.update,posts.delete')
                ->name('content.posts.moderate');

            Route::get('/content/comments', [ContentModerationController::class, 'comments'])
                ->middleware('permission:comments.view')
                ->name('content.comments');
            Route::post('/content/comments/{commentId}/moderate', [ContentModerationController::class, 'moderateComment'])
                ->whereNumber('commentId')
                ->middleware('permission:comments.update,comments.delete')
                ->name('content.comments.moderate');
            Route::post('/content/comments/bulk', [ContentModerationController::class, 'bulkModerateComments'])
                ->middleware('permission:comments.update,comments.delete')
                ->name('content.comments.bulk');

            Route::get('/content/communities', [ContentModerationController::class, 'communities'])
                ->middleware('permission:communities.view')
                ->name('content.communities');
            Route::post('/content/communities/{communityId}/moderate', [ContentModerationController::class, 'moderateCommunity'])
                ->whereNumber('communityId')
                ->middleware('permission:communities.update,communities.delete')
                ->name('content.communities.moderate');

            Route::get('/content/polls', [ContentModerationController::class, 'polls'])
                ->middleware('permission:polls.view')
                ->name('content.polls');
            Route::post('/content/polls/{poll}/moderate', [ContentModerationController::class, 'moderatePoll'])
                ->middleware('permission:polls.update,polls.delete')
                ->name('content.polls.moderate');

            Route::get('/content/hashtags', [ContentModerationController::class, 'hashtags'])
                ->middleware('permission:hashtags.view')
                ->name('content.hashtags');
            Route::patch('/content/hashtags/{hashtag}', [ContentModerationController::class, 'updateHashtag'])
                ->middleware('permission:hashtags.update')
                ->name('content.hashtags.update');

            Route::get('/content/media', [ContentModerationController::class, 'media'])
                ->middleware('permission:media.view')
                ->name('content.media');
            Route::post('/content/media/{media}/moderate', [ContentModerationController::class, 'moderateMedia'])
                ->middleware('permission:media.update,media.delete')
                ->name('content.media.moderate');

            Route::get('/content/settings', [ContentSettingsAndAnalyticsController::class, 'index'])
                ->middleware('permission:posts.settings.view,feed.settings.view,spam_events.view,settings.view')
                ->name('content.settings');
            Route::post('/content/settings', [ContentSettingsAndAnalyticsController::class, 'update'])
                ->middleware('permission:posts.settings.manage,feed.settings.manage,settings.update')
                ->name('content.settings.update');
            Route::post('/content/settings/reset', [ContentSettingsAndAnalyticsController::class, 'reset'])
                ->middleware('permission:posts.settings.manage,feed.settings.manage,settings.update')
                ->name('content.settings.reset');
            Route::post('/content/settings/simulate', [ContentSettingsAndAnalyticsController::class, 'simulate'])
                ->middleware('permission:ranking.analytics.view,posts.settings.view,analytics.view,settings.view')
                ->name('content.settings.simulate');

            Route::get('/content/keywords', [ContentModerationController::class, 'keywords'])
                ->middleware('permission:keywords.view')
                ->name('content.keywords');
            Route::post('/content/keywords', [ContentModerationController::class, 'storeKeyword'])
                ->middleware('permission:keywords.create')
                ->name('content.keywords.store');
            Route::delete('/content/keywords/{blockedKeyword}', [ContentModerationController::class, 'destroyKeyword'])
                ->middleware('permission:keywords.delete')
                ->name('content.keywords.destroy');

            // ── Report Center, Moderation & Appeals ──────────────────────────────
            Route::get('/moderation', [ModerationController::class, 'index'])
                ->middleware('can:access-moderation')
                ->name('moderation.index');
            Route::post('/moderation/reports/{report}', [ModerationController::class, 'resolve'])
                ->middleware('can:access-moderation')
                ->name('moderation.resolve');
            Route::patch('/moderation/reports/{report}/triage', [ModerationController::class, 'triage'])
                ->middleware('can:access-moderation')
                ->name('moderation.triage');

            Route::get('/appeals', [AppealController::class, 'index'])
                ->middleware('permission:appeals.view')
                ->name('appeals.index');
            Route::post('/appeals/{appeal}', [AppealController::class, 'resolve'])
                ->middleware('permission:appeals.approve,appeals.reject')
                ->name('appeals.resolve');

            // ── Advertising ──────────────────────────────────────────────────────
            Route::get('/ads', [AdReviewController::class, 'index'])
                ->middleware('can:review-ads')
                ->name('ads.index');
            Route::post('/ads/{adCampaign}/review', [AdReviewController::class, 'review'])
                ->middleware('can:review-ads')
                ->name('ads.review');
            Route::post('/ads/{adCampaign}/manage', [AdReviewController::class, 'manage'])
                ->middleware('permission:ads.manage,ads.update')
                ->name('ads.manage');

            // ── Finance & Ledger ─────────────────────────────────────────────────
            Route::get('/finance', [FinanceController::class, 'index'])
                ->middleware('permission:finance.view')
                ->name('finance.index');
            Route::post('/finance/payments/{payment}/refund', [FinanceController::class, 'refund'])
                ->middleware('permission:finance.manage')
                ->name('finance.refund');
            Route::post('/finance/wallet/{user}/adjust', [FinanceController::class, 'adjustWallet'])
                ->middleware('permission:finance.manage')
                ->name('finance.wallet.adjust');
            Route::get('/finance/export-csv', [FinanceController::class, 'exportCsv'])
                ->middleware('permission:finance.export')
                ->name('finance.export-csv');

            // ── Monetization Administration Center ───────────────────────────────
            Route::prefix('monetization')->name('monetization.')->group(function () {
                Route::get('/revenue', [RevenueDashboardController::class, 'index'])
                    ->middleware('permission:monetization.view,finance.view')
                    ->name('revenue');
                Route::get('/revenue/export-csv', [RevenueDashboardController::class, 'exportCsv'])
                    ->middleware('permission:finance.export')
                    ->name('revenue.export-csv');

                Route::get('/premium', [PremiumAdminController::class, 'index'])
                    ->middleware('permission:premium.view,monetization.view')
                    ->name('premium');
                Route::post('/premium/plans', [PremiumAdminController::class, 'storePlan'])
                    ->middleware('permission:premium.manage')
                    ->name('premium.plans.store');
                Route::patch('/premium/plans/{premiumPlan}', [PremiumAdminController::class, 'updatePlan'])
                    ->middleware('permission:premium.manage')
                    ->name('premium.plans.update');
                Route::post('/premium/grant', [PremiumAdminController::class, 'grantSubscription'])
                    ->middleware('permission:premium.manage')
                    ->name('premium.grant');
                Route::post('/premium/subscriptions/{subscription}/manage', [PremiumAdminController::class, 'manageSubscription'])
                    ->middleware('permission:premium.manage')
                    ->name('premium.subscriptions.manage');

                Route::get('/banners', [BannerAdminController::class, 'index'])
                    ->middleware('permission:banners.view,monetization.view,ads.view')
                    ->name('banners');
                Route::patch('/banners/placements/{bannerPlacement}', [BannerAdminController::class, 'updatePlacement'])
                    ->middleware('permission:banners.manage')
                    ->name('banners.placements.update');
                Route::post('/banners/campaigns', [BannerAdminController::class, 'storeCampaign'])
                    ->middleware('permission:banners.manage')
                    ->name('banners.campaigns.store');
                Route::post('/banners/campaigns/{bannerCampaign}/review', [BannerAdminController::class, 'reviewCampaign'])
                    ->middleware('permission:banners.manage')
                    ->name('banners.campaigns.review');
                Route::post('/banners/campaigns/{bannerCampaign}/manage', [BannerAdminController::class, 'manageCampaign'])
                    ->middleware('permission:banners.manage')
                    ->name('banners.campaigns.manage');

                Route::get('/boosts', [BoostAdminController::class, 'index'])
                    ->middleware('permission:boosts.view,monetization.view,ads.view')
                    ->name('boosts');
                Route::post('/boosts/packages', [BoostAdminController::class, 'storePackage'])
                    ->middleware('permission:boosts.manage')
                    ->name('boosts.packages.store');
                Route::patch('/boosts/packages/{boostPackage}', [BoostAdminController::class, 'updatePackage'])
                    ->middleware('permission:boosts.manage')
                    ->name('boosts.packages.update');

                Route::get('/settings', [MonetizationSettingsAdminController::class, 'index'])
                    ->middleware('permission:monetization.view,finance.view')
                    ->name('settings');
                Route::patch('/payment-methods/{paymentMethod}', [MonetizationSettingsAdminController::class, 'updatePaymentMethod'])
                    ->middleware('permission:monetization.manage,finance.manage')
                    ->name('payment-methods.update');
                Route::post('/payments/{payment}/confirm', [MonetizationSettingsAdminController::class, 'confirmPayment'])
                    ->middleware('permission:finance.manage')
                    ->name('payments.confirm');
                Route::post('/payments/{payment}/refund', [MonetizationSettingsAdminController::class, 'refundPayment'])
                    ->middleware('permission:finance.manage')
                    ->name('payments.refund');
                Route::post('/adsense', [MonetizationSettingsAdminController::class, 'updateAdSense'])
                    ->middleware('permission:adsense.manage,monetization.manage')
                    ->name('adsense.update');
                Route::patch('/compliance/{platformComplianceRule}', [MonetizationSettingsAdminController::class, 'updateCompliance'])
                    ->middleware('permission:monetization.manage')
                    ->name('compliance.update');
                Route::post('/guardrails', [MonetizationSettingsAdminController::class, 'updateGeneralMonetization'])
                    ->middleware('permission:monetization.manage')
                    ->name('guardrails.update');
            });

            // ── International SEO & Organic Growth Center V2.0 ───────────────────
            Route::prefix('seo')->name('seo.')->group(function () {
                Route::get('/', [SeoOverviewController::class, 'index'])
                    ->middleware('permission:seo.view')
                    ->name('overview');

                Route::get('/integrations', [SeoIntegrationsController::class, 'index'])
                    ->middleware('permission:seo.view')
                    ->name('integrations');
                Route::post('/integrations/verification', [SeoIntegrationsController::class, 'saveVerification'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.verification.save');
                Route::post('/integrations/verification/check', [SeoIntegrationsController::class, 'checkVerification'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.verification.check');
                Route::post('/integrations/gsc/service-account', [SeoIntegrationsController::class, 'saveGscServiceAccount'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.gsc.service-account');
                Route::post('/integrations/gsc/oauth-client', [SeoIntegrationsController::class, 'saveGscOAuthClient'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.gsc.oauth-client');
                Route::get('/integrations/gsc/oauth-redirect', [SeoIntegrationsController::class, 'redirectGscOAuth'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.gsc.oauth-redirect');
                Route::get('/integrations/gsc/oauth-callback', [SeoIntegrationsController::class, 'callbackGscOAuth'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.gsc.oauth-callback');
                Route::post('/integrations/gsc/test', [SeoIntegrationsController::class, 'testGscConnection'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.gsc.test');
                Route::post('/integrations/gsc/disconnect', [SeoIntegrationsController::class, 'disconnectGsc'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.gsc.disconnect');
                Route::post('/integrations/gsc/sync-analytics', [SeoIntegrationsController::class, 'syncGscAnalytics'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.gsc.sync-analytics');
                Route::post('/integrations/gsc/submit-sitemap', [SeoIntegrationsController::class, 'submitGscSitemap'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.gsc.submit-sitemap');
                Route::post('/integrations/indexnow/toggle', [SeoIntegrationsController::class, 'toggleIndexNow'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.indexnow.toggle');
                Route::post('/integrations/indexnow/regenerate-key', [SeoIntegrationsController::class, 'regenerateIndexNowKey'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.indexnow.regenerate-key');
                Route::post('/integrations/indexnow/submit-batch', [SeoIntegrationsController::class, 'submitIndexNowBatch'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.indexnow.submit-batch');
                Route::post('/integrations/ga4', [SeoIntegrationsController::class, 'saveGa4'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.ga4.save');
                Route::post('/integrations/cloudflare', [SeoIntegrationsController::class, 'saveCloudflare'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.cloudflare.save');
                Route::post('/integrations/cloudflare/purge', [SeoIntegrationsController::class, 'purgeCloudflare'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('integrations.cloudflare.purge');
                Route::post('/integrations/pagespeed', [SeoIntegrationsController::class, 'runPageSpeed'])
                    ->middleware('permission:seo.view')
                    ->name('integrations.pagespeed');

                Route::get('/urls', [SeoUrlsAndSitemapsController::class, 'index'])
                    ->middleware('permission:seo.view')
                    ->name('urls');
                Route::post('/urls/backfill', [SeoUrlsAndSitemapsController::class, 'backfillRegistry'])
                    ->middleware('permission:seo.manage')
                    ->name('urls.backfill');
                Route::post('/urls/bulk', [SeoUrlsAndSitemapsController::class, 'bulkAction'])
                    ->middleware('permission:seo.manage')
                    ->name('urls.bulk');
                Route::get('/urls/export-csv', [SeoUrlsAndSitemapsController::class, 'exportRegistryCsv'])
                    ->middleware('permission:seo.view')
                    ->name('urls.export-csv');
                Route::post('/sitemaps/rebuild', [SeoUrlsAndSitemapsController::class, 'rebuildSitemaps'])
                    ->middleware('permission:seo.manage')
                    ->name('sitemaps.rebuild');
                Route::post('/inspections/run-batch', [SeoUrlsAndSitemapsController::class, 'runInspectionBatch'])
                    ->middleware('permission:seo.integrations,seo.manage')
                    ->name('inspections.run-batch');
                Route::post('/redirects', [SeoUrlsAndSitemapsController::class, 'storeRedirect'])
                    ->middleware('permission:seo.redirects,seo.manage')
                    ->name('redirects.store');
                Route::patch('/redirects/{seoRedirect}/toggle', [SeoUrlsAndSitemapsController::class, 'toggleRedirect'])
                    ->middleware('permission:seo.redirects,seo.manage')
                    ->name('redirects.toggle');
                Route::delete('/redirects/{seoRedirect}', [SeoUrlsAndSitemapsController::class, 'destroyRedirect'])
                    ->middleware('permission:seo.redirects,seo.manage')
                    ->name('redirects.destroy');
                Route::post('/robots', [SeoUrlsAndSitemapsController::class, 'updateRobots'])
                    ->middleware('permission:seo.manage')
                    ->name('robots.update');

                Route::get('/international', [SeoInternationalAndAuditController::class, 'international'])
                    ->middleware('permission:seo.view')
                    ->name('international');
                Route::post('/translations', [SeoInternationalAndAuditController::class, 'storeTranslation'])
                    ->middleware('permission:seo.translations,seo.manage')
                    ->name('translations.store');
                Route::post('/translations/{translation}/publish', [SeoInternationalAndAuditController::class, 'publishTranslation'])
                    ->middleware('permission:seo.translations,seo.manage')
                    ->name('translations.publish');
                Route::post('/translations/{translation}/unpublish', [SeoInternationalAndAuditController::class, 'unpublishTranslation'])
                    ->middleware('permission:seo.translations,seo.manage')
                    ->name('translations.unpublish');
                Route::post('/templates', [SeoInternationalAndAuditController::class, 'updateTemplates'])
                    ->middleware('permission:seo.manage')
                    ->name('templates.update');

                Route::get('/audit', [SeoInternationalAndAuditController::class, 'audit'])
                    ->middleware('permission:seo.view')
                    ->name('audit');
                Route::post('/audit/run', [SeoInternationalAndAuditController::class, 'runAuditNow'])
                    ->middleware('permission:seo.audit,seo.manage')
                    ->name('audit.run');
                Route::post('/audit/issues/{issue}/resolve', [SeoInternationalAndAuditController::class, 'resolveIssue'])
                    ->middleware('permission:seo.audit,seo.manage')
                    ->name('audit.issues.resolve');
                Route::post('/recommendations/refresh', [SeoInternationalAndAuditController::class, 'refreshRecommendations'])
                    ->middleware('permission:seo.audit,seo.manage')
                    ->name('recommendations.refresh');
                Route::post('/recommendations/{recommendation}/dismiss', [SeoInternationalAndAuditController::class, 'dismissRecommendation'])
                    ->middleware('permission:seo.audit,seo.manage')
                    ->name('recommendations.dismiss');
                Route::post('/settings', [SeoInternationalAndAuditController::class, 'updateSettings'])
                    ->middleware('permission:seo.manage')
                    ->name('settings.update');
            });

            // ── AI Content Center & Multilingual Automation V1.0 ─────────────────
            Route::prefix('ai')->name('ai.')->group(function () {
                Route::get('/', [AiOverviewController::class, 'index'])
                    ->middleware('permission:ai.view')
                    ->name('overview');
                Route::post('/emergency-stop', [AiOverviewController::class, 'toggleEmergencyStop'])
                    ->middleware('permission:ai.emergency_stop,ai.manage')
                    ->name('emergency-stop');
                Route::post('/settings', [AiOverviewController::class, 'updateSettings'])
                    ->middleware('permission:ai.manage')
                    ->name('settings.update');

                Route::get('/credentials', [AiCredentialsAndModelsController::class, 'index'])
                    ->middleware('permission:ai.view')
                    ->name('credentials');
                Route::post('/credentials', [AiCredentialsAndModelsController::class, 'storeCredential'])
                    ->middleware('permission:ai.credentials,ai.manage')
                    ->name('credentials.store');
                Route::patch('/credentials/{credential}', [AiCredentialsAndModelsController::class, 'updateCredential'])
                    ->middleware('permission:ai.credentials,ai.manage')
                    ->name('credentials.update');
                Route::delete('/credentials/{credential}', [AiCredentialsAndModelsController::class, 'destroyCredential'])
                    ->middleware('permission:ai.credentials,ai.manage')
                    ->name('credentials.destroy');
                Route::post('/credentials/{credential}/test', [AiCredentialsAndModelsController::class, 'testCredential'])
                    ->middleware('permission:ai.credentials,ai.manage')
                    ->name('credentials.test');
                Route::post('/credentials/{credential}/sync-models', [AiCredentialsAndModelsController::class, 'syncModels'])
                    ->middleware('permission:ai.credentials,ai.manage')
                    ->name('credentials.sync-models');
                Route::patch('/models/{model}', [AiCredentialsAndModelsController::class, 'updateModel'])
                    ->middleware('permission:ai.credentials,ai.manage')
                    ->name('models.update');

                Route::get('/agents', [AiAgentsAndPromptsController::class, 'index'])
                    ->middleware('permission:ai.view')
                    ->name('agents');
                Route::post('/agents/seed-starters', [AiAgentsAndPromptsController::class, 'seedStarterAgents'])
                    ->middleware('permission:ai.agents,ai.manage')
                    ->name('agents.seed-starters');
                Route::post('/agents', [AiAgentsAndPromptsController::class, 'storeAgent'])
                    ->middleware('permission:ai.agents,ai.manage')
                    ->name('agents.store');
                Route::patch('/agents/{agent}', [AiAgentsAndPromptsController::class, 'updateAgent'])
                    ->middleware('permission:ai.agents,ai.manage')
                    ->name('agents.update');
                Route::post('/prompts', [AiAgentsAndPromptsController::class, 'storePrompt'])
                    ->middleware('permission:ai.prompts,ai.manage')
                    ->name('prompts.store');
                Route::patch('/prompts/{template}', [AiAgentsAndPromptsController::class, 'updatePrompt'])
                    ->middleware('permission:ai.prompts,ai.manage')
                    ->name('prompts.update');
                Route::post('/prompts/{template}/rollback/{version}', [AiAgentsAndPromptsController::class, 'rollbackPrompt'])
                    ->middleware('permission:ai.prompts,ai.manage')
                    ->name('prompts.rollback');

                Route::get('/content', [AiContentWorkflowController::class, 'index'])
                    ->middleware('permission:ai.view')
                    ->name('content');
                Route::post('/sources', [AiContentWorkflowController::class, 'storeSource'])
                    ->middleware('permission:ai.manage,ai.review')
                    ->name('sources.store');
                Route::post('/plans', [AiContentWorkflowController::class, 'storePlan'])
                    ->middleware('permission:ai.manage')
                    ->name('plans.store');
                Route::patch('/plans/{plan}/toggle', [AiContentWorkflowController::class, 'togglePlan'])
                    ->middleware('permission:ai.manage')
                    ->name('plans.toggle');
                Route::post('/generate', [AiContentWorkflowController::class, 'generateNow'])
                    ->middleware('permission:ai.manage,ai.review')
                    ->name('generate');
                Route::patch('/drafts/{draft}', [AiContentWorkflowController::class, 'updateDraft'])
                    ->middleware('permission:ai.review,ai.manage')
                    ->name('drafts.update');
                Route::post('/drafts/{draft}/review', [AiContentWorkflowController::class, 'reviewDraft'])
                    ->middleware('permission:ai.review,ai.manage')
                    ->name('drafts.review');
                Route::post('/drafts/bulk-review', [AiContentWorkflowController::class, 'bulkReview'])
                    ->middleware('permission:ai.review,ai.manage')
                    ->name('drafts.bulk-review');
                Route::post('/drafts/{draft}/schedule', [AiContentWorkflowController::class, 'scheduleDraft'])
                    ->middleware('permission:ai.publish,ai.manage')
                    ->name('drafts.schedule');
                Route::post('/drafts/{draft}/publish', [AiContentWorkflowController::class, 'publishDraft'])
                    ->middleware('permission:ai.publish,ai.manage')
                    ->name('drafts.publish');
                Route::get('/drafts/export-csv', [AiContentWorkflowController::class, 'exportDraftsCsv'])
                    ->middleware('permission:ai.view')
                    ->name('drafts.export-csv');

                Route::get('/assist', [AiAssistantAndTranslationController::class, 'index'])
                    ->middleware('permission:ai.view')
                    ->name('assist');
                Route::post('/translate', [AiAssistantAndTranslationController::class, 'translatePost'])
                    ->middleware('permission:ai.manage,ai.review')
                    ->name('translate');
                Route::post('/replies/draft', [AiAssistantAndTranslationController::class, 'generateReplyDraft'])
                    ->middleware('permission:ai.manage,ai.review')
                    ->name('replies.draft');
                Route::post('/replies/{task}/approve', [AiAssistantAndTranslationController::class, 'approveReply'])
                    ->middleware('permission:ai.review,ai.publish,ai.manage')
                    ->name('replies.approve');
                Route::post('/replies/{task}/reject', [AiAssistantAndTranslationController::class, 'rejectReply'])
                    ->middleware('permission:ai.review,ai.manage')
                    ->name('replies.reject');
                Route::post('/moderation/assess', [AiAssistantAndTranslationController::class, 'assessModeration'])
                    ->middleware('permission:ai.moderation,ai.manage')
                    ->name('moderation.assess');
                Route::post('/moderation/{assessment}/resolve', [AiAssistantAndTranslationController::class, 'resolveAssessment'])
                    ->middleware('permission:ai.moderation,ai.manage')
                    ->name('moderation.resolve');
            });

            // ── User Growth, Referral, Early Member & Retention Center V1.0 ──────
            Route::prefix('growth')->name('growth.')->group(function () {
                Route::get('/', [GrowthOverviewController::class, 'index'])
                    ->middleware('permission:growth.view')
                    ->name('overview');
                Route::post('/analytics/refresh', [GrowthOverviewController::class, 'refreshAnalytics'])
                    ->middleware('permission:growth.view')
                    ->name('analytics.refresh');
                Route::post('/settings', [GrowthOverviewController::class, 'updateSettings'])
                    ->middleware('permission:growth.manage')
                    ->name('settings.update');

                Route::get('/referrals', [GrowthReferralsAndFraudController::class, 'index'])
                    ->middleware('permission:growth.view')
                    ->name('referrals');
                Route::post('/referrals/qualify-now', [GrowthReferralsAndFraudController::class, 'runQualificationNow'])
                    ->middleware('permission:growth.referrals,growth.manage')
                    ->name('referrals.qualify-now');
                Route::post('/referrals/{referral}/review', [GrowthReferralsAndFraudController::class, 'reviewReferral'])
                    ->middleware('permission:growth.fraud,growth.referrals,growth.manage')
                    ->name('referrals.review');
                Route::patch('/codes/{referralCode}/toggle', [GrowthReferralsAndFraudController::class, 'toggleCode'])
                    ->middleware('permission:growth.fraud,growth.referrals,growth.manage')
                    ->name('codes.toggle');
                Route::post('/rules', [GrowthReferralsAndFraudController::class, 'storeRule'])
                    ->middleware('permission:growth.rewards,growth.manage')
                    ->name('rules.store');
                Route::patch('/rules/{rule}', [GrowthReferralsAndFraudController::class, 'updateRule'])
                    ->middleware('permission:growth.rewards,growth.manage')
                    ->name('rules.update');
                Route::post('/rewards/manual-grant', [GrowthReferralsAndFraudController::class, 'manualGrant'])
                    ->middleware('permission:growth.rewards,growth.manage')
                    ->name('rewards.manual-grant');
                Route::post('/rewards/{reward}/revoke', [GrowthReferralsAndFraudController::class, 'revokeReward'])
                    ->middleware('permission:growth.rewards,growth.fraud,growth.manage')
                    ->name('rewards.revoke');
                Route::get('/referrals/export-csv', [GrowthReferralsAndFraudController::class, 'exportReferralsCsv'])
                    ->middleware('permission:growth.export,growth.view')
                    ->name('referrals.export-csv');

                Route::get('/campaigns', [GrowthCampaignsAndProgramsController::class, 'index'])
                    ->middleware('permission:growth.view')
                    ->name('campaigns');
                Route::post('/campaigns', [GrowthCampaignsAndProgramsController::class, 'storeCampaign'])
                    ->middleware('permission:growth.campaigns,growth.manage')
                    ->name('campaigns.store');
                Route::patch('/campaigns/{campaign}', [GrowthCampaignsAndProgramsController::class, 'updateCampaign'])
                    ->middleware('permission:growth.campaigns,growth.manage')
                    ->name('campaigns.update');
                Route::post('/early-members/enroll', [GrowthCampaignsAndProgramsController::class, 'enrollEarlyMember'])
                    ->middleware('permission:growth.early_members,growth.manage')
                    ->name('early-members.enroll');
                Route::post('/early-members/{earlyMember}/revoke', [GrowthCampaignsAndProgramsController::class, 'revokeEarlyMember'])
                    ->middleware('permission:growth.early_members,growth.fraud,growth.manage')
                    ->name('early-members.revoke');
                Route::post('/badges/grant', [GrowthCampaignsAndProgramsController::class, 'grantBadge'])
                    ->middleware('permission:growth.early_members,growth.manage')
                    ->name('badges.grant');
                Route::post('/badges/{badge}/revoke', [GrowthCampaignsAndProgramsController::class, 'revokeBadge'])
                    ->middleware('permission:growth.early_members,growth.fraud,growth.manage')
                    ->name('badges.revoke');
                Route::patch('/achievements/{achievement}/toggle', [GrowthCampaignsAndProgramsController::class, 'toggleAchievement'])
                    ->middleware('permission:growth.campaigns,growth.manage')
                    ->name('achievements.toggle');
            });

            // ── System: Languages, Settings, Notifications, Policies, Security, Backups ─
            Route::get('/languages', [LanguageManagementController::class, 'index'])
                ->middleware('permission:languages.view')
                ->name('languages.index');
            Route::get('/languages/audit/report', [LanguageManagementController::class, 'exportReport'])
                ->middleware('permission:languages.export')
                ->name('languages.audit-report');
            Route::post('/languages/{code}/update', [LanguageManagementController::class, 'update'])
                ->middleware('permission:languages.update')
                ->name('languages.update');
            Route::get('/languages/{code}/export', [LanguageManagementController::class, 'export'])
                ->middleware('permission:languages.export')
                ->name('languages.export');
            Route::post('/languages/{code}/import', [LanguageManagementController::class, 'import'])
                ->middleware('permission:languages.update')
                ->name('languages.import');
            Route::post('/languages/{code}/rollback', [LanguageManagementController::class, 'rollback'])
                ->middleware('permission:languages.update')
                ->name('languages.rollback');

            Route::get('/settings', [SystemSettingsController::class, 'index'])
                ->middleware('permission:settings.view')
                ->name('settings.index');
            Route::post('/settings', [SystemSettingsController::class, 'update'])
                ->middleware('permission:settings.update')
                ->name('settings.update');
            Route::post('/settings/branding-asset', [SystemSettingsController::class, 'uploadBrandingAsset'])
                ->middleware('permission:settings.update')
                ->name('settings.branding-asset');

            Route::get('/notifications', [NotificationCenterController::class, 'index'])
                ->middleware('permission:notifications.view')
                ->name('notifications.index');
            Route::post('/notifications', [NotificationCenterController::class, 'store'])
                ->middleware('permission:notifications.create')
                ->name('notifications.store');
            Route::post('/notifications/{adminNotification}/cancel', [NotificationCenterController::class, 'cancel'])
                ->middleware('permission:notifications.update')
                ->name('notifications.cancel');
            Route::post('/notifications/templates', [NotificationCenterController::class, 'saveTemplate'])
                ->middleware('permission:notifications.manage')
                ->name('notifications.templates.save');

            Route::get('/security', [SecurityAuditController::class, 'index'])
                ->middleware('permission:security.view')
                ->name('security.index');
            Route::delete('/security/sessions/{sessionId}', [SecurityAuditController::class, 'revokeSession'])
                ->middleware('permission:security.manage,users.suspend')
                ->name('security.sessions.revoke');
            Route::post('/security/auth-protection/settings', [SecurityAuditController::class, 'updateAuthProtection'])
                ->middleware('permission:security.manage,settings.update')
                ->name('security.auth-protection.settings');
            Route::post('/security/auth-protection/emergency-pause', [SecurityAuditController::class, 'toggleEmergencyPause'])
                ->middleware('permission:security.manage,settings.update')
                ->name('security.auth-protection.emergency-pause');
            Route::post('/security/auth-protection/disposable-domains', [SecurityAuditController::class, 'storeDisposableDomain'])
                ->middleware('permission:security.manage,settings.update')
                ->name('security.auth-protection.disposable-domains.store');
            Route::delete('/security/auth-protection/disposable-domains/{disposableEmailDomain}', [SecurityAuditController::class, 'destroyDisposableDomain'])
                ->middleware('permission:security.manage,settings.update')
                ->name('security.auth-protection.disposable-domains.destroy');
            Route::post('/security/auth-protection/remediate-unverified', [SecurityAuditController::class, 'remediateUnverified'])
                ->middleware('permission:security.manage')
                ->name('security.auth-protection.remediate-unverified');
            Route::post('/security/auth-protection/cleanup-pending', [SecurityAuditController::class, 'cleanupPending'])
                ->middleware('permission:security.manage')
                ->name('security.auth-protection.cleanup-pending');

            Route::get('/backups', [SecurityAuditController::class, 'backups'])
                ->middleware('permission:backups.view')
                ->name('backups.index');
            Route::post('/backups', [SecurityAuditController::class, 'createBackup'])
                ->middleware('permission:backups.create')
                ->name('backups.store');
            Route::get('/backups/{backupRecord}/download', [SecurityAuditController::class, 'downloadBackup'])
                ->middleware('permission:backups.export')
                ->name('backups.download');

            Route::middleware(['role:admin'])->group(function () {
                Route::get('/policies', [PolicyManagementController::class, 'index'])
                    ->name('policies.index');
                Route::post('/policies', [PolicyManagementController::class, 'storePolicy'])
                    ->name('policies.store');
                Route::patch('/policies/{policyPage:slug}', [PolicyManagementController::class, 'updatePolicy'])
                    ->name('policies.update');
                Route::post('/policies/{policyPage:slug}/translations', [PolicyManagementController::class, 'saveTranslation'])
                    ->name('policies.translations.save');
                Route::post('/policies/{policyPage:slug}/revisions/{revision}/restore', [PolicyManagementController::class, 'restoreRevision'])
                    ->name('policies.revisions.restore');
                Route::post('/policies/upload-image', [PolicyManagementController::class, 'uploadImage'])
                    ->name('policies.upload-image');
                Route::post('/policies/footer-settings', [PolicyManagementController::class, 'updateFooterSettings'])
                    ->name('policies.footer-settings');
                Route::post('/footer-links', [PolicyManagementController::class, 'storeLink'])
                    ->name('footer-links.store');
                Route::patch('/footer-links/{footerLink}', [PolicyManagementController::class, 'updateLink'])
                    ->name('footer-links.update');
                Route::delete('/footer-links/{footerLink}', [PolicyManagementController::class, 'destroyLink'])
                    ->name('footer-links.destroy');
            });
        });
});

// Ensure unmatched URLs still execute the `web` middleware stack (StartSession + SetLocale)
// so 404 error pages render in the user's selected locale and RTL direction.
Route::fallback(static function (): never {
    abort(404);
});

require __DIR__.'/settings.php';
