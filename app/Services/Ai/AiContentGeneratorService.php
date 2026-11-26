<?php

namespace App\Services\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AiModelGroup;
use App\Enums\AiPromptType;
use App\Enums\PostCategory;
use App\Models\AiAgent;
use App\Models\AiContentDraft;
use App\Models\AiContentPlan;
use App\Models\AiContentSource;
use App\Models\AiPromptTemplate;
use App\Models\AiPromptVersion;
use App\Models\Community;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiContentGeneratorService
{
    public function __construct(
        private readonly AiAutomationControlService $control,
        private readonly AiProviderManager $providerManager,
        private readonly AiPromptService $promptService,
        private readonly AiContentQualityService $qualityService,
    ) {}

    /**
     * Structured Output JSON Schema enforced via Gemini responseJsonSchema.
     *
     * @return array<string, mixed>
     */
    public static function postOutputSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'title' => ['type' => 'STRING'],
                'body' => ['type' => 'STRING'],
                'summary' => ['type' => 'STRING'],
                'language' => ['type' => 'STRING'],
                'content_type' => ['type' => 'STRING'],
                'suggested_tags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'seo_title' => ['type' => 'STRING'],
                'seo_description' => ['type' => 'STRING'],
                'factual_claims' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'review_flags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['title', 'body', 'summary', 'language'],
        ];
    }

    /**
     * Generate a new AI Draft strictly governed by Emergency Stop, daily limits, prompt template,
     * Gemini Structured Output, and Quality Gate. Always lands in Draft / Pending Review — NEVER auto-published unapproved!
     *
     * @param  array{
     *     agent_id: int,
     *     topic: string,
     *     language_code?: string,
     *     content_type?: string,
     *     category?: string,
     *     community_id?: int|null,
     *     plan_id?: int|null,
     *     source_id?: int|null,
     *     prompt_template_id?: int|null,
     *     seo_context?: string|null,
     *     manual_trigger?: bool
     * }  $input
     */
    public function generateDraft(array $input, ?User $actor = null): AiContentDraft
    {
        $manual = (bool) ($input['manual_trigger'] ?? ($actor !== null));
        $this->control->assertNotStopped($manual ? null : 'ai_generation_enabled');

        // Enforce global daily draft generation cap
        $maxDraftsPerDay = max(1, $this->control->int('max_generated_drafts_per_day', 10));
        $todayDrafts = AiContentDraft::query()->where('created_at', '>=', now()->startOfDay())->count();
        if ($todayDrafts >= $maxDraftsPerDay) {
            throw ValidationException::withMessages([
                'topic' => [__('Daily AI draft generation limit (:max) reached.', ['max' => $maxDraftsPerDay])],
            ]);
        }

        /** @var AiAgent $agent */
        $agent = AiAgent::query()->findOrFail($input['agent_id']);
        if (! $agent->is_enabled) {
            throw ValidationException::withMessages([
                'agent_id' => [__('Selected AI Agent is currently disabled.')],
            ]);
        }

        $topic = trim($input['topic']);
        $language = trim((string) ($input['language_code'] ?? $agent->default_language));
        $contentType = trim((string) ($input['content_type'] ?? 'knowledge'));
        $catEnum = PostCategory::tryFrom((string) ($input['category'] ?? 'technology'));
        $category = $catEnum !== null ? $catEnum->value : PostCategory::Technology->value;

        // Enforce restricted topics guardrail
        if (is_array($agent->restricted_topics)) {
            foreach ($agent->restricted_topics as $restricted) {
                if (trim($restricted) !== '' && stripos($topic, trim($restricted)) !== false) {
                    throw ValidationException::withMessages([
                        'topic' => [__('This topic matches a restricted topic rule for the selected AI Agent.')],
                    ]);
                }
            }
        }

        $community = isset($input['community_id']) ? Community::query()->find((int) $input['community_id']) : null;
        $source = isset($input['source_id']) ? AiContentSource::query()->find((int) $input['source_id']) : null;

        /** @var AiPromptTemplate|null $template */
        $template = isset($input['prompt_template_id'])
            ? AiPromptTemplate::query()->find((int) $input['prompt_template_id'])
            : AiPromptTemplate::query()
                ->where('is_enabled', true)
                ->where(static function ($q) use ($agent): void {
                    $q->where('agent_id', $agent->id)->orWhereNull('agent_id');
                })
                ->where('prompt_type', AiPromptType::GeneratePost->value)
                ->orderByDesc('agent_id')
                ->first();

        $versionNumber = $template !== null && $template->active_version_id !== null
            ? (int) (AiPromptVersion::query()->whereKey($template->active_version_id)->value('version_number') ?? 1)
            : 1;

        $variables = [
            'agent_name' => $agent->display_name,
            'agent_expertise' => (string) ($agent->expertise ?? 'Technology & Community Knowledge'),
            'topic' => $topic,
            'target_language' => $language,
            'community_name' => $community !== null ? $community->name : 'General Feed',
            'content_type' => $contentType,
            'writing_style' => (string) ($agent->writing_style ?? 'Clear, factual, and helpful'),
            'maximum_length' => '2400',
            'current_date' => now()->toDateString(),
            'seo_context' => trim((string) ($input['seo_context'] ?? '')),
            'source_material' => $source !== null
                ? "Title: {$source->title}\nURL: {$source->url}\nExcerpt: ".(string) ($source->excerpt ?? '')
                : 'No external breaking news source provided; focus on evergreen verified knowledge.',
        ];

        $rendered = $template !== null
            ? $this->promptService->render($template, $variables)
            : [
                'system_instruction' => $agent->system_prompt,
                'user_prompt' => "Write an original {$contentType} post in {$language} about: {$topic}.",
            ];

        // Combine Agent system prompt + template system instruction cleanly
        $systemInstruction = trim($agent->system_prompt."\n\n".$rendered['system_instruction']);

        $draft = AiContentDraft::query()->create([
            'agent_id' => $agent->id,
            'plan_id' => $input['plan_id'] ?? null,
            'community_id' => $community?->id,
            'prompt_template_id' => $template?->id,
            'prompt_version_number' => $versionNumber,
            'source_id' => $source?->id,
            'title' => Str::limit($topic, 240, ''),
            'body' => '',
            'language_code' => $language,
            'content_type' => $contentType,
            'category' => $category,
            'status' => AiDraftStatus::Generating,
            'idempotency_key' => hash('sha256', implode('|', [$agent->id, $topic, $language, now()->format('Y-m-d-H'), Str::random(8)])),
        ]);

        $exec = $this->providerManager->execute(
            taskType: 'generate_post',
            payload: [
                'system_instruction' => $systemInstruction,
                'user_prompt' => $rendered['user_prompt'],
                'max_output_tokens' => 2048,
                'json_schema' => self::postOutputSchema(),
            ],
            preferredModelId: $agent->preferred_model_id,
            fallbackGroup: AiModelGroup::Fast,
            agentId: $agent->id,
            languageCode: $language,
        );

        $result = $exec['result'];
        $model = $exec['model'];
        $credential = $exec['credential'];

        if (! $result->ok) {
            $draft->forceFill([
                'model_id' => $model?->id,
                'credential_id' => $credential?->id,
                'status' => $result->isWaitingForQuota ? AiDraftStatus::WaitingForQuota : AiDraftStatus::Failed,
                'error_message' => $result->errorMessage,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'thinking_tokens' => $result->thinkingTokens,
            ])->save();

            return $draft;
        }

        // Strict backend validation & sanitization of AI Structured Output
        $data = is_array($result->structuredData) ? $result->structuredData : [];
        $title = is_string($data['title'] ?? null) && trim($data['title']) !== ''
            ? Str::limit(trim(strip_tags($data['title'])), 240, '')
            : Str::limit($topic, 240, '');
        $body = is_string($data['body'] ?? null) && trim($data['body']) !== ''
            ? trim(strip_tags($data['body']))
            : trim(strip_tags($result->text));
        $summary = is_string($data['summary'] ?? null) ? Str::limit(trim(strip_tags($data['summary'])), 480, '') : Str::limit($body, 220);

        $tags = [];
        if (is_array($data['suggested_tags'] ?? null)) {
            foreach (array_slice($data['suggested_tags'], 0, 6) as $t) {
                if (is_string($t) && trim($t) !== '') {
                    $cleanTag = Str::slug(ltrim(trim($t), '#'), '');
                    if ($cleanTag !== '') {
                        $tags[] = $cleanTag;
                    }
                }
            }
        }

        $sourceRefs = [];
        if ($source !== null) {
            $sourceRefs[] = ['title' => $source->title, 'url' => $source->url, 'publisher' => $source->publisher];
        }

        $draft->forceFill([
            'model_id' => $model?->id,
            'credential_id' => $credential?->id,
            'title' => $title,
            'body' => $body,
            'summary' => $summary,
            'suggested_tags' => array_values(array_unique($tags)),
            'seo_title' => is_string($data['seo_title'] ?? null) ? Str::limit(trim(strip_tags($data['seo_title'])), 160, '') : Str::limit($title, 110, ''),
            'seo_description' => is_string($data['seo_description'] ?? null) ? Str::limit(trim(strip_tags($data['seo_description'])), 300, '') : Str::limit($summary, 160, ''),
            'source_references' => $sourceRefs,
            'factual_claims' => is_array($data['factual_claims'] ?? null) ? array_values(array_filter($data['factual_claims'], 'is_string')) : [],
            'review_flags' => is_array($data['review_flags'] ?? null) ? array_values(array_filter($data['review_flags'], 'is_string')) : [],
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'thinking_tokens' => $result->thinkingTokens,
            'estimated_cost_micro_usd' => $model !== null ? $model->estimateCostMicroUsd($result->inputTokens, $result->outputTokens + $result->thinkingTokens) : 0,
            'error_message' => null,
        ]);

        $quality = $this->qualityService->evaluateDraft($draft);
        $draft->forceFill([
            'content_hash' => $quality['content_hash'],
            'quality_status' => $quality['status'],
            'quality_score' => $quality['score'],
            'quality_report' => $quality,
            'status' => AiDraftStatus::PendingReview,
        ])->save();

        if (isset($input['plan_id'])) {
            AiContentPlan::query()->whereKey((int) $input['plan_id'])->update(['last_generated_at' => now()]);
        }

        $this->control->logActivity('ai.draft.generated', $actor, $agent->id, AiContentDraft::class, $draft->id, [
            'model' => $model?->model_id,
            'language' => $language,
            'quality_status' => $quality['status'],
            'quality_score' => $quality['score'],
        ]);

        return $draft;
    }
}
