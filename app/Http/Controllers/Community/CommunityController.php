<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Http\Requests\Community\CommunityStoreRequest;
use App\Http\Requests\Community\CommunityUpdateRequest;
use App\Models\Community;
use App\Models\Post;
use App\Services\Community\CommunityService;
use App\Services\Monetization\AdSenseConfigService;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Seo\OrganicGrowthService;
use App\Services\Seo\SeoMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CommunityController extends Controller
{
    public function __construct(
        private readonly CommunityService $communityService,
        private readonly BannerAdvertisingService $bannerService,
        private readonly AdSenseConfigService $adSenseService,
        private readonly SeoMetadataService $seoMetadata,
        private readonly OrganicGrowthService $organicGrowth,
    ) {}

    public function indexLocalized(Request $request, string $locale): Response
    {
        return $this->index($request, $locale);
    }

    public function index(Request $request, ?string $locale = null): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString();
        $activeLocale = $locale ?: app()->getLocale();
        $payload = $this->communityService->directoryPayload($request->user(), $filter, $search);

        $staticPath = $locale !== null && $locale !== 'en' ? '/'.$locale.'/communities' : '/communities';
        $seo = $this->seoMetadata->forStaticPage(
            $staticPath,
            __('Communities | :site', ['site' => config('app.name', 'SysNoxis')], $activeLocale),
            __('Discover active global communities and topic hubs on :site.', ['site' => config('app.name', 'SysNoxis')], $activeLocale),
            $search === '',
            $activeLocale,
        );

        $ssrLinks = array_map(
            static fn (array $c): array => ['label' => (string) ($c['name'] ?? ''), 'href' => '/communities/'.($c['slug'] ?? '')],
            array_slice($payload['communities'], 0, 20),
        );

        return Inertia::render('communities/Index', $payload)->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'communities_index',
                'heading' => 'Communities',
                'description' => (string) ($seo['description'] ?? ''),
                'links' => $ssrLinks,
            ],
        ]);
    }

    public function showLocalized(Request $request, string $locale, Community $community): Response
    {
        return $this->show($request, $community, $locale);
    }

    public function show(Request $request, Community $community, ?string $routeLocale = null): Response
    {
        Gate::authorize('view', $community);

        $tab = $request->string('tab', 'posts')->toString();
        $category = $request->string('category', 'all')->toString();
        $sort = $request->string('sort', 'latest')->toString();
        $showPayload = $this->communityService->showPayload($community, $request->user(), $tab, $category, $sort);
        $seo = $this->seoMetadata->forCommunity($community, $routeLocale ?: app()->getLocale(), $routeLocale !== null);

        $ssrPosts = array_map(static fn (array $p): array => [
            'title' => (string) ($p['title'] ?: Str::limit(strip_tags((string) ($p['content'] ?? '')), 80)),
            'excerpt' => Str::limit(strip_tags((string) ($p['content'] ?? '')), 180),
            'href' => '/posts/'.($p['id'] ?? ''),
        ], array_slice(is_array($showPayload['posts'] ?? null) ? $showPayload['posts'] : [], 0, 12));

        return Inertia::render(
            'communities/Show',
            [
                ...$showPayload,
                'banners' => $this->bannerService->activeBannersForSlots(
                    ['desktop_community_top', 'mobile_community'],
                    $request->user(),
                    $request,
                ),
                'adSense' => $this->adSenseService->configForPage('community', $request->user()),
            ],
        )->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'community',
                'heading' => $community->name,
                'description' => (string) ($community->description ?? ''),
                'posts' => $ssrPosts,
                'breadcrumbs' => [
                    ['label' => (string) config('app.name', 'SysNoxis'), 'href' => '/'],
                    ['label' => 'Communities', 'href' => '/communities'],
                ],
            ],
        ]);
    }

    public function store(CommunityStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Community::class);

        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array{name: string, category: string, description?: string|null, visibility?: string|null, rules?: list<string>|null} $validated */
        $validated = $request->validated();

        $community = $this->communityService->create(
            $user,
            $validated,
            $request->file('avatar'),
            $request->file('cover'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Community created.'),
        ]);

        return to_route('communities.show', $community);
    }

    public function update(CommunityUpdateRequest $request, Community $community): RedirectResponse
    {
        Gate::authorize('update', $community);

        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array{name: string, category: string, description?: string|null, rules?: list<string>|null} $validated */
        $validated = $request->validated();

        $this->communityService->update(
            $user,
            $community,
            $validated,
            $request->file('avatar'),
            $request->file('cover'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Community updated.'),
        ]);

        return back();
    }

    public function toggleMembership(Request $request, Community $community): RedirectResponse
    {
        Gate::authorize('view', $community);

        $user = $request->user();
        abort_if($user === null, 403);

        $result = $this->communityService->toggleMembership($user, $community);

        if ($result['joined']) {
            $this->organicGrowth->recordEvent('join_community', $request, $user, 'community', $community->id);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['joined']
                ? __('Joined :community.', ['community' => $community->name])
                : __('Left :community.', ['community' => $community->name]),
        ]);

        return back();
    }

    public function togglePin(Request $request, Community $community, Post $post): RedirectResponse
    {
        Gate::authorize('moderate', $community);

        $user = $request->user();
        abort_if($user === null, 403);

        $pinned = $this->communityService->togglePinPost($user, $community, $post);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $pinned ? __('Post pinned in community.') : __('Post unpinned.'),
        ]);

        return back();
    }
}
