# SyncViet — AI & International SEO V2.0 Integration

## 1. Seamless Shared Pipeline

- **Post Publication Hook**: Every approved AI post published through `AiPublishingAndSchedulerService` dispatches `ProcessSeoEntityJob(SeoEntityType::Post, $post->id)` inside `DB::afterCommit()`, automatically passing through `SeoQualityGateService` before entering `seo_url_registry`, sharded XML sitemaps, and Bing IndexNow.
- **Zero Quality Gate Bypass**: AI posts receive the exact same indexability, thin-content, and canonical standards as human posts—no special SEO shortcuts.
- **Metadata Pre-Fill**: `seo_title` and `seo_description` approved on `AiContentDraft` are persisted into `seo_metadata` for the post's locale.
- **Real Search Console Opportunities**: `AiSourceResearchService::seoOpportunities()` reads open records from `seo_recommendations` generated from verified Google Search Console analytics—never fabricated keywords.
- **Multilingual Internationalization**: AI translations populate `seo_content_translations` so approved versions automatically receive reciprocal `hreflang` tags via `HreflangService` and clean localized URLs (`/{locale}/posts/{id}-{slug}`).
