<?php

namespace App\Services\Social;

use App\Enums\ModerationStatus;
use App\Models\Comment;
use App\Models\CommunityMember;
use App\Models\Post;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommentService
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Load threaded top-level comments and replies for a post excluding blocked users.
     *
     * @return list<array<string, mixed>>
     */
    public function forPost(Post $post, ?User $viewer): array
    {
        $blockedIds = $viewer !== null ? $this->feedService->mutualBlockedUserIds($viewer) : [];

        $commentsQuery = Comment::query()
            ->where('post_id', $post->id)
            ->whereNull('parent_id')
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->with([
                'author:id,name,username',
                'author.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
                'replies' => function ($replyQuery) use ($blockedIds): void {
                    $replyQuery
                        ->where('moderation_status', ModerationStatus::Approved->value)
                        ->with([
                            'author:id,name,username',
                            'author.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
                        ]);

                    if ($blockedIds !== []) {
                        $replyQuery->whereNotIn('user_id', $blockedIds);
                    }
                },
            ])
            ->orderBy('id');

        if ($blockedIds !== []) {
            $commentsQuery->whereNotIn('user_id', $blockedIds);
        }

        return array_values(
            $commentsQuery->get()->map(function (Comment $comment) use ($post, $viewer): array {
                return [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'created_at' => $comment->created_at?->toIso8601String(),
                    'can_delete' => $viewer !== null && $viewer->isActive() && (
                        $viewer->id === $comment->user_id
                        || $viewer->id === $post->user_id
                        || $viewer->isStaff()
                    ),
                    'author' => $comment->author === null ? null : [
                        'id' => $comment->author->id,
                        'name' => $comment->author->name,
                        'username' => $comment->author->username,
                        'headline' => $comment->author->profile?->headline,
                        'avatar' => $comment->author->profile?->avatarUrl(),
                        'is_verified' => $comment->author->profile !== null && $comment->author->profile->is_verified_badge,
                    ],
                    'replies' => array_values(
                        $comment->replies->map(static function (Comment $reply) use ($post, $viewer): array {
                            return [
                                'id' => $reply->id,
                                'parent_id' => $reply->parent_id,
                                'content' => $reply->content,
                                'created_at' => $reply->created_at?->toIso8601String(),
                                'can_delete' => $viewer !== null && $viewer->isActive() && (
                                    $viewer->id === $reply->user_id
                                    || $viewer->id === $post->user_id
                                    || $viewer->isStaff()
                                ),
                                'author' => $reply->author === null ? null : [
                                    'id' => $reply->author->id,
                                    'name' => $reply->author->name,
                                    'username' => $reply->author->username,
                                    'headline' => $reply->author->profile?->headline,
                                    'avatar' => $reply->author->profile?->avatarUrl(),
                                    'is_verified' => $reply->author->profile !== null && $reply->author->profile->is_verified_badge,
                                ],
                            ];
                        })->all(),
                    ),
                ];
            })->all(),
        );
    }

    /**
     * @param  array{content: string, parent_id?: int|null}  $data
     */
    public function create(User $author, Post $post, array $data): Comment
    {
        $created = DB::transaction(function () use ($author, $post, $data): Comment {
            /** @var Post $lockedPost */
            $lockedPost = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

            if ($parentId !== null && $parentId > 0) {
                $parent = Comment::query()
                    ->where('id', $parentId)
                    ->where('post_id', $lockedPost->id)
                    ->where('moderation_status', ModerationStatus::Approved->value)
                    ->first();

                if ($parent === null) {
                    throw ValidationException::withMessages([
                        'parent_id' => [__('The parent comment does not belong to this post.')],
                    ]);
                }

                // Flatten replies to max depth 2.
                $parentId = $parent->parent_id ?? $parent->id;
            } else {
                $parentId = null;
            }

            $comment = new Comment([
                'parent_id' => $parentId,
                'content' => trim($data['content']),
            ]);
            $comment->post_id = $lockedPost->id;
            $comment->user_id = $author->id;
            $comment->moderation_status = ModerationStatus::Approved;
            $comment->save();

            $lockedPost->comments_count = Comment::query()
                ->where('post_id', $lockedPost->id)
                ->where('moderation_status', ModerationStatus::Approved->value)
                ->count();
            $lockedPost->save();

            if ($lockedPost->community_id !== null) {
                CommunityMember::query()
                    ->where('community_id', $lockedPost->community_id)
                    ->where('user_id', $author->id)
                    ->increment('points', 2);
            }

            return $comment;
        });

        if ($post->author !== null) {
            $this->notificationService->notifyIfAllowed(
                $post->author,
                $author,
                new SocialActivityNotification(
                    eventKey: 'post.commented',
                    titleVi: "{$author->name} đã bình luận về bài viết của bạn.",
                    titleEn: "{$author->name} commented on your post.",
                    url: route('posts.show', $post, false),
                    actorName: $author->name,
                    actorUsername: $author->username,
                ),
            );
        }

        return $created;
    }

    public function delete(Comment $comment): void
    {
        DB::transaction(function () use ($comment): void {
            /** @var Post|null $lockedPost */
            $lockedPost = Post::query()->whereKey($comment->post_id)->lockForUpdate()->first();

            $comment->delete();

            if ($lockedPost !== null) {
                $lockedPost->comments_count = Comment::query()
                    ->where('post_id', $lockedPost->id)
                    ->where('moderation_status', ModerationStatus::Approved->value)
                    ->count();
                $lockedPost->save();
            }
        });
    }
}
