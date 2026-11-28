<?php

namespace App\Http\Controllers\Social;

use App\Enums\ReactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Social\PostPollVoteRequest;
use App\Http\Requests\Social\PostReactionRequest;
use App\Models\Post;
use App\Models\UserHiddenPost;
use App\Services\Social\PostService;
use App\Services\Social\PostViewTrackingService;
use App\Services\Social\SocialDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PostInteractionController extends Controller
{
    public function __construct(
        private readonly PostService $postService,
        private readonly PostViewTrackingService $viewTrackingService,
        private readonly SocialDirectoryService $directoryService,
    ) {}

    public function reactions(Request $request, Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:all,like,love,insightful'],
            'search' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:30'],
        ]);

        $rawType = isset($validated['type']) ? (string) $validated['type'] : null;
        $filterType = ($rawType !== null && $rawType !== 'all') ? $rawType : null;

        $payload = $this->directoryService->reactionUsers(
            post: $post,
            viewer: $request->user(),
            type: $filterType,
            search: isset($validated['search']) ? (string) $validated['search'] : null,
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? SocialDirectoryService::DEFAULT_PER_PAGE),
        );

        return response()->json($payload);
    }

    public function react(PostReactionRequest $request, Post $post): RedirectResponse
    {
        Gate::authorize('view', $post);

        $user = $request->user();
        abort_if($user === null, 403);

        $type = ReactionType::from($request->string('type')->toString());
        $this->postService->toggleReaction($user, $post, $type);

        return back();
    }

    public function bookmark(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('view', $post);

        $user = $request->user();
        abort_if($user === null, 403);

        $result = $this->postService->toggleBookmark($user, $post);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['bookmarked']
                ? __('Saved to your bookmarks.')
                : __('Removed from your bookmarks.'),
        ]);

        return back();
    }

    public function votePoll(PostPollVoteRequest $request, Post $post): RedirectResponse
    {
        Gate::authorize('view', $post);

        $user = $request->user();
        abort_if($user === null, 403);

        $poll = $post->poll;
        abort_if($poll === null, 404);

        $this->postService->votePoll($user, $poll, (int) $request->validated('option_id'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your vote has been recorded.'),
        ]);

        return back();
    }

    public function share(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        Gate::authorize('view', $post);

        $user = $request->user();
        $secret = (string) config('app.key', 'syncviet');
        $actorKey = $user !== null
            ? 'user:'.$user->id
            : 'guest:'.($request->session()->getId() ?: (string) $request->ip()).'|'.mb_substr((string) $request->userAgent(), 0, 120);

        $actorHash = hash_hmac('sha256', $actorKey, $secret);
        $channel = $request->string('channel', 'copy_link')->toString();

        $sharesCount = $this->postService->incrementShare($post, $user, $actorHash, $channel);

        if ($request->expectsJson()) {
            return response()->json([
                'shares_count' => $sharesCount,
            ]);
        }

        return back();
    }

    public function hideFromFeed(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        UserHiddenPost::query()->firstOrCreate(
            ['user_id' => $user->id, 'post_id' => $post->id],
            ['created_at' => now()],
        );

        if ($request->expectsJson()) {
            return response()->json(['hidden' => true]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Post hidden from your feed.'),
        ]);

        return back();
    }

    public function trackViews(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'views' => ['required', 'array', 'min:1', 'max:25'],
            'views.*.post_id' => ['required', 'integer', 'min:1'],
            'views.*.source' => ['nullable', 'string', 'in:feed,detail,profile,community,search,topic'],
            'views.*.is_promoted' => ['nullable', 'boolean'],
            'views.*.visible_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'views.*.visible_ratio' => ['nullable', 'integer', 'min:0', 'max:100'],
            'views.*.is_tall_post_probe' => ['nullable', 'boolean'],
            'views.*.is_tab_visible' => ['nullable', 'boolean'],
        ]);

        /** @var list<array{post_id: int, source?: string, is_promoted?: bool, visible_ms?: int|null, visible_ratio?: int|null, is_tall_post_probe?: bool|null, is_tab_visible?: bool|null}> $views */
        $views = array_values($validated['views']);

        $result = $this->viewTrackingService->recordBatch(
            $request,
            $request->user(),
            $views,
        );

        return response()->json($result);
    }
}
