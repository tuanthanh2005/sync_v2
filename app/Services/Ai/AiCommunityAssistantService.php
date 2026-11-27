<?php

namespace App\Services\Ai;

use App\Enums\AccountType;
use App\Enums\AiModelGroup;
use App\Enums\AiPromptType;
use App\Models\AiAgent;
use App\Models\AiModerationAssessment;
use App\Models\AiPromptTemplate;
use App\Models\AiReplyTask;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Social\CommentService;
use App\Services\Social\PostViewTrackingService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommunityAssistantService
{
    public function __construct(
        private readonly AiAutomationControlService $control,
        private readonly AiProviderManager $providerManager,
        private readonly AiPromptService $promptService,
        private readonly CommentService $commentService,
        private readonly PostViewTrackingService $viewTrackingService,
    ) {}

    /**
     * Generate a helpful AI reply draft for a public post.
     * Strictly enforces:
     * - Compliance verification (`compliance_user_facing_verified` when automated)
     * - Never creates fake AI-to-AI conversations (rejects replying to another AI Agent's post)
     * - Respects UserBlock between post author and AI Agent
     * - Enforces daily & per-post reply caps.
     */
    public function createReplyDraft(Post $post, AiAgent $agent, User $actor, string $mode = 'manual_draft'): AiReplyTask
    {
        $this->control->assertNotStopped($mode === 'manual_draft' ? null : 'ai_replies_enabled');

        if ($mode !== 'manual_draft' && ! $this->control->bool('compliance_user_facing_verified', false)) {
            throw ValidationException::withMessages([
                'compliance' => [__('Automated user-facing AI replies require compliance review confirmation before activation.')],
            ]);
        }

        if (! $agent->is_enabled || $agent->user === null) {
            throw ValidationException::withMessages(['agent_id' => [__('AI Agent is disabled.')]]);
        }

        // Prevent fake AI-to-AI conversations (PHẦN 18)
        $post->loadMissing('author');
        if ($post->author !== null && $post->author->account_type === AccountType::AiAgent) {
            throw ValidationException::withMessages([
                'post_id' => [__('AI Agents are strictly prohibited from generating conversations on other AI Agents\' posts.')],
            ]);
        }

        // Respect member blocks against the AI Agent (PHẦN 21)
        if ($post->author !== null) {
            $blocked = UserBlock::query()
                ->where('blocker_id', $post->author->id)
                ->where('blocked_id', $agent->user_id)
                ->exists();
            if ($blocked) {
                throw ValidationException::withMessages([
                    'post_id' => [__('The post author has blocked this AI Agent.')],
                ]);
            }
        }

        $maxPerDay = max(1, $this->control->int('max_ai_replies_per_day', 5));
        $todayReplies = AiReplyTask::query()
            ->where('created_at', '>=', now()->startOfDay())
            ->whereNotIn('status', ['rejected', 'failed'])
            ->count();

        if ($todayReplies >= $maxPerDay) {
            throw ValidationException::withMessages([
                'post_id' => [__('Daily AI reply limit (:max) reached.', ['max' => $maxPerDay])],
            ]);
        }

        $maxPerPost = max(1, $this->control->int('max_ai_replies_per_post', 2));
        $postReplies = AiReplyTask::query()
            ->where('post_id', $post->id)
            ->whereNotIn('status', ['rejected', 'failed'])
            ->count();

        if ($postReplies >= $maxPerPost) {
            throw ValidationException::withMessages([
                'post_id' => [__('Maximum AI replies per post (:max) reached.', ['max' => $maxPerPost])],
            ]);
        }

        $lang = $post->language_code ?: $agent->default_language;
        $template = AiPromptTemplate::query()
            ->where('prompt_type', AiPromptType::GenerateReply->value)
            ->where('is_enabled', true)
            ->first();

        $context = trim((string) ($post->title ?? '')."\n".$post->content);
        $rendered = $template !== null
            ? $this->promptService->render($template, [
                'agent_name' => $agent->display_name,
                'target_language' => $lang,
                'source_material' => $context,
            ])
            : [
                'system_instruction' => $agent->system_prompt,
                'user_prompt' => $this->promptService->sanitizeUntrustedData($context, 3000),
            ];

        $exec = $this->providerManager->execute(
            taskType: 'generate_reply',
            payload: [
                'system_instruction' => trim($agent->system_prompt."\n\n".$rendered['system_instruction']),
                'user_prompt' => $rendered['user_prompt'],
                'max_output_tokens' => 600,
            ],
            preferredModelId: $agent->preferred_model_id,
            fallbackGroup: AiModelGroup::LowCost,
            agentId: $agent->id,
            languageCode: $lang,
        );

        $res = $exec['result'];

        if ($res->ok) {
            $this->viewTrackingService->recordAiContentAccess($post, $agent, 'ai_reply_draft');
        }

        $task = AiReplyTask::query()->create([
            'post_id' => $post->id,
            'agent_id' => $agent->id,
            'triggered_by_user_id' => $actor->id,
            'mode' => $mode,
            'language_code' => $lang,
            'draft_reply' => $res->ok ? Str::limit(trim(strip_tags($res->text)), 1400, '') : null,
            'status' => $res->ok ? 'pending_review' : 'failed',
            'error_message' => $res->ok ? null : $res->errorMessage,
        ]);

        $this->control->logActivity('ai.reply.drafted', $actor, $agent->id, AiReplyTask::class, $task->id, [
            'post_id' => $post->id,
            'status' => $task->status,
        ]);

        return $task;
    }

    /**
     * Approve & publish an AI Reply Draft via existing CommentService (explicitly tagged as AI-generated).
     */
    public function approveAndPublishReply(AiReplyTask $task, User $reviewer): Comment
    {
        $this->control->assertNotStopped(null);

        if ($task->published_comment_id !== null) {
            /** @var Comment $existing */
            $existing = Comment::query()->findOrFail($task->published_comment_id);

            return $existing;
        }

        if (! is_string($task->draft_reply) || trim($task->draft_reply) === '') {
            throw ValidationException::withMessages(['draft_reply' => [__('Reply draft is empty.')]]);
        }

        $task->loadMissing(['post', 'agent.user']);
        if ($task->post === null || $task->agent === null || $task->agent->user === null || ! $task->agent->is_enabled) {
            throw ValidationException::withMessages(['task' => [__('Target post or AI Agent is unavailable.')]]);
        }

        $comment = $this->commentService->create($task->agent->user, $task->post, [
            'content' => trim(strip_tags($task->draft_reply)),
            'parent_id' => $task->parent_comment_id,
        ]);

        $comment->forceFill([
            'is_ai_generated' => true,
            'ai_agent_id' => $task->agent_id,
        ])->saveQuietly();

        $task->forceFill([
            'published_comment_id' => $comment->id,
            'status' => 'published',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        $this->control->logActivity('ai.reply.published', $reviewer, $task->agent_id, Comment::class, $comment->id, [
            'task_id' => $task->id,
            'post_id' => $task->post_id,
        ]);

        return $comment;
    }

    /**
     * AI-assisted Content Moderation Assessment — advisory ONLY (NEVER auto-bans users or touches payments!).
     */
    public function assessContentModeration(string $entityType, int $entityId, string $untrustedContent, User $actor, ?int $reportId = null): AiModerationAssessment
    {
        $this->control->assertNotStopped(null);

        $template = AiPromptTemplate::query()
            ->where('prompt_type', AiPromptType::ContentModeration->value)
            ->where('is_enabled', true)
            ->first();

        $sanitized = $this->promptService->sanitizeUntrustedData($untrustedContent, 4000);
        $rendered = $template !== null
            ? $this->promptService->render($template, ['source_material' => $sanitized])
            : [
                'system_instruction' => 'Classify content safety risk. Return JSON only. Never recommend permanent auto-bans.',
                'user_prompt' => $sanitized,
            ];

        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'risk_category' => ['type' => 'STRING'],
                'risk_level' => ['type' => 'STRING'],
                'reason' => ['type' => 'STRING'],
                'suggested_action' => ['type' => 'STRING'],
                'review_required' => ['type' => 'BOOLEAN'],
            ],
            'required' => ['risk_category', 'risk_level', 'reason', 'suggested_action'],
        ];

        $exec = $this->providerManager->execute(
            taskType: 'content_moderation',
            payload: [
                'system_instruction' => $rendered['system_instruction'],
                'user_prompt' => $rendered['user_prompt'],
                'max_output_tokens' => 400,
                'json_schema' => $schema,
            ],
            preferredModelId: null,
            fallbackGroup: AiModelGroup::LowCost,
        );

        $res = $exec['result'];
        if (! $res->ok) {
            throw ValidationException::withMessages([
                'content' => [__('AI moderation assistant unavailable: :err', ['err' => $res->errorMessage ?? 'Provider error'])],
            ]);
        }

        $data = is_array($res->structuredData) ? $res->structuredData : [];
        $rawRisk = strtolower((string) ($data['risk_level'] ?? 'low'));
        $riskLevel = in_array($rawRisk, ['low', 'medium', 'high', 'critical'], true)
            ? $rawRisk
            : 'medium';

        // Strictly allowlisted non-destructive advisory actions only (ZERO auto-ban!)
        $rawAction = strtolower((string) ($data['suggested_action'] ?? 'flag_for_review'));
        $safeAction = in_array($rawAction, ['allow', 'flag_for_review', 'temporarily_hold', 'escalate_to_moderator'], true)
            ? $rawAction
            : 'flag_for_review';

        $assessment = AiModerationAssessment::query()->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'report_id' => $reportId,
            'model_id' => $exec['model']?->id,
            'risk_category' => Str::limit(trim((string) ($data['risk_category'] ?? 'unspecified')), 44, ''),
            'risk_level' => $riskLevel,
            'reason' => Str::limit(trim(strip_tags((string) ($data['reason'] ?? 'AI moderation review suggested.'))), 1000, ''),
            'suggested_action' => $safeAction,
            'review_required' => true,
            'status' => 'pending_review',
        ]);

        $this->control->logActivity('ai.moderation.assessed', $actor, null, AiModerationAssessment::class, $assessment->id, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'risk_level' => $riskLevel,
            'suggested_action' => $safeAction,
        ]);

        return $assessment;
    }
}
