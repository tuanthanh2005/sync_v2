# SyncViet SEO V2.0 — Implementation Plan & Completed Phases

## Audit Baseline (Pre-V2.0)

Before V2.0, SyncViet had:

- A static 2-line `public/robots.txt` without `Sitemap:` or private-route disallow rules.
- A single unsharded `/sitemap.xml` limited to 200 posts with hardcoded `en`/`vi` alternates.
- Empty JS-only `<body>` in `app.blade.php` without non-JS semantic fallback HTML.
- Partial metadata on Posts/Profiles/Communities and none on Home/Topics.
- No URL Registry, no Content Translation layer, no Redirect Manager, no Google Search Console API integration, no IndexNow, no SEO Audit Engine, and no Admin SEO Center.

## Completed Delivery Phases (1–8)

1. **Phase 1 — Technical SEO Foundation**: 17 normalized `seo_*` database tables + SEO columns on `posts`, `communities`, `users`; dynamic `/robots.txt`; `SeoMiddleware` (`X-Robots-Tag`, managed redirects, privacy-safe organic capture, safe `Cache-Control`); full Blade `<head>` metadata + zero-cloaking `<noscript>` SSR HTML.
2. **Phase 2 — URL Registry & Sharded Sitemaps**: Automatic after-commit `ProcessSeoEntityJob`, chunked 5,000-URL shard generator (`/sitemap.xml` index + `pages`, `posts-{locale}`, `communities`, `profiles`, `topics-{locale}`, `images`), ETag + `304`, and `InternalLinkingService`.
3. **Phase 3 — Google & Bing Official Integrations**: Real DNS/HTML/Meta site verification (never fakes Verified), encrypted OAuth 2.0 + RS256 Service Account GSC integration (Sites, Sitemaps, Search Analytics, URL Inspection), Bing IndexNow with content-hash deduplication.
4. **Phase 4 — International SEO (15 Locales)**: Localized routes (`/{locale}/posts/{id}-{slug}`, `/{locale}/topics/{slug}`, `/{locale}/communities/{slug}`) preserving all legacy routes, `ContentTranslationService` editorial workflow, and reciprocal `HreflangService` + validator.
5. **Phase 5 — Structured Data, Topic Clusters & UGC Protection**: Factual JSON-LD schemas, crawlable `/topics` & `/topics/{slug}` pagination, `rel="ugc nofollow noopener noreferrer"` on user external links, duplicate fingerprinting, and AI-assisted readiness flags.
6. **Phase 6 — CWV, OG Image, Cloudflare & PageSpeed**: Built-in `1200x630` `/og-default.svg`, guest vs authenticated cache headers, Cloudflare purge-only adapter, PageSpeed Insights Lab vs CrUX Field distinction.
7. **Phase 7 — Admin SEO Center, Audits, Keywords, GA4 & Growth**: Dedicated 5-page `SEO Center` module in `AdminLayout.vue`, RBAC `seo.*` permissions, automated technical audit engine, GSC-backed keyword recommendations, controlled GA4 (zero raw JS), and organic conversion funnel.
8. **Phase 8 — Testing, 15-Locale i18n, Verification & Documentation**: Full Pest feature suite (`SeoEngineV2Test`), 15-language JSON parity, PHPStan Level 7, Pint, `vue-tsc`, Vite build, and 16 SEO docs.
