<?php

use App\Http\Controllers\Admin\AdReviewController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PolicyManagementController;
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

    Route::prefix('admin')
        ->name('admin.')
        ->middleware(['role:admin,moderator', 'admin.2fa', 'can:access-admin'])
        ->group(function () {
            Route::get('/', OverviewController::class)->name('overview');
            Route::get('/moderation', [ModerationController::class, 'index'])
                ->middleware('can:access-moderation')
                ->name('moderation.index');
            Route::post('/moderation/reports/{report}', [ModerationController::class, 'resolve'])
                ->middleware('can:access-moderation')
                ->name('moderation.resolve');
            Route::get('/ads', [AdReviewController::class, 'index'])
                ->middleware('can:review-ads')
                ->name('ads.index');
            Route::post('/ads/{adCampaign}/review', [AdReviewController::class, 'review'])
                ->middleware('can:review-ads')
                ->name('ads.review');

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
