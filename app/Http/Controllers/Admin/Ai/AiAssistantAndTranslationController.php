<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\AiModerationAssessment;
use App\Models\AiReplyTask;
use App\Models\Comment;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\User;
use App\Services\Ai\AiAutomationControlService;
use App\Services\Ai\AiCommunityAssistantService;
use App\Services\Ai\AiSourceResearchService;
use App\Services\Ai\AiTranslationWorkflowService;
use App\Services\System\LanguageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiAssistantAndTranslationController extends Controller
{
    public function __construct(
        private readonly AiAutomationControlService $control,
        private readonly AiTranslationWorkflowService $translationService,
        private readonly AiCommunityAssistantService $assistantService,
        private readonly AiSourceResearchService $researchService,
        private readonly LanguageService $languages,
    ) {}

    public function index(): Response
    {
        $recentPosts = Post::query()
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->with('author:id,name,username,account_type')
            ->latest('id')
            ->limit(30)
            ->get(['id', 'user_id', 'title', 'language_code', 'content_origin', 'created_at'])
            ->map(static fn (Post $p): array => [
                'id' => $p->id,
                'title' => $p->title ?: ('Post #'.$p->id),
                'language_code' => $p->language_code,
                'content_origin' => $p->content_origin->value,
                'author_username' => $p->author?->username,
                'author_is_ai' => $p->author !== null && $p->author->isAiAgent(),
                'created_at' => $p->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $aiTranslations = SeoContentTranslation::query()
            ->where('is_ai_assisted', true)
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(static fn (SeoContentTranslation $t): array => [
                'id' => $t->id,
                'entity_type' => $t->entity_type,
                'entity_id' => $t->entity_id,
                'source_locale' => $t->original_language,
                'target_locale' => $t->target_locale,
                'translated_title' => $t->translated_title,
                'localized_slug' => $t->localized_slug,
                'status' => $t->publication_status->value,
                'review_status' => $t->review_status->value,
                'provider' => $t->provider,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $replyTasks = AiReplyTask::query()
            ->with(['post:id,title', 'agent:id,display_name,username', 'reviewer:id,username'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(static fn (AiReplyTask $r): array => [
                'id' => $r->id,
                'post_id' => $r->post_id,
                'post_title' => $r->post?->title ?: ('Post #'.$r->post_id),
                'agent_name' => $r->agent?->display_name,
                'mode' => $r->mode,
                'language_code' => $r->language_code,
                'draft_reply' => $r->draft_reply,
                'status' => $r->status,
                'published_comment_id' => $r->published_comment_id,
                'reviewer_username' => $r->reviewer?->username,
                'error_message' => $r->error_message,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $assessments = AiModerationAssessment::query()
            ->with('reviewer:id,username')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(static fn (AiModerationAssessment $a): array => [
                'id' => $a->id,
                'entity_type' => $a->entity_type,
                'entity_id' => $a->entity_id,
                'risk_category' => $a->risk_category,
                'risk_level' => $a->risk_level,
                'reason' => $a->reason,
                'suggested_action' => $a->suggested_action,
                'review_required' => $a->review_required,
                'status' => $a->status,
                'reviewer_username' => $a->reviewer?->username,
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Ai/AssistAndTranslation', [
            'control' => $this->control->summaryPayload(),
            'recentPosts' => $recentPosts,
            'aiTranslations' => $aiTranslations,
            'seoOpportunities' => $this->researchService->seoOpportunities(15),
            'replyTasks' => $replyTasks,
            'assessments' => $assessments,
            'agents' => AiAgent::query()->where('is_enabled', true)->orderBy('display_name')->get(['id', 'display_name', 'username']),
            'languages' => $this->languages->publicEnabledLanguages(),
        ]);
    }

    public function translatePost(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'post_id' => ['required', 'integer', 'exists:posts,id'],
            'target_locale' => ['required', 'string', 'max:12'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        /** @var Post $post */
        $post = Post::query()->findOrFail((int) $validated['post_id']);

        $translation = $this->translationService->translatePostToLocale($post, $validated['target_locale'], $actor, true);

        return back()->with('status', __('AI translation draft saved to SEO V2.0 International Center (:locale, status: :status). Publish from SEO International after human review.', [
            'locale' => $translation->target_locale,
            'status' => $translation->publication_status->value,
        ]));
    }

    public function generateReplyDraft(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'post_id' => ['required', 'integer', 'exists:posts,id'],
            'agent_id' => ['required', 'integer', 'exists:ai_agents,id'],
            'mode' => ['nullable', Rule::in(['manual_draft', 'mention_trigger', 'scheduled_assist'])],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        /** @var Post $post */
        $post = Post::query()->findOrFail((int) $validated['post_id']);
        /** @var AiAgent $agent */
        $agent = AiAgent::query()->findOrFail((int) $validated['agent_id']);

        $task = $this->assistantService->createReplyDraft($post, $agent, $actor, $validated['mode'] ?? 'manual_draft');

        if ($task->status === 'failed') {
            return back()->withErrors(['post_id' => $task->error_message ?: __('Failed to draft AI reply.')]);
        }

        return back()->with('status', __('AI comment reply draft generated and queued for human approval.'));
    }

    public function approveReply(Request $request, AiReplyTask $task): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $comment = $this->assistantService->approveAndPublishReply($task, $actor);

        return back()->with('status', __('AI reply approved and published as Comment #:id with transparent AI badge.', ['id' => $comment->id]));
    }

    public function rejectReply(Request $request, AiReplyTask $task): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $task->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ])->save();

        $this->control->logActivity('ai.reply.rejected', $actor, $task->agent_id, AiReplyTask::class, $task->id);

        return back()->with('status', __('AI reply draft rejected.'));
    }

    public function assessModeration(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', Rule::in(['post', 'comment'])],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        if ($validated['entity_type'] === 'post') {
            /** @var Post $post */
            $post = Post::query()->findOrFail((int) $validated['entity_id']);
            $content = trim((string) ($post->title ?? '')."\n\n".$post->content);
        } else {
            /** @var Comment $comment */
            $comment = Comment::query()->findOrFail((int) $validated['entity_id']);
            $content = $comment->content;
        }

        $assessment = $this->assistantService->assessContentModeration(
            $validated['entity_type'],
            (int) $validated['entity_id'],
            $content,
            $actor,
        );

        return back()->with('status', __('AI Moderation advisory completed: :level risk (:category). Human moderator decision required.', [
            'level' => strtoupper($assessment->risk_level),
            'category' => $assessment->risk_category,
        ]));
    }

    public function resolveAssessment(Request $request, AiModerationAssessment $assessment): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['acknowledged', 'dismissed'])],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $assessment->forceFill([
            'status' => $validated['status'],
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ])->save();

        $this->control->logActivity('ai.moderation.resolved', $actor, null, AiModerationAssessment::class, $assessment->id, [
            'status' => $validated['status'],
        ]);

        return back()->with('status', __('AI moderation assessment marked as :status.', ['status' => $validated['status']]));
    }
}
