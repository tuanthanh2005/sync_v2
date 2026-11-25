<?php

use App\Http\Controllers\Admin\AdReviewController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AppealController;
use App\Http\Controllers\Admin\ContentModerationController;
use App\Http\Controllers\Admin\FinanceController;
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
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Ads\AdCampaignController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Billing\PaymentWebhookController;
use App\Http\Controllers\Billing\WalletController;
use App\Http\Controllers\Community\CommunityController;
use App\Http\Controllers\DashboardController;
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
use App\Http\Controllers\Social\CommentController;
use App\Http\Controllers\Social\PostController;
use App\Http\Controllers\Social\PostInteractionController;
use App\Http\Controllers\Social\ProfileViewController;
use App\Http\Controllers\Social\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/feed/more', [HomeController::class, 'more'])
    ->middleware('throttle:60,1')
    ->name('feed.more');
Route::get('/search', SearchController::class)
    ->middleware('throttle:60,1')
    ->name('search');
Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');
Route::get('/u/{user:username}', [ProfileViewController::class, 'show'])->name('users.show');
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

// ─── Social OAuth ────────────────────────────────────────────────────────────
Route::prefix('auth/{provider}')
    ->middleware('throttle:20,1')
    ->group(function () {
        Route::get('redirect', [SocialAuthController::class, 'redirect'])->name('auth.social.redirect');
        Route::get('callback', [SocialAuthController::class, 'callback'])->name('auth.social.callback');
    });

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
Route::get('/sitemap.xml', [ServicesCenterController::class, 'sitemap'])->name('seo.sitemap');

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

            // ── System: Languages, Settings, Notifications, Policies, Security, Backups ─
            Route::get('/languages', [LanguageManagementController::class, 'index'])
                ->middleware('permission:languages.view')
                ->name('languages.index');
            Route::post('/languages/{code}/update', [LanguageManagementController::class, 'update'])
                ->middleware('permission:languages.update')
                ->name('languages.update');
            Route::get('/languages/{code}/export', [LanguageManagementController::class, 'export'])
                ->middleware('permission:languages.export')
                ->name('languages.export');
            Route::post('/languages/{code}/import', [LanguageManagementController::class, 'import'])
                ->middleware('permission:languages.update')
                ->name('languages.import');

            Route::get('/settings', [SystemSettingsController::class, 'index'])
                ->middleware('permission:settings.view')
                ->name('settings.index');
            Route::post('/settings', [SystemSettingsController::class, 'update'])
                ->middleware('permission:settings.update')
                ->name('settings.update');

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
                Route::patch('/policies/{policyPage:slug}', [PolicyManagementController::class, 'updatePolicy'])
                    ->name('policies.update');
                Route::post('/footer-links', [PolicyManagementController::class, 'storeLink'])
                    ->name('footer-links.store');
                Route::patch('/footer-links/{footerLink}', [PolicyManagementController::class, 'updateLink'])
                    ->name('footer-links.update');
                Route::delete('/footer-links/{footerLink}', [PolicyManagementController::class, 'destroyLink'])
                    ->name('footer-links.destroy');
            });
        });
});

require __DIR__.'/settings.php';
