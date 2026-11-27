<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\ProfileDetailsUpdateRequest;
use App\Models\User;
use App\Services\Seo\SeoMetadataService;
use App\Services\Social\ProfileService;
use App\Services\Social\SocialDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileViewController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly SocialDirectoryService $directoryService,
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

        if ((! $user->isActive() || ! $user->hasVerifiedEmail()) && ! ($viewer !== null && $viewer->isStaff())) {
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

        /** @var array{headline?: string|null, bio?: string|null, location?: string|null, website_url?: string|null, remove_avatar?: bool, remove_cover?: bool} $validated */
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

    public function followers(Request $request, User $user): JsonResponse
    {
        return $this->renderFollowList($request, $user, 'followers');
    }

    public function following(Request $request, User $user): JsonResponse
    {
        return $this->renderFollowList($request, $user, 'following');
    }

    public function toggleFollow(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $viewer = $request->user();
        abort_if($viewer === null, 403);

        $result = $this->profileService->toggleFollow($viewer, $user);

        $message = $result['following']
            ? __('You are now following @:username.', ['username' => $user->username])
            : __('Unfollowed @:username.', ['username' => $user->username]);

        if ($request->expectsJson()) {
            return response()->json([
                'following' => $result['following'],
                'followers_count' => $result['followers_count'],
                'viewer_following_count' => $result['viewer_following_count'],
                'target_user_id' => $user->id,
                'message' => $message,
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return back();
    }

    private function renderFollowList(Request $request, User $user, string $tab): JsonResponse
    {
        $viewer = $request->user();

        if ((! $user->isActive() || ! $user->hasVerifiedEmail()) && ! ($viewer !== null && $viewer->isStaff())) {
            abort(404);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:30'],
        ]);

        $payload = $this->directoryService->followList(
            targetUser: $user,
            tab: $tab,
            viewer: $viewer,
            search: isset($validated['search']) ? (string) $validated['search'] : null,
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? SocialDirectoryService::DEFAULT_PER_PAGE),
        );

        return response()->json($payload);
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
