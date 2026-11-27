<?php

namespace App\Http\Controllers\Social;

use App\Enums\ReactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Social\PostPollVoteRequest;
use App\Http\Requests\Social\PostReactionRequest;
use App\Models\Post;
use App\Services\Social\PostService;
use App\Services\Social\PostViewTrackingService;
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
    ) {}

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

    public function share(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('view', $post);

        $this->postService->incrementShare($post);

        return back();
    }

    public function trackViews(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'views' => ['required', 'array', 'min:1', 'max:25'],
            'views.*.post_id' => ['required', 'integer', 'min:1'],
            'views.*.source' => ['nullable', 'string', 'in:feed,detail,profile,community,search,topic'],
            'views.*.is_promoted' => ['nullable', 'boolean'],
        ]);

        /** @var list<array{post_id: int, source?: string, is_promoted?: bool}> $views */
        $views = array_values($validated['views']);

        $result = $this->viewTrackingService->recordBatch(
            $request,
            $request->user(),
            $views,
        );

        return response()->json($result);
    }
}
