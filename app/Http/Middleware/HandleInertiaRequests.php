<?php

namespace App\Http\Middleware;

use App\Models\PolicyPage;
use App\Services\Community\CommunityService;
use App\Services\Messaging\MessagingService;
use App\Services\Policy\FooterMenuService;
use App\Services\Social\HashtagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly FooterMenuService $footerMenuService,
        private readonly HashtagService $hashtagService,
        private readonly CommunityService $communityService,
        private readonly MessagingService $messagingService,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $user?->loadMissing(['profile', 'creditAccount']);
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appUrl' => config('app.url'),
            'locale' => $locale,
            'supportedLocales' => SetLocale::SUPPORTED_LOCALES,
            'footer' => $this->footerMenuService->shared($locale),
            'sidebarHighlights' => [
                'trendingHashtags' => $this->hashtagService->trending(5),
                'featuredCommunities' => $this->communityService->featuredForSidebar($user, 3),
            ],
            'unreadNotificationsCount' => $user !== null ? $user->unreadNotifications()->count() : 0,
            'unreadMessagesCount' => $user !== null ? $this->messagingService->totalUnreadCount($user) : 0,
            'walletBalance' => $user !== null ? (int) ($user->creditAccount->balance ?? 0) : 0,
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'avatar' => $user->avatarUrl(),
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'status' => $user->status->value,
                    'status_label' => $user->status->label(),
                    'timezone' => $user->timezone,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                    'created_at' => $user->created_at?->toIso8601String(),
                    'updated_at' => $user->updated_at?->toIso8601String(),
                    'can' => [
                        'accessAdmin' => Gate::forUser($user)->allows('access-admin'),
                        'accessModeration' => Gate::forUser($user)->allows('access-moderation'),
                        'reviewAds' => Gate::forUser($user)->allows('review-ads'),
                        'managePolicies' => Gate::forUser($user)->allows('viewAny', PolicyPage::class),
                    ],
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
