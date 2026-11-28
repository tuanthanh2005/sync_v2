<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedKeyword;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostPoll;
use App\Models\User;
use App\Services\Admin\AdminContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentModerationController extends Controller
{
    public function __construct(
        private readonly AdminContentService $contentService,
    ) {}

    public function posts(Request $request): Response
    {
        return Inertia::render('admin/Content/Posts', $this->contentService->postsPayload([
            'filter' => (string) $request->input('filter', 'all'),
            'search' => (string) $request->input('search', ''),
            'community_id' => $request->integer('community_id') ?: null,
        ]));
    }

    public function moderatePost(Request $request, int $postId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var Post $post */
        $post = Post::query()->withTrashed()->findOrFail($postId);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:hide,restore_visibility,approve,reject,lock_comments,unlock_comments,pin,unpin,content_warning,mark_spam,soft_delete,restore_deleted'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'content_warning' => ['nullable', 'string', 'max:160'],
        ]);

        $this->contentService->moderatePost(
            $actor,
            $post,
            (string) $validated['action'],
            (string) $validated['reason'],
            isset($validated['content_warning']) ? (string) $validated['content_warning'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content moderation action applied.')]);

        return back();
    }

    public function comments(Request $request): Response
    {
        return Inertia::render('admin/Content/Comments', $this->contentService->commentsPayload([
            'filter' => (string) $request->input('filter', 'all'),
            'search' => (string) $request->input('search', ''),
        ]));
    }

    public function moderateComment(Request $request, int $commentId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var Comment $comment */
        $comment = Comment::query()->withTrashed()->findOrFail($commentId);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:hide,approve,restore_visibility,mark_spam,soft_delete,restore_deleted'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->contentService->moderateComment($actor, $comment, (string) $validated['action'], (string) $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content moderation action applied.')]);

        return back();
    }

    public function bulkModerateComments(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer'],
            'action' => ['required', 'string', 'in:hide,approve,mark_spam,soft_delete'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', $validated['ids']));

        $this->contentService->bulkModerateComments($actor, $ids, (string) $validated['action'], (string) $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content moderation action applied.')]);

        return back();
    }

    public function communities(Request $request): Response
    {
        return Inertia::render('admin/Content/Communities', $this->contentService->communitiesPayload([
            'search' => (string) $request->input('search', ''),
            'status' => (string) $request->input('status', 'all'),
        ]));
    }

    public function moderateCommunity(Request $request, int $communityId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var Community $community */
        $community = Community::query()->withTrashed()->findOrFail($communityId);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:toggle_featured,toggle_verified,toggle_new_members,toggle_posting,suspend,hide,activate,soft_delete,restore,transfer_owner'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'new_owner_username' => ['nullable', 'string', 'max:60'],
        ]);

        $this->contentService->moderateCommunity(
            $actor,
            $community,
            (string) $validated['action'],
            (string) $validated['reason'],
            isset($validated['new_owner_username']) ? (string) $validated['new_owner_username'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content moderation action applied.')]);

        return back();
    }

    public function polls(Request $request): Response
    {
        return Inertia::render('admin/Content/Polls', $this->contentService->pollsPayload((string) $request->input('search', '')));
    }

    public function moderatePoll(Request $request, PostPoll $poll): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:close,reopen,hide,restore,delete'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->contentService->moderatePoll($actor, $poll, (string) $validated['action'], (string) $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content moderation action applied.')]);

        return back();
    }

    public function hashtags(Request $request): Response
    {
        return Inertia::render('admin/Content/Hashtags', $this->contentService->hashtagsPayload((string) $request->input('search', '')));
    }

    public function updateHashtag(Request $request, Hashtag $hashtag): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'is_featured' => ['sometimes', 'boolean'],
            'is_restricted' => ['sometimes', 'boolean'],
            'is_trending_blocked' => ['sometimes', 'boolean'],
            'is_spam' => ['sometimes', 'boolean'],
        ]);

        $this->contentService->updateHashtag($actor, $hashtag, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content moderation action applied.')]);

        return back();
    }

    public function media(Request $request): Response
    {
        return Inertia::render('admin/Content/Media', $this->contentService->mediaPayload((string) $request->input('status', 'all')));
    }

    public function moderateMedia(Request $request, PostMedia $media): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:approve,hide,delete'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->contentService->moderateMedia($actor, $media, (string) $validated['action'], (string) $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content moderation action applied.')]);

        return back();
    }

    public function keywords(): Response
    {
        return Inertia::render('admin/Content/Keywords', $this->contentService->keywordsPayload());
    }

    public function storeKeyword(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'keyword' => ['required', 'string', 'min:2', 'max:120', 'unique:blocked_keywords,keyword'],
            'match_type' => ['required', 'string', 'in:contains,exact,domain'],
            'severity' => ['required', 'string', 'in:block,flag,mask'],
        ]);

        $this->contentService->createKeyword($actor, [
            'keyword' => (string) $validated['keyword'],
            'match_type' => (string) $validated['match_type'],
            'severity' => (string) $validated['severity'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Blocked keyword saved.')]);

        return back();
    }

    public function destroyKeyword(Request $request, BlockedKeyword $blockedKeyword): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->contentService->deleteKeyword($actor, $blockedKeyword);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Blocked keyword deleted.')]);

        return back();
    }
}
