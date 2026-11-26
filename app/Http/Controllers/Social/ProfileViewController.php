<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\ProfileDetailsUpdateRequest;
use App\Models\User;
use App\Services\Seo\SeoMetadataService;
use App\Services\Social\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileViewController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly SeoMetadataService $seoMetadata,
    ) {}

    public function me(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return to_route('users.show', ['user' => $user->username]);
    }

    public function show(Request $request, User $user): Response
    {
        $viewer = $request->user();

        if (! $user->isActive() && ! ($viewer !== null && $viewer->isStaff())) {
            abort(404);
        }

        $tab = $request->string('tab', 'posts')->toString();
        $payload = $this->profileService->profilePayload($user, $viewer, $tab);
        $seo = $this->seoMetadata->forProfile($user, app()->getLocale());

        $ssrPosts = array_map(static fn (array $p): array => [
            'title' => (string) ($p['title'] ?: Str::limit(strip_tags((string) ($p['content'] ?? '')), 80)),
            'excerpt' => Str::limit(strip_tags((string) ($p['content'] ?? '')), 180),
            'href' => '/posts/'.($p['id'] ?? ''),
        ], array_slice($payload['posts'], 0, 10));

        return Inertia::render('users/Show', $payload)->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'profile',
                'heading' => sprintf('%s (@%s)', $user->name, $user->username),
                'description' => (string) ($payload['profile_user']['bio'] ?? $payload['profile_user']['headline'] ?? ''),
                'posts' => $ssrPosts,
            ],
        ]);
    }

    public function updateDetails(ProfileDetailsUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array{headline?: string|null, bio?: string|null, location?: string|null, website_url?: string|null} $validated */
        $validated = $request->validated();

        $this->profileService->updateDetails(
            $user,
            $validated,
            $request->file('avatar'),
            $request->file('cover'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Public profile updated.'),
        ]);

        return back();
    }

    public function toggleFollow(Request $request, User $user): RedirectResponse
    {
        $viewer = $request->user();
        abort_if($viewer === null, 403);

        $result = $this->profileService->toggleFollow($viewer, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['following']
                ? __('You are now following @:username.', ['username' => $user->username])
                : __('Unfollowed @:username.', ['username' => $user->username]),
        ]);

        return back();
    }

    public function toggleBlock(Request $request, User $user): RedirectResponse
    {
        $viewer = $request->user();
        abort_if($viewer === null, 403);

        $result = $this->profileService->toggleBlock($viewer, $user);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => $result['blocked']
                ? __('Blocked @:username.', ['username' => $user->username])
                : __('Unblocked @:username.', ['username' => $user->username]),
        ]);

        return back();
    }
}
