# Hreflang System & Validator Specification

## 1. Strict Rules Enforced by `HreflangService`

1. **Absolute HTTPS URLs only**: Derived from `SeoConfigService::canonicalBaseUrl()`.
2. **Valid BCP-47 locale codes**: Verified via `LanguageDetectionService::isValidBcp47()` and constrained to enabled platform languages (`en`, `vi`, `es`, `pt-BR`, `id`, `hi`, `th`, `fil`, `fr`, `de`, `ja`, `ko`, `zh-CN`, `ar`, `tr`).
3. **Real published equivalents only**: Never outputs links to empty, draft, rejected, or non-existent localized pages.
4. **Reciprocal & self-referencing**: Original post and every published `seo_content_translations` equivalent output the exact same complete alternate link set.
5. **Correct `x-default` per resource**: Points to the published English equivalent if available, otherwise to the canonical original resource — **never** blindly pointing every post's `x-default` to `/`.

## 2. Automated Hreflang Validator

`HreflangService::validateAll()` runs on demand in **Admin → SEO Center → International SEO** and automatically inside `SeoAuditService::runAudit()`, detecting:

- `hreflang_orphan_translation` (source deleted)
- `hreflang_invalid_code`
- `hreflang_duplicate_locale`
- `hreflang_relative_url`
- `hreflang_disabled_locale`
- `hreflang_points_to_noindex` (alternate marked non-eligible in `seo_url_registry`)
