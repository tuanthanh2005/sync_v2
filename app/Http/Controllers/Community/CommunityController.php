<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Http\Requests\Community\CommunityStoreRequest;
use App\Http\Requests\Community\CommunityUpdateRequest;
use App\Models\Community;
use App\Models\Post;
use App\Services\Community\CommunityService;
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
    ) {}

    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString();

        return Inertia::render(
            'communities/Index',
            $this->communityService->directoryPayload($request->user(), $filter, $search),
        );
    }

    public function show(Request $request, Community $community): Response
    {
        Gate::authorize('view', $community);

        $tab = $request->string('tab', 'posts')->toString();
        $category = $request->string('category', 'all')->toString();
        $sort = $request->string('sort', 'latest')->toString();

        return Inertia::render(
            'communities/Show',
            $this->communityService->showPayload($community, $request->user(), $tab, $category, $sort),
        )->withViewData([
            'seo' => [
                'title' => $community->name.' - '.config('app.name'),
                'description' => Str::limit((string) $community->description, 160),
                'url' => route('communities.show', $community),
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
