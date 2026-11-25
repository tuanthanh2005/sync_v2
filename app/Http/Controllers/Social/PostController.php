<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\LinkPreviewRequest;
use App\Http\Requests\Social\PostStoreRequest;
use App\Http\Requests\Social\PostUpdateRequest;
use App\Models\Post;
use App\Services\Social\CommentService;
use App\Services\Social\FeedService;
use App\Services\Social\PostService;
use App\Services\Social\SafeLinkPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function __construct(
        private readonly PostService $postService,
        private readonly FeedService $feedService,
        private readonly CommentService $commentService,
        private readonly SafeLinkPreviewService $linkPreviewService,
    ) {}

    public function show(Request $request, Post $post): Response
    {
        Gate::authorize('view', $post);

        $post->load([
            'author:id,name,username,status',
            'author.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
            'community:id,name,slug',
            'hashtags:id,name,slug',
            'media',
            'poll.options',
        ]);

        $serializedPost = $this->feedService->serializeOne($post, $request->user());
        $title = $post->title ?: Str::limit(strip_tags($post->content), 60, '...');
        $description = Str::limit(strip_tags($post->content), 160, '...');

        return Inertia::render('posts/Show', [
            'post' => $serializedPost,
            'comments' => $this->commentService->forPost($post, $request->user()),
        ])->withViewData([
            'seo' => [
                'title' => $title.' - '.config('app.name'),
                'description' => $description,
                'url' => route('posts.show', $post),
            ],
        ]);
    }

    public function store(PostStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Post::class);

        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array<int, UploadedFile> $uploadedImages */
        $uploadedImages = $request->file('images', []);

        /** @var array{
         *     title?: string|null,
         *     content: string,
         *     community_id?: int|null,
         *     category?: string|null,
         *     visibility?: string|null,
         *     link_url?: string|null,
         *     poll_question?: string|null,
         *     poll_options?: list<string>|null,
         *     poll_duration_hours?: int|null
         * } $validated
         */
        $validated = $request->validated();

        $this->postService->create($user, $validated, array_values($uploadedImages));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your post has been published.'),
        ]);

        return back();
    }

    public function update(PostUpdateRequest $request, Post $post): RedirectResponse
    {
        Gate::authorize('update', $post);

        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array{title?: string|null, content: string, category?: string|null} $validated */
        $validated = $request->validated();

        $this->postService->update($user, $post, $validated);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Post updated.'),
        ]);

        return back();
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('delete', $post);

        $user = $request->user();
        abort_if($user === null, 403);

        $this->postService->delete($user, $post);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Post removed.'),
        ]);

        return to_route('home');
    }

    public function previewLink(LinkPreviewRequest $request): JsonResponse
    {
        $url = $request->string('url')->toString();

        if (! $this->linkPreviewService->isSafePublicUrl($url)) {
            return response()->json([
                'message' => __('The provided link URL is blocked or invalid.'),
            ], 422);
        }

        return response()->json([
            'preview' => $this->linkPreviewService->fetch($url),
        ]);
    }
}
