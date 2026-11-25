<?php

namespace App\Services\Social;

use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\PostVisibility;
use App\Enums\ReactionType;
use App\Models\Bookmark;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostPoll;
use App\Models\PostPollOption;
use App\Models\PostPollVote;
use App\Models\PostReaction;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Media\SafeImageUploadService;
use App\Services\Notification\NotificationService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostService
{
    public function __construct(
        private readonly SafeImageUploadService $imageUploadService,
        private readonly SafeLinkPreviewService $linkPreviewService,
        private readonly HashtagService $hashtagService,
        private readonly NotificationService $notificationService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Create a new post with optional images, link preview, poll, community placement, and hashtags.
     *
     * @param  array{
     *     title?: string|null,
     *     content: string,
     *     community_id?: int|null,
     *     category?: string|null,
     *     visibility?: string|null,
     *     link_url?: string|null,
     *     poll_question?: string|null,
     *     poll_options?: list<string>|null,
     *     poll_duration_hours?: int|null
     * }  $data
     * @param  list<UploadedFile>  $images
     */
    public function create(User $author, array $data, array $images = []): Post
    {
        $community = null;

        if (isset($data['community_id']) && (int) $data['community_id'] > 0) {
            /** @var Community|null $community */
            $community = Community::query()->find((int) $data['community_id']);

            if ($community === null) {
                throw ValidationException::withMessages([
                    'community_id' => [__('The selected community was not found.')],
                ]);
            }

            $isMember = $community->owner_id === $author->id
                || CommunityMember::query()
                    ->where('community_id', $community->id)
                    ->where('user_id', $author->id)
                    ->exists();

            if (! $isMember) {
                throw ValidationException::withMessages([
                    'community_id' => [__('You must join this community before posting in it.')],
                ]);
            }
        }

        $linkUrl = isset($data['link_url']) && trim($data['link_url']) !== ''
            ? trim($data['link_url'])
            : null;

        $linkMeta = null;

        if ($linkUrl !== null) {
            if (! $this->linkPreviewService->isSafePublicUrl($linkUrl)) {
                throw ValidationException::withMessages([
                    'link_url' => [__('The provided link URL is blocked or invalid.')],
                ]);
            }

            $linkMeta = $this->linkPreviewService->fetch($linkUrl);
        }

        $storedImages = [];

        try {
            foreach (array_slice($images, 0, 4) as $image) {
                $storedImages[] = $this->imageUploadService->storeImage($image, 'posts');
            }

            $post = DB::transaction(function () use ($author, $data, $community, $linkUrl, $linkMeta, $storedImages): Post {
                $category = PostCategory::tryFrom((string) ($data['category'] ?? '')) ?? PostCategory::General;
                $visibility = $community !== null
                    ? ($community->visibility === 'private' ? PostVisibility::Community : PostVisibility::Public)
                    : (PostVisibility::tryFrom((string) ($data['visibility'] ?? '')) ?? PostVisibility::Public);

                $post = new Post([
                    'category' => $category->value,
                    'title' => isset($data['title']) && trim($data['title']) !== '' ? trim($data['title']) : null,
                    'content' => trim($data['content']),
                    'link_url' => $linkUrl,
                    'link_meta' => $linkMeta,
                    'visibility' => $visibility->value,
                    'published_at' => now(),
                ]);
                $post->user_id = $author->id;
                $post->community_id = $community?->id;
                $post->moderation_status = ModerationStatus::Approved;
                $post->save();

                foreach ($storedImages as $index => $mediaInfo) {
                    $media = new PostMedia([
                        'disk' => $mediaInfo['disk'],
                        'path' => $mediaInfo['path'],
                        'mime_type' => $mediaInfo['mime_type'],
                        'width' => $mediaInfo['width'],
                        'height' => $mediaInfo['height'],
                        'size_bytes' => $mediaInfo['size_bytes'],
                        'sort_order' => $index,
                    ]);
                    $media->post_id = $post->id;
                    $media->save();
                }

                $pollQuestion = isset($data['poll_question']) ? trim($data['poll_question']) : '';
                $pollOptions = array_values(array_filter(
                    array_map(static fn (string $opt): string => trim($opt), $data['poll_options'] ?? []),
                    static fn (string $opt): bool => $opt !== '',
                ));

                if ($pollQuestion !== '' && count($pollOptions) >= 2) {
                    $hours = (int) ($data['poll_duration_hours'] ?? 72);
                    $poll = new PostPoll([
                        'question' => $pollQuestion,
                        'ends_at' => now()->addHours(max(1, min(720, $hours))),
                        'allows_multiple' => false,
                    ]);
                    $poll->post_id = $post->id;
                    $poll->total_votes = 0;
                    $poll->save();

                    foreach (array_slice($pollOptions, 0, 5) as $idx => $label) {
                        $option = new PostPollOption([
                            'label' => $label,
                            'sort_order' => $idx,
                        ]);
                        $option->poll_id = $poll->id;
                        $option->votes_count = 0;
                        $option->save();
                    }
                }

                $this->hashtagService->syncForPost($post);

                if ($community !== null) {
                    $community->increment('posts_count');
                    CommunityMember::query()
                        ->where('community_id', $community->id)
                        ->where('user_id', $author->id)
                        ->increment('points', 5);
                }

                return $post;
            });
        } catch (\Throwable $exception) {
            foreach ($storedImages as $mediaInfo) {
                $this->imageUploadService->deleteIfExists($mediaInfo['disk'], $mediaInfo['path']);
            }

            throw $exception;
        }

        $this->auditLogger->log(
            action: 'post.created',
            subject: $post,
            metadata: ['visibility' => $post->visibility->value, 'category' => $post->category->value],
            actor: $author,
        );

        return $post->load(['author.profile', 'community', 'hashtags', 'media', 'poll.options']);
    }

    /**
     * @param  array{title?: string|null, content: string, category?: string|null}  $data
     */
    public function update(User $actor, Post $post, array $data): Post
    {
        $category = PostCategory::tryFrom((string) ($data['category'] ?? '')) ?? $post->category;

        $post->forceFill([
            'title' => isset($data['title']) && trim($data['title']) !== '' ? trim($data['title']) : null,
            'content' => trim($data['content']),
            'category' => $category->value,
        ])->save();

        $this->hashtagService->syncForPost($post);

        $this->auditLogger->log(
            action: 'post.updated',
            subject: $post,
            actor: $actor,
        );

        return $post->fresh(['author.profile', 'community', 'hashtags', 'media', 'poll.options']) ?? $post;
    }

    public function delete(User $actor, Post $post): void
    {
        $mediaItems = $post->media()->get(['disk', 'path']);
        $hashtagIds = $post->hashtags()->pluck('hashtags.id')->map(static fn (mixed $id): int => (int) $id)->all();
        $communityId = $post->community_id;

        DB::transaction(function () use ($post, $communityId): void {
            $post->delete();

            if ($communityId !== null) {
                /** @var Community|null $community */
                $community = Community::query()->whereKey($communityId)->lockForUpdate()->first();

                if ($community !== null) {
                    $community->posts_count = max(0, $community->posts_count - 1);
                    $community->save();
                }
            }
        });

        if ($hashtagIds !== []) {
            $this->hashtagService->recalculateCounts(array_values($hashtagIds));
        }

        foreach ($mediaItems as $media) {
            $this->imageUploadService->deleteIfExists($media->disk, $media->path);
        }

        $this->auditLogger->log(
            action: 'post.deleted',
            subject: $post,
            actor: $actor,
        );
    }

    /**
     * Toggle or update a user's reaction on a post atomically.
     *
     * @return array{active: bool, type: string|null, reactions_count: int}
     */
    public function toggleReaction(User $user, Post $post, ReactionType $type): array
    {
        $result = DB::transaction(function () use ($user, $post, $type): array {
            /** @var Post $lockedPost */
            $lockedPost = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            $existing = PostReaction::query()
                ->where('post_id', $lockedPost->id)
                ->where('user_id', $user->id)
                ->first();

            $active = true;
            $resultingType = $type->value;

            if ($existing !== null && $existing->type === $type) {
                $existing->delete();
                $active = false;
                $resultingType = null;
            } elseif ($existing !== null) {
                $existing->type = $type;
                $existing->save();
            } else {
                PostReaction::query()->create([
                    'post_id' => $lockedPost->id,
                    'user_id' => $user->id,
                    'type' => $type->value,
                ]);
            }

            $count = PostReaction::query()->where('post_id', $lockedPost->id)->count();
            $lockedPost->reactions_count = $count;
            $lockedPost->save();

            return [
                'active' => $active,
                'type' => $resultingType,
                'reactions_count' => $count,
                'created_new' => $existing === null,
            ];
        });

        if ($result['created_new'] && $post->author !== null) {
            $this->notificationService->notifyIfAllowed(
                $post->author,
                $user,
                new SocialActivityNotification(
                    eventKey: 'post.reacted',
                    titleVi: "{$user->name} đã bày tỏ cảm xúc về bài viết của bạn.",
                    titleEn: "{$user->name} reacted to your post.",
                    url: route('posts.show', $post, false),
                    actorName: $user->name,
                    actorUsername: $user->username,
                ),
            );
        }

        return [
            'active' => $result['active'],
            'type' => $result['type'],
            'reactions_count' => $result['reactions_count'],
        ];
    }

    /**
     * @return array{bookmarked: bool}
     */
    public function toggleBookmark(User $user, Post $post): array
    {
        return DB::transaction(function () use ($user, $post): array {
            $existing = Bookmark::query()
                ->where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing !== null) {
                $existing->delete();

                return ['bookmarked' => false];
            }

            Bookmark::query()->create([
                'post_id' => $post->id,
                'user_id' => $user->id,
            ]);

            return ['bookmarked' => true];
        });
    }

    /**
     * Cast a poll vote inside a row-locked transaction preventing expired, cross-poll, or duplicate voting.
     *
     * @return array{
     *     total_votes: int,
     *     voted_option_ids: list<int>,
     *     options: list<array{id: int, label: string, votes_count: int, percentage: int}>
     * }
     */
    public function votePoll(User $user, PostPoll $poll, int $optionId): array
    {
        return DB::transaction(function () use ($user, $poll, $optionId): array {
            /** @var PostPoll $lockedPoll */
            $lockedPoll = PostPoll::query()->whereKey($poll->id)->lockForUpdate()->firstOrFail();

            if ($lockedPoll->isExpired()) {
                throw ValidationException::withMessages([
                    'option_id' => [__('This poll has already ended.')],
                ]);
            }

            /** @var PostPollOption|null $option */
            $option = PostPollOption::query()
                ->where('id', $optionId)
                ->where('poll_id', $lockedPoll->id)
                ->first();

            if ($option === null) {
                throw ValidationException::withMessages([
                    'option_id' => [__('The selected poll option does not belong to this poll.')],
                ]);
            }

            if (! $lockedPoll->allows_multiple) {
                PostPollVote::query()
                    ->where('poll_id', $lockedPoll->id)
                    ->where('user_id', $user->id)
                    ->delete();
            }

            PostPollVote::query()->firstOrCreate([
                'poll_id' => $lockedPoll->id,
                'option_id' => $option->id,
                'user_id' => $user->id,
            ]);

            $options = $lockedPoll->options()->get();
            $totalVotes = 0;

            foreach ($options as $opt) {
                $count = PostPollVote::query()->where('option_id', $opt->id)->count();
                $opt->votes_count = $count;
                $opt->save();
                $totalVotes += $count;
            }

            $lockedPoll->total_votes = $totalVotes;
            $lockedPoll->save();

            $votedOptionIds = array_values(
                PostPollVote::query()
                    ->where('poll_id', $lockedPoll->id)
                    ->where('user_id', $user->id)
                    ->pluck('option_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all(),
            );

            return [
                'total_votes' => $totalVotes,
                'voted_option_ids' => $votedOptionIds,
                'options' => array_values(
                    $options->map(static function (PostPollOption $opt) use ($totalVotes): array {
                        return [
                            'id' => $opt->id,
                            'label' => $opt->label,
                            'votes_count' => $opt->votes_count,
                            'percentage' => $totalVotes > 0 ? (int) round(($opt->votes_count / $totalVotes) * 100) : 0,
                        ];
                    })->all(),
                ),
            ];
        });
    }

    public function incrementShare(Post $post): int
    {
        return DB::transaction(function () use ($post): int {
            /** @var Post $locked */
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            $locked->shares_count = $locked->shares_count + 1;
            $locked->save();

            return $locked->shares_count;
        });
    }
}
