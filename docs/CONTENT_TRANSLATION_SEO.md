# Multilingual Content Translation SEO Workflow

## 1. Data Model (`seo_content_translations`)

Each localized version of a public Post stores:

- `entity_type`, `entity_id`, `original_language`, `target_locale` (unique per entity + target locale)
- `translated_title`, `translated_body`, `translated_description`, `localized_slug`
- `provider` (`manual`, future AI provider interface), `is_ai_assisted`
- `source_content_hash` (SHA-256 fingerprint of original post), `is_stale` (auto-flagged when original post changes!)
- `version`, `review_status` (`draft`, `in_review`, `approved`, `rejected`), `publication_status` (`draft`, `published`, `archived`), `reviewed_by`, `published_at`.

## 2. Editorial & Indexing Guardrails

1. New translations are saved as `review_status = in_review`, `publication_status = draft`.
2. **Zero unapproved indexing**: Visiting `/{locale}/posts/{id}-{slug}` for an unapproved or draft translation returns `301` to the canonical original post, and `SeoQualityGateService::evaluateTranslation()` marks it `excluded`.
3. Approving & publishing via **Admin → SEO Center → International SEO** sets `review_status = approved`, `publication_status = published`, registers the localized URL in `seo_url_registry`, marks `sitemap-posts-{target_locale}` dirty, and enables reciprocal `hreflang`.
4. Editing the original post updates `posts.content_fingerprint` and marks outdated translations `is_stale = true` so editors are alerted during audits.
