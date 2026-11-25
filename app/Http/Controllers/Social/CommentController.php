<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\CommentStoreRequest;
use App\Models\Comment;
use App\Models\Post;
use App\Services\Social\CommentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class CommentController extends Controller
{
    public function __construct(
        private readonly CommentService $commentService,
    ) {}

    public function store(CommentStoreRequest $request, Post $post): RedirectResponse
    {
        Gate::authorize('create', [Comment::class, $post]);

        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array{content: string, parent_id?: int|null} $validated */
        $validated = $request->validated();

        $this->commentService->create($user, $post, $validated);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Comment posted.'),
        ]);

        return back();
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $this->commentService->delete($comment);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Comment deleted.'),
        ]);

        return back();
    }
}
