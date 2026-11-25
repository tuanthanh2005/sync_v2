<?php

namespace App\Http\Middleware;

use App\Enums\AdCampaignStatus;
use App\Enums\ReportStatus;
use App\Models\AdCampaign;
use App\Models\Appeal;
use App\Models\PolicyPage;
use App\Models\Report;
use App\Models\VerificationRequest;
use App\Services\Community\CommunityService;
use App\Services\Messaging\MessagingService;
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
                'appeals' => Schema::hasTable('appeals') ? Appeal::query()->where('status', 'pending')->count() : 0,
                'verifications' => Schema::hasTable('verification_requests') ? VerificationRequest::query()->where('status', 'pending')->count() : 0,
            ];
        }

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
                        'manageSettings' => $user->hasPermission('settings.view'),
                        'manageLanguages' => $user->hasPermission('languages.view'),
                        'manageNotifications' => $user->hasPermission('notifications.view'),
                        'viewSecurity' => $user->hasPermission('security.view'),
                        'manageBackups' => $user->hasPermission('backups.view'),
                    ],
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
