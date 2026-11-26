<?php

namespace App\Services\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\ContentOrigin;
use App\Enums\SeoEntityType;
use App\Jobs\Seo\ProcessSeoEntityJob;
use App\Models\AiContentDraft;
use App\Models\AiContentReview;
use App\Models\AiContentSchedule;
use App\Models\Post;
use App\Models\SeoMetadata;
use App\Models\User;
use App\Services\Social\PostService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiPublishingAndSchedulerService
{
    public function __construct(
        private readonly PostService $postService,
        private readonly AiAutomationControlService $control,
        private readonly AiContentQualityService $qualityService,
    ) {}

    public function updateDraftContent(AiContentDraft $draft, string $title, string $body, ?string $seoTitle, ?string $seoDescription, User $editor): AiContentDraft
    {
        $wasApproved = in_array($draft->status, [AiDraftStatus::Approved, AiDraftStatus::Scheduled], true);

        $draft->forceFill([
            'title' => trim(strip_tags($title)),
            'body' => trim(strip_tags($body)),
            'seo_title' => $seoTitle !== null && trim($seoTitle) !== '' ? trim(strip_tags($seoTitle)) : null,
            'seo_description' => $seoDescription !== null && trim($seoDescription) !== '' ? trim(strip_tags($seoDescription)) : null,
        ]);

        $quality = $this->qualityService->evaluateDraft($draft);
        $draft->forceFill([
            'content_hash' => $quality['content_hash'],
            'quality_status' => $quality['status'],
            'quality_score' => $quality['score'],
            'quality_report' => $quality,
            // Editing an approved or scheduled draft MUST require fresh review before publishing (PHẦN 14)
            'status' => $wasApproved ? AiDraftStatus::PendingReview : $draft->status,
            'reviewed_by' => $wasApproved ? null : $draft->reviewed_by,
            'reviewed_at' => $wasApproved ? null : $draft->reviewed_at,
        ])->save();

        if ($wasApproved) {
            AiContentSchedule::query()
                ->where('draft_id', $draft->id)
                ->where('status', 'scheduled')
                ->update(['status' => 'cancelled', 'last_error' => 'Draft edited after approval; re-approval required.']);
        }

        $this->control->logActivity('ai.draft.edited', $editor, $draft->agent_id, AiContentDraft::class, $draft->id, [
            'reset_approval' => $wasApproved,
        ]);

        return $draft;
    }

    public function reviewDraft(AiContentDraft $draft, string $decision, ?string $notes, User $reviewer): AiContentDraft
    {
        if (! in_array($decision, ['approved', 'rejected', 'archived'], true)) {
            throw ValidationException::withMessages(['decision' => [__('Invalid review decision.')]]);
        }

        if ($decision === 'approved' && $draft->quality_status === 'failed') {
            throw ValidationException::withMessages([
                'decision' => [__('Draft failed the Content Quality Gate. Edit and fix quality issues before approving.')],
            ]);
        }

        $nextStatus = match ($decision) {
            'approved' => AiDraftStatus::Approved,
            'rejected' => AiDraftStatus::Rejected,
            default => AiDraftStatus::Archived,
        };

        $draft->forceFill([
            'status' => $nextStatus,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        AiContentReview::query()->create([
            'draft_id' => $draft->id,
            'reviewer_id' => $reviewer->id,
            'decision' => $decision,
            'notes' => $notes,
            'draft_content_hash' => $draft->content_hash,
            'created_at' => now(),
        ]);

        $this->control->logActivity("ai.draft.{$decision}", $reviewer, $draft->agent_id, AiContentDraft::class, $draft->id, [
            'notes' => $notes,
        ]);

        return $draft;
    }

    public function scheduleApprovedDraft(AiContentDraft $draft, CarbonInterface $scheduledFor, string $timezone, User $actor): AiContentSchedule
    {
        if ($draft->status !== AiDraftStatus::Approved) {
            throw ValidationException::withMessages([
                'draft' => [__('Only human-approved AI drafts can be scheduled for publication.')],
            ]);
        }

        $schedule = DB::transaction(static function () use ($draft, $scheduledFor, $timezone): AiContentSchedule {
            AiContentSchedule::query()
                ->where('draft_id', $draft->id)
                ->where('status', 'scheduled')
                ->delete();

            $sched = AiContentSchedule::query()->create([
                'draft_id' => $draft->id,
                'agent_id' => $draft->agent_id,
                'scheduled_for' => $scheduledFor,
                'timezone' => $timezone ?: 'UTC',
                'status' => 'scheduled',
            ]);

            $draft->forceFill([
                'status' => AiDraftStatus::Scheduled,
                'scheduled_for' => $scheduledFor,
            ])->save();

            return $sched;
        });

        $this->control->logActivity('ai.draft.scheduled', $actor, $draft->agent_id, AiContentDraft::class, $draft->id, [
            'scheduled_for' => $scheduledFor->toIso8601String(),
        ]);

        return $schedule;
    }

    /**
     * Publish an approved AI draft cleanly through the existing PostService with strict DB row locking & idempotency.
     * - NEVER publishes unapproved drafts.
     * - NEVER creates duplicate posts on retry.
     * - Checks Emergency Stop & Agent active state at publish time.
     */
    public function publishApprovedDraft(AiContentDraft $draft, ?User $actor = null, bool $isScheduledJob = false): Post
    {
        if ($isScheduledJob) {
            $this->control->assertNotStopped('ai_publishing_enabled');
        } else {
            $this->control->assertNotStopped(null);
        }

        return DB::transaction(function () use ($draft, $actor): Post {
            /** @var AiContentDraft $locked */
            $locked = AiContentDraft::query()->with(['agent.user'])->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            // Idempotency guard: if already published, return the existing Post without duplicating!
            if ($locked->published_post_id !== null) {
                $existingPost = Post::withTrashed()->find($locked->published_post_id);
                if ($existingPost !== null) {
                    return $existingPost;
                }
            }

            if (! in_array($locked->status, [AiDraftStatus::Approved, AiDraftStatus::Scheduled], true)) {
                throw ValidationException::withMessages([
                    'draft' => [__('Unapproved AI drafts cannot be published. Human approval is required.')],
                ]);
            }

            if ($locked->agent === null || ! $locked->agent->is_enabled || $locked->agent->user === null) {
                throw ValidationException::withMessages([
                    'agent' => [__('AI Agent is disabled or missing its official user account.')],
                ]);
            }

            // Enforce daily published posts limit
            $maxPubDay = max(1, $this->control->int('max_published_posts_per_day', 5));
            $publishedToday = Post::query()
                ->where('content_origin', ContentOrigin::AiGenerated->value)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();

            if ($publishedToday >= $maxPubDay) {
                throw ValidationException::withMessages([
                    'draft' => [__('Daily published AI posts limit (:max) reached.', ['max' => $maxPubDay])],
                ]);
            }

            // Append clean hashtags from suggested_tags so existing HashtagService attaches them automatically
            $bodyWithTags = $locked->body;
            if (is_array($locked->suggested_tags) && $locked->suggested_tags !== []) {
                $tagLine = implode(' ', array_map(static fn (string $t): string => '#'.ltrim($t, '#'), array_slice($locked->suggested_tags, 0, 5)));
                if (! str_contains($bodyWithTags, '#')) {
                    $bodyWithTags .= "\n\n".$tagLine;
                }
            }

            $post = $this->postService->create($locked->agent->user, [
                'title' => $locked->title,
                'content' => $bodyWithTags,
                'community_id' => $locked->community_id,
                'category' => $locked->category,
                'visibility' => 'public',
            ]);

            $post->forceFill([
                'language_code' => $locked->language_code,
                'is_ai_assisted' => true,
                'content_origin' => ContentOrigin::AiGenerated,
                'ai_agent_id' => $locked->agent_id,
                'ai_draft_id' => $locked->id,
            ])->saveQuietly();

            if ($locked->seo_title !== null || $locked->seo_description !== null) {
                SeoMetadata::query()->updateOrCreate(
                    [
                        'entity_type' => SeoEntityType::Post->value,
                        'entity_id' => $post->id,
                        'locale' => $locked->language_code,
                    ],
                    [
                        'title' => $locked->seo_title,
                        'description' => $locked->seo_description,
                        'updated_by' => $actor !== null ? $actor->id : $locked->reviewed_by,
                    ],
                );
            }

            $locked->forceFill([
                'published_post_id' => $post->id,
                'status' => AiDraftStatus::Published,
                'published_at' => now(),
            ])->save();

            AiContentSchedule::query()
                ->where('draft_id', $locked->id)
                ->where('status', 'scheduled')
                ->update([
                    'status' => 'published',
                    'published_post_id' => $post->id,
                    'executed_at' => now(),
                ]);

            DB::afterCommit(static function () use ($post): void {
                ProcessSeoEntityJob::dispatch(SeoEntityType::Post->value, $post->id);
            });

            $this->control->logActivity('ai.draft.published', $actor, $locked->agent_id, Post::class, $post->id, [
                'draft_id' => $locked->id,
                'language' => $locked->language_code,
            ]);

            return $post;
        });
    }

    /**
     * Execute due scheduled posts strictly respecting Emergency Stop, daily caps, and anti-flood guard
     * (skips stale schedules older than 24h after downtime so backlog never dumps all at once!).
     *
     * @return array{published: int, skipped: int, failed: int}
     */
    public function processDueSchedules(int $batchLimit = 2): array
    {
        if (! $this->control->isSwitchEnabled('ai_publishing_enabled')) {
            return ['published' => 0, 'skipped' => 0, 'failed' => 0];
        }

        // Expire stale overdue schedules (> 24h old) instead of flooding the feed after an outage or Emergency Stop
        $staleCutoff = now()->subHours(24);
        $skipped = AiContentSchedule::query()
            ->where('status', 'scheduled')
            ->where('scheduled_for', '<', $staleCutoff)
            ->update([
                'status' => 'expired_skipped',
                'last_error' => 'Skipped automatically to prevent backlog burst after pause/outage.',
            ]);

        $due = AiContentSchedule::query()
            ->with(['draft', 'agent'])
            ->where('status', 'scheduled')
            ->where('scheduled_for', '<=', now())
            ->where('scheduled_for', '>=', $staleCutoff)
            ->orderBy('scheduled_for')
            ->limit(max(1, min(5, $batchLimit)))
            ->get();

        $published = 0;
        $failed = 0;

        foreach ($due as $schedule) {
            if ($this->control->isEmergencyStopped()) {
                break;
            }

            if ($schedule->draft === null || $schedule->draft->status !== AiDraftStatus::Scheduled) {
                $schedule->forceFill(['status' => 'cancelled'])->save();

                continue;
            }

            try {
                $this->publishApprovedDraft($schedule->draft, null, true);
                $published++;
            } catch (\Throwable $e) {
                $schedule->forceFill([
                    'attempts' => $schedule->attempts + 1,
                    'status' => $schedule->attempts + 1 >= 3 ? 'failed' : 'scheduled',
                    'last_error' => $e->getMessage(),
                ])->save();
                $failed++;
            }
        }

        return ['published' => $published, 'skipped' => $skipped, 'failed' => $failed];
    }
}
