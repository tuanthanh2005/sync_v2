# SyncViet Technical SEO V2.0 Final Pre-Launch Audit (PHẦN 28)

## 1. Core Technical SEO Controls Verified

- **Zero-Cloaking Public HTML (`resources/views/partials/seo-ssr.blade.php` & `app.blade.php`)**: Public posts, communities, profiles, and topics render real server-side `<title>`, `<meta name="description">`, `<link rel="canonical">`, reciprocal `<link rel="alternate" hreflang="...">`, JSON-LD `<script type="application/ld+json">` (escaped with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`), and `<noscript>` body content directly in initial HTML.
- **Trusted Origin Canonical Enforcement**: `SeoConfigService::canonicalBaseUrl()` strictly derives canonical URLs from server config (`SEO_CANONICAL_URL` / `APP_URL`), never trusting client `Host` headers.
- **Dynamic `/robots.txt` & Chunked Sitemaps (`/sitemap.xml`)**:
    - Verified live: `GET /robots.txt` (`200`, `4` SQL queries, `12.1 ms` app) and `GET /sitemap.xml` (`200`, `5` SQL queries, `22.8 ms` app) after `AUD-016` optimization!
    - Private/sensitive paths (`/admin`, `/wallet`, `/payments`, `/messages`, `/settings`, `/referral`) and draft policies are strictly excluded from sitemaps and marked `noindex`.
    - Referral landing links (`/r/{code}`) always return `X-Robots-Tag: noindex, nofollow` (verified in `UserGrowthAndReferralTest`).
- **Deleted Entity De-Indexing (Hardened in AUD-006)**: Deleting a post, community, or user profile dispatches `ProcessSeoEntityJob(..., removed: true, 410)` on the dedicated `seo` queue (`AUD-014`) to remove the URL from `seo_url_registry` and dirty sitemap shards immediately.
- **SEO Quality Gate**: Enforced in `SeoQualityGateService` — never bypassed by SyncViet Premium or Boost Post.
- **Honest Google API Compliance**: Google Search Console integration uses official Search Console APIs (Sites, Sitemaps, Search Analytics, URL Inspection) and **strictly forbids** Google Indexing API abuse on general posts.

## 2. Production Configuration Required Before Go-Live

1. Set `APP_ENV=production`, `APP_URL=https://syncviet.com`, `SEO_CANONICAL_URL=https://syncviet.com` in production `.env`.
2. Ensure `seo_enabled = true` in `/admin/seo` (and verify no accidental `noindex` exists on homepage/public posts once `APP_ENV=production`).
3. Add Google Site Verification meta/file token, optional Bing IndexNow key, and Google Search Console Service Account JSON in `/admin/seo/integrations`.
4. Run `php artisan app:seo:backfill` once after initial content is published, and submit `https://syncviet.com/sitemap.xml` in Google Search Console.
5. **Indexing Honesty Note**: Google indexing has **NOT** been claimed or verified yet — verify real indexing status inside Search Console / `/admin/seo` after live launch.
