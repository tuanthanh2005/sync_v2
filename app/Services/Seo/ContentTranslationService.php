<?php

namespace App\Services\Seo;

use App\Enums\SeoEntityType;
use App\Enums\SeoTranslationStatus;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContentTranslationService
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly SeoQualityGateService $qualityGate,
        private readonly SeoRegistryService $registry,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Create or update a multilingual translation for a public Post.
     * Unapproved/draft translations are strictly excluded from search indexing.
     *
     * @param  array{
     *     target_locale: string,
     *     translated_title?: string|null,
     *     translated_body: string,
     *     translated_description?: string|null,
     *     localized_slug?: string|null,
     *     provider?: string|null,
     *     is_ai_assisted?: bool
     * }  $data
     */
    public function upsertPostTranslation(Post $post, array $data, User $actor): SeoContentTranslation
    {
        $enabled = $this->config->enabledLocales();
        $targetLocale = trim($data['target_locale']);

        if (! in_array($targetLocale, $enabled, true)) {
            throw ValidationException::withMessages([
                'target_locale' => [__('The selected target language is not enabled on this platform.')],
            ]);
        }

        $sourceLang = $post->language_code ?: $this->config->defaultLocale();
        if ($targetLocale === $sourceLang) {
            throw ValidationException::withMessages([
                'target_locale' => [__('Target language must differ from the original post language.')],
            ]);
        }

        $body = trim($data['translated_body']);
        $title = isset($data['translated_title']) && trim($data['translated_title']) !== ''
            ? trim($data['translated_title'])
            : null;
        $description = isset($data['translated_description']) && trim($data['translated_description']) !== ''
            ? Str::limit(trim($data['translated_description']), 490, '')
            : Str::limit(trim(strip_tags($body)), 160);

        $rawSlug = isset($data['localized_slug']) && trim($data['localized_slug']) !== ''
            ? trim($data['localized_slug'])
            : ($title ?: Str::limit(strip_tags($body), 70, ''));
        $slug = Str::slug($rawSlug);
        if ($slug === '') {
            $slug = 'post-'.$post->id.'-'.strtolower($targetLocale);
        }

        $sourceHash = $post->content_fingerprint ?: $this->qualityGate->contentFingerprint(
            trim((string) ($post->title ?? '')).' '.$post->content,
        );

        $existing = SeoContentTranslation::query()
            ->where('entity_type', SeoEntityType::Post->value)
            ->where('entity_id', $post->id)
            ->where('target_locale', $targetLocale)
            ->first();

        $translation = SeoContentTranslation::query()->updateOrCreate(
            [
                'entity_type' => SeoEntityType::Post->value,
                'entity_id' => $post->id,
                'target_locale' => $targetLocale,
            ],
            [
                'original_language' => $sourceLang,
                'translated_title' => $title,
                'translated_body' => $body,
                'translated_description' => $description,
                'localized_slug' => Str::limit($slug, 150, ''),
                'provider' => (string) ($data['provider'] ?? 'manual'),
                'source_content_hash' => $sourceHash,
                'version' => $existing !== null ? $existing->version + 1 : 1,
                'review_status' => SeoTranslationStatus::InReview,
                'publication_status' => SeoTranslationStatus::Draft,
                'is_ai_assisted' => (bool) ($data['is_ai_assisted'] ?? false),
                'is_stale' => false,
                'created_by' => $existing !== null ? $existing->created_by : $actor->id,
            ],
        );

        $this->registry->syncTranslation($translation, $post);

        $this->auditLogger->log('seo.translation.saved', $translation, [
            'post_id' => $post->id,
            'target_locale' => $targetLocale,
            'version' => $translation->version,
        ], $actor);

        return $translation;
    }

    public function approveAndPublish(SeoContentTranslation $translation, User $reviewer): SeoContentTranslation
    {
        if (mb_strlen(trim(strip_tags($translation->translated_body))) < 40) {
            throw ValidationException::withMessages([
                'translated_body' => [__('Translated content is too short to publish for international search indexing.')],
            ]);
        }

        $translation->forceFill([
            'review_status' => SeoTranslationStatus::Approved,
            'publication_status' => SeoTranslationStatus::Published,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'published_at' => $translation->published_at ?? now(),
            'is_stale' => false,
        ])->save();

        $this->registry->syncTranslation($translation);

        $this->auditLogger->log('seo.translation.published', $translation, [
            'entity_type' => $translation->entity_type,
            'entity_id' => $translation->entity_id,
            'target_locale' => $translation->target_locale,
        ], $reviewer);

        return $translation->fresh() ?? $translation;
    }

    public function unpublish(SeoContentTranslation $translation, User $reviewer, bool $reject = false): SeoContentTranslation
    {
        $translation->forceFill([
            'review_status' => $reject ? SeoTranslationStatus::Rejected : SeoTranslationStatus::Draft,
            'publication_status' => $reject ? SeoTranslationStatus::Archived : SeoTranslationStatus::Draft,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        $this->registry->syncTranslation($translation);

        $this->auditLogger->log('seo.translation.unpublished', $translation, [
            'rejected' => $reject,
            'target_locale' => $translation->target_locale,
        ], $reviewer);

        return $translation->fresh() ?? $translation;
    }
}
