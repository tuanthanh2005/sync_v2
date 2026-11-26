<?php

namespace App\Services\Ai;

use App\Enums\AiModelGroup;
use App\Enums\AiPromptType;
use App\Models\AiPromptTemplate;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\User;
use App\Services\Seo\ContentTranslationService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiTranslationWorkflowService
{
    public function __construct(
        private readonly AiAutomationControlService $control,
        private readonly AiProviderManager $providerManager,
        private readonly AiPromptService $promptService,
        private readonly ContentTranslationService $seoTranslations,
    ) {}

    /**
     * Translate an approved public Post into a target locale via Gemini Structured Output
     * and store it inside SEO V2.0's `seo_content_translations` as Draft/InReview (never bypassing editorial review).
     */
    public function translatePostToLocale(Post $post, string $targetLocale, User $actor, bool $manual = true): SeoContentTranslation
    {
        $this->control->assertNotStopped($manual ? null : 'ai_translation_enabled');

        $maxPerDay = max(1, $this->control->int('max_translations_per_day', 10));
        $todayCount = SeoContentTranslation::query()
            ->where('is_ai_assisted', true)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($todayCount >= $maxPerDay) {
            throw ValidationException::withMessages([
                'target_locale' => [__('Daily AI translation limit (:max) reached.', ['max' => $maxPerDay])],
            ]);
        }

        /** @var AiPromptTemplate|null $template */
        $template = AiPromptTemplate::query()
            ->where('prompt_type', AiPromptType::TranslateContent->value)
            ->where('is_enabled', true)
            ->first();

        $sourceText = 'Title: '.(string) ($post->title ?? '')."\n\nBody:\n".$post->content;
        $rendered = $template !== null
            ? $this->promptService->render($template, [
                'target_language' => $targetLocale,
                'source_material' => $sourceText,
            ])
            : [
                'system_instruction' => "Translate accurately into {$targetLocale} preserving code blocks, URLs, numbers, and factual meaning.",
                'user_prompt' => $this->promptService->sanitizeUntrustedData($sourceText),
            ];

        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'translated_title' => ['type' => 'STRING'],
                'translated_body' => ['type' => 'STRING'],
                'translated_description' => ['type' => 'STRING'],
                'localized_slug' => ['type' => 'STRING'],
            ],
            'required' => ['translated_title', 'translated_body', 'translated_description'],
        ];

        $exec = $this->providerManager->execute(
            taskType: 'translate_content',
            payload: [
                'system_instruction' => $rendered['system_instruction'],
                'user_prompt' => $rendered['user_prompt'],
                'max_output_tokens' => 2048,
                'json_schema' => $schema,
            ],
            preferredModelId: null,
            fallbackGroup: AiModelGroup::LowCost,
            agentId: $post->ai_agent_id,
            languageCode: $targetLocale,
        );

        $res = $exec['result'];
        if (! $res->ok) {
            throw ValidationException::withMessages([
                'target_locale' => [__('AI translation failed: :error', ['error' => $res->errorMessage ?? 'Provider error'])],
            ]);
        }

        $data = is_array($res->structuredData) ? $res->structuredData : [];
        $body = is_string($data['translated_body'] ?? null) && trim($data['translated_body']) !== ''
            ? trim(strip_tags($data['translated_body']))
            : trim(strip_tags($res->text));
        $title = is_string($data['translated_title'] ?? null) && trim($data['translated_title']) !== ''
            ? trim(strip_tags($data['translated_title']))
            : $post->title;
        $description = is_string($data['translated_description'] ?? null)
            ? Str::limit(trim(strip_tags($data['translated_description'])), 480, '')
            : Str::limit($body, 160);
        $slug = is_string($data['localized_slug'] ?? null) && trim($data['localized_slug']) !== ''
            ? Str::slug(trim($data['localized_slug']))
            : null;

        $translation = $this->seoTranslations->upsertPostTranslation($post, [
            'target_locale' => $targetLocale,
            'translated_title' => $title,
            'translated_body' => $body,
            'translated_description' => $description,
            'localized_slug' => $slug,
            'provider' => 'gemini:'.($exec['model'] !== null ? $exec['model']->model_id : 'flash'),
            'is_ai_assisted' => true,
        ], $actor);

        $this->control->logActivity('ai.translation.generated', $actor, $post->ai_agent_id, SeoContentTranslation::class, $translation->id, [
            'post_id' => $post->id,
            'target_locale' => $targetLocale,
        ]);

        return $translation;
    }
}
