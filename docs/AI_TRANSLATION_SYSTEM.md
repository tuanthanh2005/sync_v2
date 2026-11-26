# SyncViet — Multilingual AI Translation System

## 1. Integration with SEO V2.0 (15 Global Languages)

Rather than duplicating translation tables, `AiTranslationWorkflowService` writes directly into SEO V2.0's `seo_content_translations` via `ContentTranslationService::upsertPostTranslation()`:

- Supports all 15 enabled SyncViet languages (`en`, `vi`, `es`, `pt-BR`, `id`, `hi`, `th`, `fil`, `fr`, `de`, `ja`, `ko`, `zh-CN`, `ar` RTL, `tr`).
- Uses `gemini-2.5-flash-lite` (`AiModelGroup::LowCost`) with Structured JSON Output (`translated_title`, `translated_body`, `translated_description`, `localized_slug`).
- Preserves technical accuracy, code snippets, URLs, and brand names.

## 2. Editorial Safety & Quota Enforcement

- Respects Emergency Stop and `max_translations_per_day`.
- All AI translations are saved with `is_ai_assisted = true` and `publication_status = draft` (`review_status = draft`).
- Translations only become publicly indexable and enter reciprocal `hreflang` alternates + sharded XML sitemaps after a human editor reviews and publishes them in `/admin/seo/international`.
