<?php

namespace App\Services\Social;

use App\Enums\AdCampaignStatus;
use App\Enums\CreditEntryType;
use App\Enums\ModerationStatus;
use App\Enums\PostCategory;
use App\Enums\PostLifecycleStatus;
use App\Enums\PostVisibility;
use App\Enums\ReactionType;
use App\Enums\SeoEntityType;
use App\Jobs\Seo\ProcessSeoEntityJob;
use App\Models\AdCampaign;
use App\Models\Bookmark;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Post;
use App\Models\PostEditHistory;
use App\Models\PostMedia;
use App\Models\PostPoll;
use App\Models\PostPollOption;
use App\Models\PostPollVote;
use App\Models\PostReaction;
use App\Models\PostShare;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Billing\WalletService;
use App\Services\Media\SafeImageUploadService;
use App\Services\Moderation\AntiSpamEngine;
use App\Services\Moderation\DuplicateDetectionService;
use App\Services\Notification\NotificationService;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PostService
{
    public function __construct(
        private readonly SafeImageUploadService $imageUploadService,
        private readonly SafeLinkPreviewService $linkPreviewService,
        private readonly HashtagService $hashtagService,
        private readonly NotificationService $notificationService,
        private readonly AuditLogger $auditLogger,
        private readonly SystemSettingService $settings,
        private readonly WalletService $walletService,
    ) {}

    /**
     * Create a new post through the unified SysNoxis publishing pipeline
     * (eligibility, trust-tier limits, idempotency, rate limit, anti-spam, moderation, media, ranking).
     *
     * @param  array{
     *     title?: string|null,
     *     content: string,
     *     community_id?: int|null,
     *     category?: string|null,
     *     visibility?: string|null,
     *     link_url?: string|null,
     *     image_alts?: list<string|null>|null,
     *     poll_question?: string|null,
     *     poll_options?: list<string>|null,
     *     poll_duration_hours?: int|null,
     *     idempotency_key?: string|null,
     *     ip_address?: string|null,
     *     language_code?: string|null
     * }  $data
     * @param  list<UploadedFile>  $images
     */
    public function create(User $author, array $data, array $images = []): Post
    {
        if (! $this->settings->featureEnabled('feature_posting')) {
            throw ValidationException::withMessages([
                'content' => [__('Posting is currently disabled by the administrator.')],
            ]);
        }

        if ($author->isRestrictedFrom('post')) {
            throw ValidationException::withMessages([
                'content' => [__('Your account is currently restricted from publishing new posts.')],
            ]);
        }

        if ($images !== [] && ! $this->settings->featureEnabled('feature_image_upload')) {
            throw ValidationException::withMessages([
                'images' => [__('Image uploads are currently disabled.')],
            ]);
        }

        $trustResolver = app(TrustLevelResolver::class);
        $duplicateDetector = app(DuplicateDetectionService::class);
        $rateLimiter = app(PostingRateLimitService::class);
        $idempotencyService = app(PostIdempotencyService::class);
        $antiSpam = app(AntiSpamEngine::class);
        $rankingService = app(TrendingRankingService::class);

        $limits = $trustResolver->limitsFor($author);
        $cleanContent = $duplicateDetector->stripInvisibleOnly($data['content']);

        if ($cleanContent === '') {
            throw ValidationException::withMessages([
                'content' => [__('Post content cannot be empty.')],
            ]);
        }

        $maxLength = max(100, (int) $limits['max_post_length']);
        if (mb_strlen($cleanContent) > $maxLength) {
            throw ValidationException::withMessages([
                'content' => [__('Post content exceeds the maximum allowed length of :max characters.', ['max' => $maxLength])],
            ]);
        }

        $maxImages = max(1, min(10, (int) $limits['max_images_per_post']));
        if (count($images) > $maxImages) {
            throw ValidationException::withMessages([
                'images' => [__('You can attach up to :max images per post at your current trust tier.', ['max' => $maxImages])],
            ]);
        }

        $community = null;

        if (isset($data['community_id']) && (int) $data['community_id'] > 0) {
            /** @var Community|null $community */
            $community = Community::query()->find((int) $data['community_id']);

            if ($community === null || $community->status !== 'active') {
                throw ValidationException::withMessages([
                    'community_id' => [__('The selected community was not found or is currently unavailable.')],
                ]);
            }

            if (! $community->allow_posting) {
                throw ValidationException::withMessages([
                    'community_id' => [__('Posting is currently restricted in this community.')],
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

        $linkUrl = isset($data['link_url']) && trim((string) $data['link_url']) !== ''
            ? trim((string) $data['link_url'])
            : null;

        if ($linkUrl !== null && ! $this->linkPreviewService->isSafePublicUrl($linkUrl)) {
            throw ValidationException::withMessages([
                'link_url' => [__('The provided link URL is blocked or invalid.')],
            ]);
        }

        $imageSignatures = [];
        foreach ($images as $imageFile) {
            $imageSignatures[] = sprintf('%s:%d', $imageFile->getClientOriginalName(), (int) $imageFile->getSize());
        }

        $payloadHash = PostIdempotencyService::payloadHash(
            title: isset($data['title']) ? (string) $data['title'] : null,
            content: $cleanContent,
            communityId: $community?->id,
            category: isset($data['category']) ? (string) $data['category'] : null,
            visibility: isset($data['visibility']) ? (string) $data['visibility'] : null,
            linkUrl: $linkUrl,
            imageSignatures: $imageSignatures,
            pollQuestion: isset($data['poll_question']) ? (string) $data['poll_question'] : null,
        );

        $idempotency = $idempotencyService->acquireOrResolve(
            user: $author,
            action: 'post.create',
            rawKey: isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null,
            payloadHash: $payloadHash,
        );

        if ($idempotency['existing_post'] !== null) {
            return $idempotency['existing_post'];
        }

        $ipAddress = isset($data['ip_address']) ? $data['ip_address'] : request()->ip();
        $imageAlts = $data['image_alts'] ?? [];
        $storedImages = [];

        try {
            $rateLimiter->assertCanCreatePost($author, $ipAddress);

            $evaluation = $antiSpam->evaluatePost(
                author: $author,
                payload: [
                    'title' => isset($data['title']) ? (string) $data['title'] : null,
                    'content' => $cleanContent,
                    'community_id' => $community?->id,
                    'link_url' => $linkUrl,
                    'ip_address' => $ipAddress,
                ],
                actionContext: 'post.create',
            );

            $linkMeta = $linkUrl !== null ? $this->linkPreviewService->fetch($linkUrl) : null;

            foreach (array_slice($images, 0, $maxImages) as $image) {
                $storedImages[] = $this->imageUploadService->storeImage($image, 'posts', 'post', 'images', true);
            }

            $post = DB::transaction(function () use (
                $author,
                $data,
                $community,
                $linkUrl,
                $linkMeta,
                $storedImages,
                $imageAlts,
                $evaluation,
                $rateLimiter,
                $ipAddress,
            ): Post {
                $category = PostCategory::tryFrom((string) ($data['category'] ?? '')) ?? PostCategory::General;
                $visibility = $community !== null
                    ? ($community->visibility === 'private' ? PostVisibility::Community : PostVisibility::Public)
                    : (PostVisibility::tryFrom((string) ($data['visibility'] ?? '')) ?? PostVisibility::Public);

                $languageCode = isset($data['language_code']) && trim($data['language_code']) !== ''
                    ? trim($data['language_code'])
                    : $author->preferredLocale();

                $post = new Post([
                    'category' => $category->value,
                    'title' => $evaluation['filtered_title'],
                    'content' => $evaluation['filtered_content'],
                    'link_url' => $linkUrl,
                    'link_meta' => $linkMeta,
                    'visibility' => $visibility->value,
                    'published_at' => now(),
                ]);
                $post->user_id = $author->id;
                $post->community_id = $community?->id;
                $post->language_code = mb_substr($languageCode, 0, 12);
                $post->moderation_status = $evaluation['moderation_status'];
                $post->lifecycle_status = $evaluation['lifecycle_status'];
                $post->moderation_reason = $evaluation['moderation_reason'];
                $post->is_spam = $evaluation['risk_level'] === 'violation';
                $post->spam_risk_score = $evaluation['risk_score'];
                $post->content_fingerprint = $evaluation['content_fingerprint'];
                $post->link_fingerprint = $evaluation['link_fingerprint'];
                $post->save();

                foreach ($storedImages as $index => $mediaInfo) {
                    $rawAlt = isset($imageAlts[$index]) ? trim((string) $imageAlts[$index]) : '';
                    $media = new PostMedia([
                        'disk' => $mediaInfo['disk'],
                        'path' => $mediaInfo['path'],
                        'mime_type' => $mediaInfo['mime_type'],
                        'width' => $mediaInfo['width'],
                        'height' => $mediaInfo['height'],
                        'size_bytes' => $mediaInfo['size_bytes'],
                        'alt_text' => $rawAlt !== '' ? mb_substr($rawAlt, 0, 250) : null,
                        'sort_order' => $index,
                    ]);
                    $media->post_id = $post->id;
                    $media->user_id = $author->id;
                    $media->save();
                }

                $pollQuestion = isset($data['poll_question']) ? trim((string) $data['poll_question']) : '';
                $pollOptions = array_values(array_filter(
                    array_map(static fn (string $opt): string => trim($opt), $data['poll_options'] ?? []),
                    static fn (string $opt): bool => $opt !== '',
                ));

                if ($pollQuestion !== '' && count($pollOptions) >= 2 && $this->settings->featureEnabled('feature_polls')) {
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

                if ($community !== null && $post->moderation_status === ModerationStatus::Approved) {
                    $community->increment('posts_count');
                    CommunityMember::query()
                        ->where('community_id', $community->id)
                        ->where('user_id', $author->id)
                        ->increment('points', 5);
                }

                if (! $author->isAiAgent()) {
                    $rateLimiter->recordUsage($author, 'post', $post->id, $ipAddress);
                }

                return $post;
            });

            $idempotencyService->markCompleted($idempotency['record'], $post->id);
        } catch (\Throwable $exception) {
            $idempotencyService->markFailed($idempotency['record']);

            foreach ($storedImages as $mediaInfo) {
                $this->imageUploadService->deleteIfExists($mediaInfo['disk'], $mediaInfo['path']);
                $this->imageUploadService->deleteIfExists($mediaInfo['disk'], $mediaInfo['thumbnail_path']);
            }

            throw $exception;
        }

        $antiSpam->logPostEvaluationIfNotable($author, $post, $evaluation, 'post.create', $ipAddress);

        if ($post->moderation_status === ModerationStatus::Approved) {
            $rankingService->recalculatePost($post);
        }

        $this->auditLogger->log(
            action: 'post.created',
            subject: $post,
            metadata: [
                'visibility' => $post->visibility->value,
                'category' => $post->category->value,
                'moderation_status' => $post->moderation_status->value,
                'spam_risk_score' => $post->spam_risk_score,
            ],
            actor: $author,
        );

        if ($post->moderation_status === ModerationStatus::Approved && $post->visibility === PostVisibility::Public) {
            DB::afterCommit(static function () use ($post): void {
                ProcessSeoEntityJob::dispatch(SeoEntityType::Post->value, $post->id);
            });
        }

        return $post->load(['author.profile', 'community', 'hashtags', 'media', 'poll.options']);
    }

    /**
     * Update an existing post while preserving published_at & historical engagement,
     * incrementing edit_count, and re-running Anti-Spam & Moderation safeguards.
     *
     * @param  array{
     *     title?: string|null,
     *     content: string,
     *     category?: string|null,
     *     visibility?: string|null,
     *     link_url?: string|null,
     *     link_synced?: bool|string|int|null,
     *     media_synced?: bool|string|int|null,
     *     existing_media?: list<array{id: int|string, alt_text?: string|null}>|null,
     *     image_alts?: list<string|null>|null,
     *     expected_updated_at?: string|null,
     *     ip_address?: string|null
     * }  $data
     * @param  list<UploadedFile>  $images
     */
    public function update(User $actor, Post $post, array $data, array $images = []): Post
    {
        $policyConfig = app(PostingPolicyConfigService::class);
        $trustResolver = app(TrustLevelResolver::class);
        $duplicateDetector = app(DuplicateDetectionService::class);
        $rateLimiter = app(PostingRateLimitService::class);
        $antiSpam = app(AntiSpamEngine::class);
        $rankingService = app(TrendingRankingService::class);

        $ipAddress = isset($data['ip_address']) ? $data['ip_address'] : request()->ip();
        $editWindowMinutes = $policyConfig->editWindowMinutes();

        if ($editWindowMinutes > 0 && ! $actor->isStaff()) {
            $anchor = $post->published_at ?? $post->created_at ?? now();
            if ($anchor->diffInMinutes(now()) > $editWindowMinutes) {
                throw ValidationException::withMessages([
                    'content' => [__('The editing window for this post (:minutes minutes) has closed.', ['minutes' => $editWindowMinutes])],
                ]);
            }
        }

        if ($images !== [] && ! $this->settings->featureEnabled('feature_image_upload')) {
            throw ValidationException::withMessages([
                'images' => [__('Image uploads are currently disabled.')],
            ]);
        }

        $rateLimiter->assertCanEditPost($actor, $ipAddress);

        $cleanContent = $duplicateDetector->stripInvisibleOnly($data['content']);
        if ($cleanContent === '') {
            throw ValidationException::withMessages([
                'content' => [__('Post content cannot be empty.')],
            ]);
        }

        $limits = $trustResolver->limitsFor($actor);
        $maxLength = max(100, (int) $limits['max_post_length']);
        if (mb_strlen($cleanContent) > $maxLength) {
            throw ValidationException::withMessages([
                'content' => [__('Post content exceeds the maximum allowed length of :max characters.', ['max' => $maxLength])],
            ]);
        }

        $linkSynced = array_key_exists('link_url', $data)
            || filter_var($data['link_synced'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $linkUrl = $post->link_url;
        $linkMeta = $post->link_meta;

        if ($linkSynced) {
            $rawLink = isset($data['link_url']) ? trim((string) $data['link_url']) : '';
            $linkUrl = $rawLink !== '' ? $rawLink : null;

            if ($linkUrl !== null && ! $this->linkPreviewService->isSafePublicUrl($linkUrl)) {
                throw ValidationException::withMessages([
                    'link_url' => [__('The provided link URL is blocked or invalid.')],
                ]);
            }

            if ($linkUrl === null) {
                $linkMeta = null;
            } elseif ($linkUrl !== $post->link_url) {
                $linkMeta = $this->linkPreviewService->fetch($linkUrl);
            }
        }

        $nextVisibility = $post->visibility;
        if ($post->community_id === null && isset($data['visibility'])) {
            $candidateVisibility = PostVisibility::tryFrom((string) $data['visibility']);
            if ($candidateVisibility === PostVisibility::Public || $candidateVisibility === PostVisibility::Private) {
                if (
                    $candidateVisibility === PostVisibility::Private
                    && $post->visibility !== PostVisibility::Private
                    && AdCampaign::query()
                        ->where('post_id', $post->id)
                        ->whereIn('status', [
                            AdCampaignStatus::PendingReview->value,
                            AdCampaignStatus::Active->value,
                            AdCampaignStatus::Paused->value,
                        ])
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'visibility' => [__('You cannot switch a promoted post to private while an ad campaign is active or pending review.')],
                    ]);
                }

                $nextVisibility = $candidateVisibility;
            }
        }

        $mediaSynced = $images !== []
            || filter_var($data['media_synced'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $maxImages = max(1, min(10, (int) $limits['max_images_per_post']));

        /** @var list<array{id: int, alt_text: ?string}> $keptEntries */
        $keptEntries = [];
        if ($mediaSynced) {
            $existingRows = $post->media()->get()->keyBy('id');
            $seenIds = [];
            foreach (($data['existing_media'] ?? []) as $item) {
                $mediaId = (int) $item['id'];
                if ($mediaId <= 0 || isset($seenIds[$mediaId])) {
                    continue;
                }
                if (! $existingRows->has($mediaId)) {
                    throw ValidationException::withMessages([
                        'images' => [__('One or more selected media items do not belong to this post.')],
                    ]);
                }
                $seenIds[$mediaId] = true;
                $rawAlt = isset($item['alt_text']) ? trim((string) $item['alt_text']) : '';
                $keptEntries[] = [
                    'id' => $mediaId,
                    'alt_text' => $rawAlt !== '' ? mb_substr($rawAlt, 0, 250) : null,
                ];
            }

            if (count($keptEntries) + count($images) > $maxImages) {
                throw ValidationException::withMessages([
                    'images' => [__('You can attach up to :max images per post at your current trust tier.', ['max' => $maxImages])],
                ]);
            }
        }

        $evaluation = $antiSpam->evaluatePost(
            author: $actor,
            payload: [
                'title' => isset($data['title']) ? (string) $data['title'] : null,
                'content' => $cleanContent,
                'community_id' => $post->community_id,
                'link_url' => $linkUrl,
                'ip_address' => $ipAddress,
            ],
            actionContext: 'post.edit',
            ignorePostId: $post->id,
        );

        $category = PostCategory::tryFrom((string) ($data['category'] ?? '')) ?? $post->category;
        $imageAlts = $data['image_alts'] ?? [];
        $storedImages = [];
        $removedMediaFiles = [];

        try {
            if ($mediaSynced && $images !== []) {
                foreach (array_slice($images, 0, max(0, $maxImages - count($keptEntries))) as $image) {
                    $storedImages[] = $this->imageUploadService->storeImage($image, 'posts', 'post', 'images', true);
                }
            }

            DB::transaction(function () use (
                $actor,
                $post,
                $data,
                $category,
                $nextVisibility,
                $linkUrl,
                $linkMeta,
                $mediaSynced,
                $keptEntries,
                $storedImages,
                $imageAlts,
                $evaluation,
                $rateLimiter,
                $ipAddress,
                &$removedMediaFiles,
            ): void {
                /** @var Post $lockedPost */
                $lockedPost = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

                if (isset($data['expected_updated_at']) && trim($data['expected_updated_at']) !== '') {
                    $expectedTime = Carbon::parse(trim($data['expected_updated_at']))->getTimestamp();
                    $actualTime = ($lockedPost->updated_at ?? $lockedPost->created_at)?->getTimestamp();
                    if ($actualTime !== null && $expectedTime !== $actualTime) {
                        throw ValidationException::withMessages([
                            'content' => [__('This post was updated in another session. Please refresh and review the latest version before saving.')],
                        ]);
                    }
                }

                $editHistory = new PostEditHistory([
                    'previous_title' => $lockedPost->title,
                    'previous_content' => $lockedPost->content,
                ]);
                $editHistory->post_id = $lockedPost->id;
                $editHistory->editor_id = $actor->id;
                $editHistory->created_at = now();
                $editHistory->save();

                $lockedPost->forceFill([
                    'title' => $evaluation['filtered_title'],
                    'content' => $evaluation['filtered_content'],
                    'category' => $category->value,
                    'visibility' => $nextVisibility->value,
                    'link_url' => $linkUrl,
                    'link_meta' => $linkMeta,
                    'edited_at' => now(),
                    'edit_count' => (int) $lockedPost->edit_count + 1,
                    'moderation_status' => $evaluation['moderation_status'],
                    'lifecycle_status' => $evaluation['lifecycle_status'],
                    'moderation_reason' => $evaluation['moderation_reason'],
                    'spam_risk_score' => $evaluation['risk_score'],
                    'content_fingerprint' => $evaluation['content_fingerprint'],
                    'link_fingerprint' => $evaluation['link_fingerprint'],
                    'needs_rerank' => $evaluation['moderation_status'] === ModerationStatus::Approved,
                ])->save();

                if ($mediaSynced) {
                    $existingMedia = $lockedPost->media()->get()->keyBy('id');
                    $keptIds = [];

                    foreach ($keptEntries as $sortOrder => $entry) {
                        $mediaModel = $existingMedia->get($entry['id']);
                        if ($mediaModel instanceof PostMedia) {
                            $mediaModel->forceFill([
                                'sort_order' => $sortOrder,
                                'alt_text' => $entry['alt_text'],
                            ])->save();
                            $keptIds[] = $mediaModel->id;
                        }
                    }

                    $baseOrder = count($keptIds);
                    foreach ($storedImages as $offset => $mediaInfo) {
                        $rawAlt = isset($imageAlts[$offset]) ? trim((string) $imageAlts[$offset]) : '';
                        $media = new PostMedia([
                            'disk' => $mediaInfo['disk'],
                            'path' => $mediaInfo['path'],
                            'mime_type' => $mediaInfo['mime_type'],
                            'width' => $mediaInfo['width'],
                            'height' => $mediaInfo['height'],
                            'size_bytes' => $mediaInfo['size_bytes'],
                            'alt_text' => $rawAlt !== '' ? mb_substr($rawAlt, 0, 250) : null,
                            'sort_order' => $baseOrder + $offset,
                        ]);
                        $media->post_id = $lockedPost->id;
                        $media->user_id = $actor->id;
                        $media->save();
                    }

                    foreach ($existingMedia as $existingRow) {
                        if (! in_array($existingRow->id, $keptIds, true)) {
                            $removedMediaFiles[] = [
                                'disk' => $existingRow->disk,
                                'path' => $existingRow->path,
                            ];
                            $existingRow->delete();
                        }
                    }
                }

                if ($evaluation['moderation_status'] !== ModerationStatus::Approved) {
                    $this->stopCampaignsForPost($lockedPost, __('Promoted post entered moderation review after editing; remaining budget refunded.'));
                }

                if (! $actor->isAiAgent()) {
                    $rateLimiter->recordUsage($actor, 'edit', $lockedPost->id, $ipAddress);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($storedImages as $mediaInfo) {
                $this->imageUploadService->deleteIfExists($mediaInfo['disk'], $mediaInfo['path']);
                $this->imageUploadService->deleteIfExists($mediaInfo['disk'], $mediaInfo['thumbnail_path']);
            }

            throw $exception;
        }

        if ($removedMediaFiles !== []) {
            $imageUploadService = $this->imageUploadService;
            DB::afterCommit(static function () use ($imageUploadService, $removedMediaFiles): void {
                foreach ($removedMediaFiles as $file) {
                    $imageUploadService->deleteIfExists($file['disk'], $file['path']);
                }
            });
        }

        $post->refresh();
        $this->hashtagService->syncForPost($post);
        $antiSpam->logPostEvaluationIfNotable($actor, $post, $evaluation, 'post.edit', $ipAddress);

        if ($post->moderation_status !== ModerationStatus::Approved) {
            $rankingService->recalculatePost($post);
        }

        $this->auditLogger->log(
            action: 'post.updated',
            subject: $post,
            metadata: [
                'edit_count' => (int) $post->edit_count,
                'moderation_status' => $post->moderation_status->value,
            ],
            actor: $actor,
        );

        if ($post->moderation_status === ModerationStatus::Approved && $post->visibility === PostVisibility::Public) {
            DB::afterCommit(static function () use ($post): void {
                ProcessSeoEntityJob::dispatch(SeoEntityType::Post->value, $post->id);
            });
        }

        return $post->fresh(['author.profile', 'community', 'hashtags', 'media', 'poll.options']) ?? $post;
    }

    public function stopCampaignsForPost(Post $post, string $reason): int
    {
        $campaigns = AdCampaign::query()
            ->where('post_id', $post->id)
            ->whereIn('status', [
                AdCampaignStatus::PendingReview->value,
                AdCampaignStatus::Active->value,
                AdCampaignStatus::Paused->value,
            ])
            ->get();

        $stopped = 0;

        foreach ($campaigns as $campaign) {
            $campaign->status = AdCampaignStatus::Cancelled;
            $campaign->rejection_reason = $reason;
            $campaign->save();

            $this->walletService->releaseCampaignHold(
                $campaign,
                CreditEntryType::Refund,
                $reason,
            );
            $stopped++;
        }

        return $stopped;
    }

    public function delete(User $actor, Post $post): void
    {
        $mediaItems = $post->media()->get(['disk', 'path']);
        $hashtagIds = $post->hashtags()->pluck('hashtags.id')->map(static fn (mixed $id): int => (int) $id)->all();
        $communityId = $post->community_id;

        DB::transaction(function () use ($post, $communityId): void {
            $wasApproved = $post->moderation_status === ModerationStatus::Approved;
            $this->stopCampaignsForPost($post, __('Promoted post was deleted; remaining ad budget refunded.'));

            $post->forceFill([
                'lifecycle_status' => PostLifecycleStatus::Deleted,
                'trending_score' => 0,
                'trending_raw_score' => 0,
                'needs_rerank' => false,
            ])->saveQuietly();

            $post->delete();

            if ($communityId !== null && $wasApproved) {
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
            $thumbPath = preg_replace('/\/([^\/]+)$/', '/thumbs/$1', $media->path);
            if (is_string($thumbPath) && $thumbPath !== $media->path) {
                $this->imageUploadService->deleteIfExists($media->disk, $thumbPath);
            }
        }

        $this->auditLogger->log(
            action: 'post.deleted',
            subject: $post,
            actor: $actor,
        );

        DB::afterCommit(static function () use ($post): void {
            ProcessSeoEntityJob::dispatch(SeoEntityType::Post->value, $post->id, true, 410);
        });
    }

    /**
     * Toggle or update a user's reaction on a post atomically while updating organic valid signals.
     *
     * @return array{active: bool, type: string|null, reactions_count: int}
     */
    public function toggleReaction(User $user, Post $post, ReactionType $type): array
    {
        if (! $this->settings->featureEnabled('feature_reactions')) {
            throw ValidationException::withMessages([
                'type' => [__('Reactions are currently disabled.')],
            ]);
        }

        $rankingService = app(TrendingRankingService::class);

        $result = DB::transaction(function () use ($user, $post, $type, $rankingService): array {
            /** @var Post $lockedPost */
            $lockedPost = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            $existing = PostReaction::query()
                ->where('post_id', $lockedPost->id)
                ->where('user_id', $user->id)
                ->first();

            $active = true;
            $resultingType = $type->value;
            $signalDelta = 0;

            if ($existing !== null && $existing->type === $type) {
                $existing->delete();
                $active = false;
                $resultingType = null;
                $signalDelta = -1;
            } elseif ($existing !== null) {
                $existing->type = $type;
                $existing->save();
            } else {
                PostReaction::query()->create([
                    'post_id' => $lockedPost->id,
                    'user_id' => $user->id,
                    'type' => $type->value,
                ]);
                $signalDelta = 1;
            }

            $count = PostReaction::query()->where('post_id', $lockedPost->id)->count();
            $lockedPost->reactions_count = $count;

            if ($signalDelta !== 0 && $rankingService->isEligibleOrganicActor($user, $lockedPost->user_id)) {
                if ($signalDelta > 0 && $rankingService->canUserContributeRankingSignal($user)) {
                    $lockedPost->valid_reactions_count = (int) $lockedPost->valid_reactions_count + 1;
                    $rankingService->recordRankingSignal($user, $lockedPost->id);
                    $lockedPost->needs_rerank = true;
                } elseif ($signalDelta < 0) {
                    $lockedPost->valid_reactions_count = max(0, (int) $lockedPost->valid_reactions_count - 1);
                    $lockedPost->needs_rerank = true;
                }
            }

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
                    titleKey: ':name reacted to your post.',
                    titleParams: ['name' => $user->name],
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
     * @return array{bookmarked: bool, bookmarks_count: int}
     */
    public function toggleBookmark(User $user, Post $post): array
    {
        $rankingService = app(TrendingRankingService::class);

        return DB::transaction(function () use ($user, $post, $rankingService): array {
            /** @var Post $lockedPost */
            $lockedPost = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            $existing = Bookmark::query()
                ->where('post_id', $lockedPost->id)
                ->where('user_id', $user->id)
                ->first();

            $bookmarked = true;

            if ($existing !== null) {
                $existing->delete();
                $bookmarked = false;
            } else {
                Bookmark::query()->create([
                    'post_id' => $lockedPost->id,
                    'user_id' => $user->id,
                ]);
            }

            $totalBookmarks = Bookmark::query()->where('post_id', $lockedPost->id)->count();
            $lockedPost->bookmarks_count = $totalBookmarks;

            if ($rankingService->isEligibleOrganicActor($user, $lockedPost->user_id)) {
                if ($bookmarked && $rankingService->canUserContributeRankingSignal($user)) {
                    $lockedPost->valid_bookmarks_count = (int) $lockedPost->valid_bookmarks_count + 1;
                    $rankingService->recordRankingSignal($user, $lockedPost->id);
                    $lockedPost->needs_rerank = true;
                } elseif (! $bookmarked) {
                    $lockedPost->valid_bookmarks_count = max(0, (int) $lockedPost->valid_bookmarks_count - 1);
                    $lockedPost->needs_rerank = true;
                }
            }

            $lockedPost->save();

            return [
                'bookmarked' => $bookmarked,
                'bookmarks_count' => $totalBookmarks,
            ];
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

            if ($lockedPoll->is_closed || $lockedPoll->moderation_status !== 'approved' || $lockedPoll->isExpired()) {
                throw ValidationException::withMessages([
                    'option_id' => [__('This poll is closed or has already ended.')],
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

    public function incrementShare(
        Post $post,
        ?User $actor = null,
        ?string $actorHash = null,
        string $channel = 'copy_link',
    ): int {
        $rankingService = app(TrendingRankingService::class);
        $secret = (string) config('app.key', 'syncviet');
        $resolvedHash = is_string($actorHash) && trim($actorHash) !== ''
            ? trim($actorHash)
            : ($actor !== null
                ? hash_hmac('sha256', 'share_user:'.$actor->id, $secret)
                : hash_hmac('sha256', 'share_anon:'.Str::random(16), $secret));

        $cleanChannel = in_array($channel, ['copy_link', 'native', 'internal'], true) ? $channel : 'copy_link';

        return DB::transaction(function () use ($post, $actor, $resolvedHash, $cleanChannel, $rankingService): int {
            /** @var Post $locked */
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            $alreadyShared = PostShare::query()
                ->where('post_id', $locked->id)
                ->where('actor_hash', $resolvedHash)
                ->exists();

            if ($alreadyShared) {
                return (int) $locked->shares_count;
            }

            $isValid = $actor === null
                || ($rankingService->isEligibleOrganicActor($actor, $locked->user_id) && $rankingService->canUserContributeRankingSignal($actor));

            PostShare::query()->create([
                'post_id' => $locked->id,
                'user_id' => $actor?->id,
                'actor_hash' => $resolvedHash,
                'share_channel' => $cleanChannel,
                'is_valid' => $isValid,
                'created_at' => now(),
            ]);

            $locked->shares_count = (int) $locked->shares_count + 1;

            if ($isValid) {
                $locked->valid_shares_count = (int) $locked->valid_shares_count + 1;
                if ($actor !== null) {
                    $rankingService->recordRankingSignal($actor, $locked->id);
                }
                $locked->needs_rerank = true;
            }

            $locked->save();

            return (int) $locked->shares_count;
        });
    }
}
