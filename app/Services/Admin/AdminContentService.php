<?php

namespace App\Services\Admin;

use App\Enums\AccountType;
use App\Enums\CommunityRole;
use App\Enums\ModerationStatus;
use App\Enums\PostLifecycleStatus;
use App\Enums\UserStatus;
use App\Models\BlockedKeyword;
use App\Models\Comment;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostPoll;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Media\SafeImageUploadService;
use App\Services\Moderation\ContentFilterService;
use App\Services\Notification\NotificationService;
use App\Services\Security\AuditLogger;
use App\Services\Social\HashtagService;
use App\Services\Social\PostService;
use App\Services\Social\TrendingRankingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminContentService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PostService $postService,
        private readonly HashtagService $hashtagService,
        private readonly SafeImageUploadService $imageUploadService,
        private readonly ContentFilterService $contentFilter,
    ) {}

    /**
     * @param  array{filter?: string, search?: string, community_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function postsPayload(array $filters): array
    {
        $filter = (string) ($filters['filter'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));
        $communityId = isset($filters['community_id']) && (int) $filters['community_id'] > 0 ? (int) $filters['community_id'] : null;

        $query = Post::query()
            ->withTrashed()
            ->with(['author:id,name,username', 'community:id,name,slug', 'media', 'editHistories.editor:id,username'])
            ->withCount(['adCampaigns']);

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(static function (Builder $q) use ($pattern): void {
                $q->where('title', 'like', $pattern)
                    ->orWhere('content', 'like', $pattern)
                    ->orWhereHas('author', static fn (Builder $aq) => $aq->where('username', 'like', $pattern)->orWhere('name', 'like', $pattern));
            });
        }

        if ($communityId !== null) {
            $query->where('community_id', $communityId);
        }

        match ($filter) {
            'hidden' => $query->where('moderation_status', ModerationStatus::Hidden->value),
            'pending' => $query->where('moderation_status', ModerationStatus::Pending->value),
            'rejected' => $query->where(static function (Builder $rq): void {
                $rq->where('moderation_status', ModerationStatus::Rejected->value)
                    ->orWhere('lifecycle_status', PostLifecycleStatus::Rejected->value);
            }),
            'spam' => $query->where('is_spam', true),
            'sponsored' => $query->whereHas('adCampaigns'),
            'deleted' => $query->onlyTrashed(),
            default => null,
        };

        $paginator = $query->latest('id')->paginate(20)->withQueryString();

        return [
            'posts' => [
                'data' => array_values(collect($paginator->items())->map(static fn (Post $p): array => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'content' => $p->content,
                    'excerpt' => Str::limit($p->content, 160),
                    'category' => $p->category->value,
                    'visibility' => $p->visibility->value,
                    'moderation_status' => $p->moderation_status->value,
                    'lifecycle_status' => $p->lifecycle_status->value,
                    'moderation_reason' => $p->moderation_reason,
                    'spam_risk_score' => (int) ($p->spam_risk_score ?? 0),
                    'is_pinned' => $p->is_pinned,
                    'comments_locked' => $p->comments_locked,
                    'content_warning' => $p->content_warning,
                    'is_spam' => $p->is_spam,
                    'reactions_count' => $p->reactions_count,
                    'comments_count' => $p->comments_count,
                    'shares_count' => $p->shares_count,
                    'ad_campaigns_count' => (int) ($p->getAttribute('ad_campaigns_count') ?? 0),
                    'author' => $p->author === null ? null : [
                        'id' => $p->author->id,
                        'name' => $p->author->name,
                        'username' => $p->author->username,
                    ],
                    'community' => $p->community === null ? null : [
                        'id' => $p->community->id,
                        'name' => $p->community->name,
                        'slug' => $p->community->slug,
                    ],
                    'media' => $p->media->map(static fn (PostMedia $m): array => [
                        'id' => $m->id,
                        'url' => $m->url(),
                    ])->values()->all(),
                    'edit_histories' => $p->editHistories->map(static fn ($h): array => [
                        'id' => $h->id,
                        'previous_title' => $h->previous_title,
                        'previous_content' => $h->previous_content,
                        'editor_username' => $h->editor?->username,
                        'created_at' => $h->created_at->toIso8601String(),
                    ])->values()->all(),
                    'created_at' => $p->created_at?->toIso8601String(),
                    'deleted_at' => $p->deleted_at?->toIso8601String(),
                ])->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'communities' => Community::query()->orderBy('name')->limit(100)->get(['id', 'name'])->all(),
            'filters' => ['filter' => $filter, 'search' => $search, 'community_id' => $communityId],
        ];
    }

    public function moderatePost(User $actor, Post $post, string $action, string $reason, ?string $contentWarning = null): void
    {
        $cleanReason = trim($reason);

        DB::transaction(function () use ($actor, $post, $action, $cleanReason, $contentWarning): void {
            match ($action) {
                'hide' => (function () use ($actor, $post, $cleanReason): void {
                    $post->forceFill([
                        'moderation_status' => ModerationStatus::Hidden,
                        'lifecycle_status' => PostLifecycleStatus::Hidden,
                        'moderation_reason' => $cleanReason,
                        'moderated_by' => $actor->id,
                        'moderated_at' => now(),
                        'trending_score' => 0,
                        'trending_raw_score' => 0,
                        'needs_rerank' => false,
                    ])->save();
                    $this->postService->stopCampaignsForPost($post, __('Promoted post was hidden by moderation; remaining ad budget refunded.'));
                })(),
                'reject' => (function () use ($actor, $post, $cleanReason): void {
                    $post->forceFill([
                        'moderation_status' => ModerationStatus::Rejected,
                        'lifecycle_status' => PostLifecycleStatus::Rejected,
                        'moderation_reason' => $cleanReason,
                        'moderated_by' => $actor->id,
                        'moderated_at' => now(),
                        'trending_score' => 0,
                        'trending_raw_score' => 0,
                        'needs_rerank' => false,
                    ])->save();
                    $this->postService->stopCampaignsForPost($post, __('Promoted post was rejected by moderation; remaining ad budget refunded.'));
                })(),
                'restore_visibility', 'approve' => (function () use ($actor, $post): void {
                    $post->forceFill([
                        'moderation_status' => ModerationStatus::Approved,
                        'lifecycle_status' => PostLifecycleStatus::Published,
                        'moderation_reason' => null,
                        'is_spam' => false,
                        'moderated_by' => $actor->id,
                        'moderated_at' => now(),
                        'needs_rerank' => true,
                    ])->save();
                })(),
                'lock_comments' => $post->forceFill(['comments_locked' => true])->save(),
                'unlock_comments' => $post->forceFill(['comments_locked' => false])->save(),
                'pin' => $post->forceFill(['is_pinned' => true])->save(),
                'unpin' => $post->forceFill(['is_pinned' => false])->save(),
                'content_warning' => $post->forceFill(['content_warning' => $contentWarning !== null && trim($contentWarning) !== '' ? trim($contentWarning) : null])->save(),
                'mark_spam' => (function () use ($actor, $post, $cleanReason): void {
                    $post->forceFill([
                        'is_spam' => true,
                        'moderation_status' => ModerationStatus::Hidden,
                        'lifecycle_status' => PostLifecycleStatus::Rejected,
                        'moderation_reason' => $cleanReason,
                        'moderated_by' => $actor->id,
                        'moderated_at' => now(),
                        'trending_score' => 0,
                        'trending_raw_score' => 0,
                        'needs_rerank' => false,
                    ])->save();
                    $this->postService->stopCampaignsForPost($post, __('Promoted post flagged as spam; remaining ad budget refunded.'));
                })(),
                'soft_delete' => (function () use ($actor, $post, $cleanReason): void {
                    $post->forceFill([
                        'lifecycle_status' => PostLifecycleStatus::Deleted,
                        'moderation_reason' => $cleanReason,
                        'moderated_by' => $actor->id,
                        'moderated_at' => now(),
                        'trending_score' => 0,
                        'trending_raw_score' => 0,
                        'needs_rerank' => false,
                    ])->save();
                    $this->postService->stopCampaignsForPost($post, __('Promoted post removed by moderation; remaining ad budget refunded.'));
                    $post->delete();
                })(),
                'restore_deleted' => (function () use ($post): void {
                    $post->restore();
                    $restoredLifecycle = match ($post->moderation_status) {
                        ModerationStatus::Approved => PostLifecycleStatus::Published,
                        ModerationStatus::Pending => PostLifecycleStatus::PendingReview,
                        ModerationStatus::Rejected, ModerationStatus::Removed => PostLifecycleStatus::Rejected,
                        ModerationStatus::Hidden => PostLifecycleStatus::Hidden,
                    };
                    $post->forceFill([
                        'lifecycle_status' => $restoredLifecycle,
                        'needs_rerank' => $post->moderation_status === ModerationStatus::Approved,
                    ])->save();
                })(),
                default => throw ValidationException::withMessages(['action' => [__('Unsupported moderation action.')]]),
            };

            if ($post->moderation_status === ModerationStatus::Approved) {
                app(TrendingRankingService::class)->recalculatePost($post, false);
            }

            $this->auditLogger->log(
                "admin.post.{$action}",
                $post,
                ['reason' => $cleanReason, 'content_warning' => $contentWarning],
                $actor,
            );
        });

        if (in_array($action, ['approve', 'restore_visibility', 'reject', 'hide'], true) && $post->author !== null && $post->author->id !== $actor->id) {
            try {
                $notifKey = match ($action) {
                    'reject' => 'moderation.post_rejected',
                    'hide' => 'moderation.post_hidden',
                    default => 'moderation.post_approved',
                };
                $titleEn = match ($action) {
                    'reject' => 'Your post did not meet community guidelines: :reason',
                    'hide' => 'Your post was hidden by moderation: :reason',
                    default => 'Your post has been approved and published.',
                };
                $titleVi = match ($action) {
                    'reject' => 'Bài viết của bạn không đạt tiêu chuẩn cộng đồng: '.$cleanReason,
                    'hide' => 'Bài viết của bạn đã bị ẩn bởi kiểm duyệt: '.$cleanReason,
                    default => 'Bài viết của bạn đã được duyệt và hiển thị công khai.',
                };

                app(NotificationService::class)->notifyIfAllowed(
                    $post->author,
                    $actor,
                    new SocialActivityNotification(
                        eventKey: $notifKey,
                        titleVi: $titleVi,
                        titleEn: __($titleEn, ['reason' => $cleanReason]),
                        url: route('posts.show', $post, false),
                        actorName: $actor->name,
                        actorUsername: $actor->username,
                        titleKey: $titleEn,
                        titleParams: ['reason' => $cleanReason],
                    ),
                );
            } catch (\Throwable) {
                // Never break moderation commit if notification dispatch fails.
            }
        }
    }

    /**
     * @param  array{filter?: string, search?: string}  $filters
     * @return array<string, mixed>
     */
    public function commentsPayload(array $filters): array
    {
        $filter = (string) ($filters['filter'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Comment::query()
            ->withTrashed()
            ->with(['author:id,name,username', 'post:id,title,content']);

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(static function (Builder $q) use ($pattern): void {
                $q->where('content', 'like', $pattern)
                    ->orWhereHas('author', static fn (Builder $aq) => $aq->where('username', 'like', $pattern));
            });
        }

        match ($filter) {
            'hidden' => $query->where('moderation_status', ModerationStatus::Hidden->value),
            'spam' => $query->where('is_spam', true),
            'deleted' => $query->onlyTrashed(),
            default => null,
        };

        $paginator = $query->latest('id')->paginate(25)->withQueryString();

        return [
            'comments' => [
                'data' => array_values(collect($paginator->items())->map(static fn (Comment $c): array => [
                    'id' => $c->id,
                    'post_id' => $c->post_id,
                    'post_excerpt' => $c->post !== null ? Str::limit($c->post->title ?: $c->post->content, 80) : '#'.$c->post_id,
                    'content' => $c->content,
                    'moderation_status' => $c->moderation_status->value,
                    'moderation_reason' => $c->moderation_reason,
                    'is_spam' => $c->is_spam,
                    'author' => $c->author === null ? null : [
                        'id' => $c->author->id,
                        'name' => $c->author->name,
                        'username' => $c->author->username,
                    ],
                    'created_at' => $c->created_at?->toIso8601String(),
                    'deleted_at' => $c->deleted_at?->toIso8601String(),
                ])->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => ['filter' => $filter, 'search' => $search],
        ];
    }

    public function moderateComment(User $actor, Comment $comment, string $action, string $reason): void
    {
        DB::transaction(function () use ($actor, $comment, $action, $reason): void {
            match ($action) {
                'hide' => $comment->forceFill([
                    'moderation_status' => ModerationStatus::Hidden,
                    'moderation_reason' => trim($reason),
                    'moderated_by' => $actor->id,
                    'moderated_at' => now(),
                ])->save(),
                'approve', 'restore_visibility' => $comment->forceFill([
                    'moderation_status' => ModerationStatus::Approved,
                    'is_spam' => false,
                    'moderation_reason' => trim($reason),
                    'moderated_by' => $actor->id,
                    'moderated_at' => now(),
                ])->save(),
                'mark_spam' => $comment->forceFill([
                    'is_spam' => true,
                    'moderation_status' => ModerationStatus::Hidden,
                    'moderation_reason' => trim($reason),
                    'moderated_by' => $actor->id,
                    'moderated_at' => now(),
                ])->save(),
                'soft_delete' => $comment->delete(),
                'restore_deleted' => $comment->restore(),
                default => throw ValidationException::withMessages(['action' => [__('Unsupported comment action.')]]),
            };

            $this->recountPostComments($comment->post_id);
            $this->auditLogger->log("admin.comment.{$action}", $comment, ['reason' => trim($reason)], $actor);
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function bulkModerateComments(User $actor, array $ids, string $action, string $reason): int
    {
        $comments = Comment::query()->withTrashed()->whereIn('id', array_slice($ids, 0, 50))->get();

        foreach ($comments as $comment) {
            $this->moderateComment($actor, $comment, $action, $reason);
        }

        return $comments->count();
    }

    /**
     * @param  array{search?: string, status?: string}  $filters
     * @return array<string, mixed>
     */
    public function communitiesPayload(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');

        $query = Community::query()->withTrashed()->with(['owner:id,name,username']);

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(static function (Builder $q) use ($pattern): void {
                $q->where('name', 'like', $pattern)->orWhere('slug', 'like', $pattern);
            });
        }

        if ($status !== 'all') {
            if ($status === 'deleted') {
                $query->onlyTrashed();
            } else {
                $query->where('status', $status);
            }
        }

        $paginator = $query->orderByDesc('members_count')->orderByDesc('id')->paginate(20)->withQueryString();

        return [
            'communities' => [
                'data' => array_values(collect($paginator->items())->map(static fn (Community $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'category' => $c->category->value,
                    'visibility' => $c->visibility,
                    'status' => $c->status,
                    'is_verified_badge' => $c->is_verified_badge,
                    'is_featured' => $c->is_featured,
                    'allow_new_members' => $c->allow_new_members,
                    'allow_posting' => $c->allow_posting,
                    'members_count' => $c->members_count,
                    'posts_count' => $c->posts_count,
                    'moderation_reason' => $c->moderation_reason,
                    'owner' => $c->owner === null ? null : [
                        'id' => $c->owner->id,
                        'name' => $c->owner->name,
                        'username' => $c->owner->username,
                    ],
                    'created_at' => $c->created_at?->toIso8601String(),
                    'deleted_at' => $c->deleted_at?->toIso8601String(),
                ])->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => ['search' => $search, 'status' => $status],
        ];
    }

    public function moderateCommunity(User $actor, Community $community, string $action, string $reason, ?string $newOwnerUsername = null): void
    {
        DB::transaction(function () use ($actor, $community, $action, $reason, $newOwnerUsername): void {
            match ($action) {
                'toggle_featured' => $community->forceFill(['is_featured' => ! $community->is_featured])->save(),
                'toggle_verified' => $community->forceFill(['is_verified_badge' => ! $community->is_verified_badge])->save(),
                'toggle_new_members' => $community->forceFill(['allow_new_members' => ! $community->allow_new_members])->save(),
                'toggle_posting' => $community->forceFill(['allow_posting' => ! $community->allow_posting])->save(),
                'suspend' => $community->forceFill(['status' => 'suspended', 'moderation_reason' => trim($reason)])->save(),
                'hide' => $community->forceFill(['status' => 'hidden', 'moderation_reason' => trim($reason)])->save(),
                'activate' => $community->forceFill(['status' => 'active', 'moderation_reason' => trim($reason)])->save(),
                'soft_delete' => (function () use ($community, $reason): void {
                    $community->forceFill(['moderation_reason' => trim($reason)])->save();
                    $community->delete();
                })(),
                'restore' => (function () use ($community): void {
                    $community->restore();
                    $community->forceFill(['status' => 'active'])->save();
                })(),
                'transfer_owner' => (function () use ($community, $newOwnerUsername): void {
                    $cleanUsername = Str::lower(trim((string) $newOwnerUsername));
                    /** @var User|null $nextOwner */
                    $nextOwner = User::query()->where('username', $cleanUsername)->first();
                    if ($nextOwner === null || ! $nextOwner->isActive()) {
                        throw ValidationException::withMessages(['new_owner_username' => [__('Target active user not found.')]]);
                    }
                    CommunityMember::query()
                        ->where('community_id', $community->id)
                        ->where('user_id', $community->owner_id)
                        ->update(['role' => CommunityRole::Moderator->value]);
                    CommunityMember::query()->updateOrCreate(
                        ['community_id' => $community->id, 'user_id' => $nextOwner->id],
                        ['role' => CommunityRole::Owner->value, 'joined_at' => now()],
                    );
                    $community->forceFill(['owner_id' => $nextOwner->id])->save();
                })(),
                default => throw ValidationException::withMessages(['action' => [__('Unsupported community action.')]]),
            };

            $this->auditLogger->log("admin.community.{$action}", $community, ['reason' => trim($reason)], $actor);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function pollsPayload(string $search = ''): array
    {
        $query = PostPoll::query()->with(['post.author:id,name,username', 'options']);

        if (trim($search) !== '') {
            $pattern = '%'.addcslashes(trim($search), '\\%_').'%';
            $query->where('question', 'like', $pattern);
        }

        $paginator = $query->latest('id')->paginate(20)->withQueryString();

        return [
            'polls' => [
                'data' => array_values(collect($paginator->items())->map(static fn (PostPoll $poll): array => [
                    'id' => $poll->id,
                    'post_id' => $poll->post_id,
                    'question' => $poll->question,
                    'total_votes' => $poll->total_votes,
                    'is_closed' => $poll->is_closed,
                    'is_expired' => $poll->isExpired(),
                    'moderation_status' => $poll->moderation_status,
                    'moderation_reason' => $poll->moderation_reason,
                    'ends_at' => $poll->ends_at?->toIso8601String(),
                    'author_username' => $poll->post?->author?->username,
                    'options' => $poll->options->map(static fn ($o): array => [
                        'id' => $o->id,
                        'label' => $o->label,
                        'votes_count' => $o->votes_count,
                    ])->values()->all(),
                ])->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'search' => $search,
        ];
    }

    public function moderatePoll(User $actor, PostPoll $poll, string $action, string $reason): void
    {
        match ($action) {
            'close' => $poll->forceFill(['is_closed' => true, 'moderation_reason' => trim($reason)])->save(),
            'reopen' => $poll->forceFill(['is_closed' => false, 'moderation_reason' => trim($reason)])->save(),
            'hide' => $poll->forceFill(['moderation_status' => 'hidden', 'is_closed' => true, 'moderation_reason' => trim($reason)])->save(),
            'restore' => $poll->forceFill(['moderation_status' => 'approved'])->save(),
            'delete' => $poll->delete(),
            default => throw ValidationException::withMessages(['action' => [__('Unsupported poll action.')]]),
        };

        $this->auditLogger->log("admin.poll.{$action}", $poll, ['reason' => trim($reason)], $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function hashtagsPayload(string $search = ''): array
    {
        $query = Hashtag::query();

        if (trim($search) !== '') {
            $pattern = '%'.addcslashes(trim($search), '\\%_').'%';
            $query->where('name', 'like', $pattern)->orWhere('slug', 'like', $pattern);
        }

        $paginator = $query->orderByDesc('is_featured')->orderByDesc('posts_count')->paginate(30)->withQueryString();

        return [
            'hashtags' => [
                'data' => array_values(collect($paginator->items())->map(static fn (Hashtag $h): array => [
                    'id' => $h->id,
                    'name' => $h->name,
                    'slug' => $h->slug,
                    'posts_count' => $h->posts_count,
                    'is_featured' => $h->is_featured,
                    'is_restricted' => $h->is_restricted,
                    'is_trending_blocked' => $h->is_trending_blocked,
                    'is_spam' => $h->is_spam,
                ])->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'search' => $search,
        ];
    }

    /**
     * @param  array{is_featured?: bool, is_restricted?: bool, is_trending_blocked?: bool, is_spam?: bool}  $flags
     */
    public function updateHashtag(User $actor, Hashtag $hashtag, array $flags): void
    {
        $hashtag->forceFill([
            'is_featured' => (bool) ($flags['is_featured'] ?? $hashtag->is_featured),
            'is_restricted' => (bool) ($flags['is_restricted'] ?? $hashtag->is_restricted),
            'is_trending_blocked' => (bool) ($flags['is_trending_blocked'] ?? $hashtag->is_trending_blocked),
            'is_spam' => (bool) ($flags['is_spam'] ?? $hashtag->is_spam),
        ])->save();

        $this->hashtagService->recalculateCounts([$hashtag->id]);
        $this->auditLogger->log('admin.hashtag.updated', $hashtag, $flags, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function mediaPayload(string $status = 'all'): array
    {
        $query = PostMedia::query()->with(['owner:id,username', 'post:id,user_id,title']);

        if ($status !== 'all') {
            $query->where('moderation_status', $status);
        }

        $paginator = $query->latest('id')->paginate(24)->withQueryString();

        return [
            'media' => [
                'data' => array_values(collect($paginator->items())->map(static fn (PostMedia $m): array => [
                    'id' => $m->id,
                    'post_id' => $m->post_id,
                    'url' => $m->url(),
                    'disk' => $m->disk,
                    'mime_type' => $m->mime_type,
                    'width' => $m->width,
                    'height' => $m->height,
                    'size_bytes' => $m->size_bytes,
                    'moderation_status' => $m->moderation_status,
                    'moderation_reason' => $m->moderation_reason,
                    'owner_username' => $m->owner?->username,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'storageSummary' => [
                'total_files' => PostMedia::query()->count(),
                'total_bytes' => (int) PostMedia::query()->sum('size_bytes'),
                'hidden_files' => PostMedia::query()->where('moderation_status', 'hidden')->count(),
            ],
            'status' => $status,
        ];
    }

    public function moderateMedia(User $actor, PostMedia $media, string $action, string $reason): void
    {
        if ($action === 'delete') {
            $disk = $media->disk;
            $path = $media->path;
            $this->auditLogger->log('admin.media.deleted', $media, ['path' => $path, 'reason' => trim($reason)], $actor);
            $media->delete();
            $this->imageUploadService->deleteIfExists($disk, $path);

            return;
        }

        $media->forceFill([
            'moderation_status' => $action === 'hide' ? 'hidden' : 'approved',
            'moderation_reason' => trim($reason),
            'moderated_by' => $actor->id,
        ])->save();

        $this->auditLogger->log("admin.media.{$action}", $media, ['reason' => trim($reason)], $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function keywordsPayload(): array
    {
        $items = BlockedKeyword::query()->with(['creator:id,username'])->latest('id')->paginate(50)->withQueryString();

        return [
            'keywords' => [
                'data' => array_values(collect($items->items())->map(static fn (BlockedKeyword $k): array => [
                    'id' => $k->id,
                    'keyword' => $k->keyword,
                    'match_type' => $k->match_type,
                    'severity' => $k->severity,
                    'is_active' => $k->is_active,
                    'creator_username' => $k->creator?->username,
                    'created_at' => $k->created_at?->toIso8601String(),
                ])->all()),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ];
    }

    /**
     * @param  array{keyword: string, match_type: string, severity: string}  $data
     */
    public function createKeyword(User $actor, array $data): BlockedKeyword
    {
        $kw = new BlockedKeyword([
            'keyword' => trim($data['keyword']),
            'match_type' => $data['match_type'],
            'severity' => $data['severity'],
            'is_active' => true,
        ]);
        $kw->created_by = $actor->id;
        $kw->save();

        $this->contentFilter->flushCache();
        $this->auditLogger->log('admin.keyword.created', $kw, ['keyword' => $kw->keyword], $actor);

        return $kw;
    }

    public function deleteKeyword(User $actor, BlockedKeyword $keyword): void
    {
        $label = $keyword->keyword;
        $keyword->delete();
        $this->contentFilter->flushCache();
        $this->auditLogger->log('admin.keyword.deleted', null, ['keyword' => $label], $actor);
    }

    private function recountPostComments(int $postId): void
    {
        /** @var Post|null $post */
        $post = Post::query()->whereKey($postId)->first();
        if ($post !== null) {
            $post->comments_count = Comment::query()
                ->where('post_id', $post->id)
                ->where('moderation_status', ModerationStatus::Approved->value)
                ->count();

            $post->valid_comments_count = Comment::query()
                ->where('post_id', $post->id)
                ->where('user_id', '!=', $post->user_id)
                ->where('moderation_status', ModerationStatus::Approved->value)
                ->where('is_spam', false)
                ->whereHas('author', static function ($query): void {
                    $query->where('account_type', AccountType::Human->value)
                        ->where('is_test_account', false)
                        ->where('status', UserStatus::Active->value);
                })
                ->count();

            $post->needs_rerank = true;
            $post->save();
        }
    }
}
