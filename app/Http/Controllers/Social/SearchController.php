<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\SearchRequest;
use App\Services\Monetization\AdSenseConfigService;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Seo\SeoMetadataService;
use App\Services\Social\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly BannerAdvertisingService $bannerService,
        private readonly AdSenseConfigService $adSenseService,
        private readonly SeoMetadataService $seoMetadata,
    ) {}

    public function __invoke(SearchRequest $request): Response
    {
        $query = $request->string('q')->toString();
        $hashtag = $request->string('hashtag')->toString();
        $type = $request->string('type', 'all')->toString();
        $page = max(1, $request->integer('page', 1));

        $payload = $this->searchService->search($query, $request->user(), $hashtag, $type, $page);

        $seoTitle = $query !== ''
            ? __('Search: :query', ['query' => $query]).' | '.config('app.name', 'SysNoxis')
            : __('Explore').' | '.config('app.name', 'SysNoxis');

        // Internal search results are strictly noindex per SEO Architecture V2.0.
        $seo = $this->seoMetadata->forStaticPage(
            '/search',
            $seoTitle,
            __('Explore discussions, communities, topics, and members on SysNoxis.'),
            indexable: false,
            locale: app()->getLocale(),
        );

        return Inertia::render('Search', [
            ...$payload,
            'banners' => $this->bannerService->activeBannersForSlots(
                ['desktop_explore_top', 'mobile_explore'],
                $request->user(),
                $request,
            ),
            'adSense' => $this->adSenseService->configForPage('explore', $request->user()),
        ])->withViewData([
            'seo' => $seo,
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        return response()->json(
            $this->searchService->suggestions($query, $request->user()),
        );
    }
}
