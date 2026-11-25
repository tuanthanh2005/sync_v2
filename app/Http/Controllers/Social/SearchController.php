<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\SearchRequest;
use App\Services\Monetization\AdSenseConfigService;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Social\SearchService;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly BannerAdvertisingService $bannerService,
        private readonly AdSenseConfigService $adSenseService,
    ) {}

    public function __invoke(SearchRequest $request): Response
    {
        $query = $request->string('q')->toString();
        $hashtag = $request->string('hashtag')->toString();

        return Inertia::render('Search', [
            ...$this->searchService->search($query, $request->user(), $hashtag),
            'banners' => $this->bannerService->activeBannersForSlots(
                ['desktop_explore_top', 'mobile_explore'],
                $request->user(),
                $request,
            ),
            'adSense' => $this->adSenseService->configForPage('explore', $request->user()),
        ]);
    }
}
