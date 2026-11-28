<?php

namespace App\Http\Controllers;

use App\Enums\PostCategory;
use App\Services\Ads\AdDeliveryService;
use App\Services\Monetization\AdSenseConfigService;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Seo\SeoMetadataService;
use App\Services\Social\FeedService;
use App\Services\Social\PostingPolicyConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly PostingPolicyConfigService $policyConfig,
        private readonly AdDeliveryService $adDeliveryService,
        private readonly BannerAdvertisingService $bannerService,
        private readonly AdSenseConfigService $adSenseService,
        private readonly SeoMetadataService $seoMetadata,
    ) {}

    public function localized(Request $request, string $locale): Response
    {
        return $this->index($request);
    }

    /**
     * Display the public SysNoxis News Feed and guest landing hero.
     */
    public function index(Request $request): Response
    {
        $tab = $this->sanitizeFeedTab($request->string('tab', 'trending')->toString(), $request->user() !== null);
        $perPage = $this->policyConfig->feedPageSize();
        $feed = $this->feedService->publicFeed($request->user(), $tab, null, $perPage);
        $sponsoredPost = $this->adDeliveryService->pickSponsoredPostForFeed($request->user(), $request);
        $banners = $this->bannerService->activeBannersForSlots(
            ['desktop_feed_top', 'mobile_after_composer', 'mobile_in_feed'],
            $request->user(),
            $request,
        );

        $locale = app()->getLocale();
        $prefix = $locale !== 'en' ? '/'.$locale : '';
        $seo = $this->seoMetadata->forHome($locale, $request->getPathInfo());
        $ssrPosts = array_map(static function (array $item): array {
            return [
                'title' => (string) ($item['title'] ?: Str::limit(strip_tags((string) ($item['content'] ?? '')), 80)),
                'excerpt' => Str::limit(strip_tags((string) ($item['content'] ?? '')), 200),
                'href' => '/posts/'.($item['id'] ?? ''),
                'author' => is_array($item['author'] ?? null) ? (string) ($item['author']['name'] ?? '') : '',
            ];
        }, array_slice($feed['data'], 0, 12));

        return Inertia::render('Welcome', [
            'posts' => $feed['data'],
            'sponsoredPost' => $sponsoredPost,
            'banners' => $banners,
            'adSense' => $this->adSenseService->configForPage('home', $request->user()),
            'nextCursor' => $feed['next_cursor'],
            'hasMore' => $feed['has_more'],
            'activeTab' => $tab,
            'categories' => PostCategory::options(),
            'feedConfig' => [
                'enable_trending' => $this->policyConfig->trendingFeedEnabled(),
                'enable_recommended' => $this->policyConfig->recommendedFeedEnabled(),
            ],
        ])->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'home',
                'heading' => (string) config('app.name', 'SysNoxis'),
                'description' => (string) ($seo['description'] ?? ''),
                'posts' => $ssrPosts,
                'links' => [
                    ['label' => 'Communities', 'href' => $prefix.'/communities'],
                    ['label' => 'Topics', 'href' => $prefix.'/topics'],
                    ['label' => 'SysNoxis Premium', 'href' => $prefix.'/premium'],
                ],
            ],
        ]);
    }

    /**
     * Load the next cursor page for Infinite Scroll News Feed.
     */
    public function more(Request $request): JsonResponse
    {
        $tab = $this->sanitizeFeedTab($request->string('tab', 'trending')->toString(), $request->user() !== null);
        $cursor = $request->string('cursor')->toString();
        $perPage = $this->policyConfig->feedPageSize();

        $feed = $this->feedService->publicFeed(
            $request->user(),
            $tab,
            $cursor !== '' ? $cursor : null,
            $perPage,
        );

        return response()->json($feed);
    }

    private function sanitizeFeedTab(string $rawTab, bool $isAuthenticated): string
    {
        $allowed = array_merge(
            ['trending', 'latest', 'recommended', 'following'],
            array_map(static fn (PostCategory $c): string => $c->value, PostCategory::cases()),
        );

        $tab = in_array($rawTab, $allowed, true) ? $rawTab : 'trending';

        if ($tab === 'following' && ! $isAuthenticated) {
            return 'trending';
        }

        if ($tab === 'recommended' && ! $this->policyConfig->recommendedFeedEnabled()) {
            return 'trending';
        }

        if ($tab === 'trending' && ! $this->policyConfig->trendingFeedEnabled()) {
            return 'latest';
        }

        return $tab;
    }
}
