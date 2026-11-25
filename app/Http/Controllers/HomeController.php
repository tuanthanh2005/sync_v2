<?php

namespace App\Http\Controllers;

use App\Enums\PostCategory;
use App\Services\Ads\AdDeliveryService;
use App\Services\Social\FeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly AdDeliveryService $adDeliveryService,
    ) {}

    /**
     * Display the public SyncViet News Feed and guest landing hero.
     */
    public function index(Request $request): Response
    {
        $tab = $request->string('tab', 'trending')->toString();
        $feed = $this->feedService->publicFeed($request->user(), $tab);
        $sponsoredPost = $this->adDeliveryService->pickSponsoredPostForFeed($request->user(), $request);

        return Inertia::render('Welcome', [
            'posts' => $feed['data'],
            'sponsoredPost' => $sponsoredPost,
            'nextCursor' => $feed['next_cursor'],
            'hasMore' => $feed['has_more'],
            'activeTab' => $tab,
            'categories' => PostCategory::options(),
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
