<?php

namespace App\Http\Controllers\Social;

use App\Enums\ReactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Social\PostPollVoteRequest;
use App\Http\Requests\Social\PostReactionRequest;
use App\Models\Post;
use App\Services\Social\PostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PostInteractionController extends Controller
{
    public function __construct(
        private readonly PostService $postService,
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
}
