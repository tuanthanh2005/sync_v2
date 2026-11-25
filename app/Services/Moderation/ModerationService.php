<?php

namespace App\Services\Moderation;

use App\Enums\ModerationStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Comment;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\Social\PostService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModerationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PostService $postService,
    ) {}

    public function submitReport(
        User $reporter,
        string $targetType,
        int $targetId,
        ReportReason $reason,
        ?string $details = null,
    ): Report {
        /** @var class-string<Model>|null $modelClass */
        $modelClass = match ($targetType) {
            'post' => Post::class,
            'comment' => Comment::class,
            'user' => User::class,
            default => null,
        };

        if ($modelClass === null) {
            throw ValidationException::withMessages([
                'target_type' => [__('Invalid reportable target type.')],
            ]);
        }

        /** @var Model|null $target */
        $target = $modelClass::query()->find($targetId);

        if ($target === null) {
            throw ValidationException::withMessages([
                'target_id' => [__('The reported item could not be found.')],
            ]);
        }

        if ($target instanceof User && $target->id === $reporter->id) {
            throw ValidationException::withMessages([
                'target_id' => [__('You cannot report your own account.')],
            ]);
        }

        $duplicate = Report::query()
            ->where('reporter_id', $reporter->id)
            ->where('reportable_type', $modelClass)
            ->where('reportable_id', $targetId)
            ->where('status', ReportStatus::Pending->value)
            ->first();

        if ($duplicate !== null) {
            return $duplicate;
        }

        $report = new Report([
            'reportable_type' => $modelClass,
            'reportable_id' => $targetId,
            'reason' => $reason->value,
            'details' => $details !== null && trim($details) !== '' ? trim($details) : null,
        ]);
        $report->reporter_id = $reporter->id;
        $report->status = ReportStatus::Pending;
        $report->save();

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function queuePayload(): array
    {
        $reports = Report::query()
            ->with(['reporter:id,name,username', 'reviewer:id,username', 'reportable'])
            ->latest('id')
            ->limit(40)
            ->get();

        $logs = ModerationLog::query()
            ->with(['actor:id,username'])
            ->latest('id')
            ->limit(25)
            ->get();

        return [
            'reports' => array_values(
                $reports->map(static function (Report $report): array {
                    $excerpt = '';
                    $targetTypeLabel = 'Item';

                    if ($report->reportable instanceof Post) {
                        $targetTypeLabel = 'Post';
                        $excerpt = $report->reportable->title ?: mb_substr($report->reportable->content, 0, 120);
                    } elseif ($report->reportable instanceof Comment) {
                        $targetTypeLabel = 'Comment';
                        $excerpt = mb_substr($report->reportable->content, 0, 120);
                    } elseif ($report->reportable instanceof User) {
                        $targetTypeLabel = 'User';
                        $excerpt = "{$report->reportable->name} (@{$report->reportable->username})";
                    }

                    return [
                        'id' => $report->id,
                        'target_type' => $targetTypeLabel,
                        'target_id' => $report->reportable_id,
                        'target_excerpt' => $excerpt,
                        'reason' => $report->reason->value,
                        'reason_label' => $report->reason->label(),
                        'details' => $report->details,
                        'status' => $report->status->value,
                        'status_label' => $report->status->label(),
                        'priority' => $report->priority,
                        'assigned_to' => $report->assigned_to,
                        'internal_note' => $report->internal_note,
                        'reporter_username' => $report->reporter?->username,
                        'reviewer_username' => $report->reviewer?->username,
                        'resolution_note' => $report->resolution_note,
                        'created_at' => $report->created_at?->toIso8601String(),
                    ];
                })->all(),
            ),
            'logs' => array_values(
                $logs->map(static fn (ModerationLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actor_username' => $log->actor?->username,
                    'target_type' => class_basename($log->target_type),
                    'target_id' => $log->target_id,
                    'reason' => $log->reason,
                    'created_at' => $log->created_at->toIso8601String(),
                ])->all(),
            ),
        ];
    }

    public function resolveReport(
        User $moderator,
        Report $report,
        string $decision,
        ?string $note = null,
    ): void {
        DB::transaction(function () use ($moderator, $report, $decision, $note): void {
            /** @var Report $lockedReport */
            $lockedReport = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            $target = $lockedReport->reportable;
            $cleanNote = $note !== null && trim($note) !== '' ? trim($note) : null;

            if ($decision === 'dismiss') {
                $lockedReport->status = ReportStatus::Dismissed;
            } elseif ($decision === 'hide_content') {
                if ($target instanceof Post) {
                    $target->moderation_status = ModerationStatus::Hidden;
                    $target->moderation_reason = $cleanNote;
                    $target->moderated_by = $moderator->id;
                    $target->moderated_at = now();
                    $target->save();

                    $this->postService->stopCampaignsForPost(
                        $target,
                        __('Promoted post was hidden during moderation; remaining ad budget refunded.'),
                    );
                } elseif ($target instanceof Comment) {
                    $target->moderation_status = ModerationStatus::Hidden;
                    $target->moderation_reason = $cleanNote;
                    $target->moderated_by = $moderator->id;
                    $target->moderated_at = now();
                    $target->save();

                    $parentPost = Post::query()->whereKey($target->post_id)->first();
                    if ($parentPost !== null) {
                        $parentPost->comments_count = Comment::query()
                            ->where('post_id', $parentPost->id)
                            ->where('moderation_status', ModerationStatus::Approved->value)
                            ->count();
                        $parentPost->save();
                    }
                }

                $lockedReport->status = ReportStatus::Resolved;
            } elseif ($decision === 'suspend_user') {
                $targetUser = null;

                if ($target instanceof User) {
                    $targetUser = $target;
                } elseif ($target instanceof Post || $target instanceof Comment) {
                    $targetUser = $target->author;
                }

                if ($targetUser !== null) {
                    if ($targetUser->id === $moderator->id || $targetUser->role === UserRole::Admin) {
                        throw ValidationException::withMessages([
                            'decision' => [__('Cannot suspend an administrator or your own account.')],
                        ]);
                    }

                    $targetUser->status = UserStatus::Suspended;
                    $targetUser->save();
                }

                $lockedReport->status = ReportStatus::Resolved;
            } else {
                throw ValidationException::withMessages([
                    'decision' => [__('Unsupported moderation decision.')],
                ]);
            }

            $lockedReport->reviewed_by = $moderator->id;
            $lockedReport->reviewed_at = now();
            $lockedReport->resolution_note = $cleanNote;
            $lockedReport->save();

            ModerationLog::query()->create([
                'actor_id' => $moderator->id,
                'action' => "report.{$decision}",
                'target_type' => $lockedReport->reportable_type,
                'target_id' => $lockedReport->reportable_id,
                'reason' => $cleanNote,
            ]);

            $this->auditLogger->log(
                "moderation.{$decision}",
                $lockedReport,
                ['target_type' => class_basename($lockedReport->reportable_type), 'target_id' => $lockedReport->reportable_id],
                $moderator,
            );
        });
    }
}
