<?php

namespace App\Http\Controllers;

use App\Enums\PostCategory;
use App\Services\Ads\AdDeliveryService;
use App\Services\Monetization\AdSenseConfigService;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Seo\SeoMetadataService;
use App\Services\Social\FeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly AdDeliveryService $adDeliveryService,
        private readonly BannerAdvertisingService $bannerService,
        private readonly AdSenseConfigService $adSenseService,
        private readonly SeoMetadataService $seoMetadata,
    ) {}

    /**
     * Display the public SyncViet News Feed and guest landing hero.
     */
    public function index(Request $request): Response
    {
        $tab = $request->string('tab', 'trending')->toString();
        $feed = $this->feedService->publicFeed($request->user(), $tab);
        $sponsoredPost = $this->adDeliveryService->pickSponsoredPostForFeed($request->user(), $request);
        $banners = $this->bannerService->activeBannersForSlots(
            ['desktop_feed_top', 'mobile_after_composer', 'mobile_in_feed'],
            $request->user(),
            $request,
        );

        $seo = $this->seoMetadata->forHome(app()->getLocale());
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
        ])->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'home',
                'heading' => (string) config('app.name', 'SyncViet'),
                'description' => (string) ($seo['description'] ?? ''),
                'posts' => $ssrPosts,
                'links' => [
                    ['label' => 'Communities', 'href' => '/communities'],
                    ['label' => 'Topics', 'href' => '/topics'],
                    ['label' => 'SyncViet Premium', 'href' => '/premium'],
                ],
            ],
        ]);
    }

    /**
     * Load the next cursor page for Infinite Scroll News Feed.
     */
    public function more(Request $request): JsonResponse
    {
        $tab = $request->string('tab', 'trending')->toString();
        $cursor = $request->string('cursor')->toString();

        $feed = $this->feedService->publicFeed(
            $request->user(),
            $tab,
            $cursor !== '' ? $cursor : null,
        );

        return response()->json($feed);
    }
}
