# SyncViet Backend & Database Performance Audit (PHẦN 21, 22, 23, 29)

## 1. Major Bottleneck Discovered & Fixed (`AUD-016`)

Live SQL query profiling under `CACHE_STORE=database` (mandatory on Hostinger Shared Hosting) revealed that a single request to `/` triggered **101 SQL queries** (`33x` database `cache` lookups + `44x` `information_schema.tables` probes) and `/robots.txt` triggered **64 SQL queries** because `HandleInertiaRequests::share()` ran on non-Inertia utility routes and config services lacked per-request memoization.

### Fixes Applied

1. Bound `LanguageService`, `SystemSettingService`, `SeoConfigService`, `RbacService`, and `ContentFilterService` as request-scoped (`$this->app->scoped(...)`) in `AppServiceProvider`.
2. Added per-request in-memory memoization (invalidated cleanly in `flushCache()`) and moved `Schema::hasTable()` / `ensureSeeded()` inside cache-miss closures.
3. Short-circuited `HandleInertiaRequests::share()` for non-`GET` requests and non-Inertia utility routes (`/robots.txt`, `/sitemap*.xml`, `/ads.txt`, `/r/*`, `/webhooks/*`, `/up`).

### Verified Before vs. After Measurements (Local MySQL 8.4.3)

| Endpoint             | SQL Queries Before | SQL Queries After | Query Reduction | App Time (Bootstrapped PHP) | Security Headers (`CSP`/`XFO`/`nosniff`) |
| :------------------- | :----------------: | :---------------: | :-------------: | :-------------------------: | :--------------------------------------: |
| `/` (Homepage)       |        101         |      **30**       |   **-70.3%**    |        `103 ms` SQL         |               All Present                |
| `/services`          |        110         |      **54**       |   **-50.9%**    |       `91.2 ms` total       |               All Present                |
| `/premium`           |        101         |      **45**       |   **-55.4%**    |      `235.9 ms` total       |               All Present                |
| `/advertising`       |        101         |      **47**       |   **-53.5%**    |       `69.9 ms` total       |               All Present                |
| `/communities`       |         78         |      **18**       |   **-76.9%**    |       `38.6 ms` total       |               All Present                |
| `/search?q=syncviet` |         87         |      **35**       |   **-59.8%**    |      `118.6 ms` total       |               All Present                |
| `/topics`            |         78         |      **18**       |   **-76.9%**    |       `37.1 ms` total       |               All Present                |
| `/robots.txt`        |         64         |       **4**       |   **-93.8%**    |       `12.1 ms` total       |               All Present                |
| `/sitemap.xml`       |         61         |       **5**       |   **-91.8%**    |       `22.8 ms` total       |               All Present                |

## 2. Laravel Production Cache Compatibility (PHẦN 22)

Verified with zero errors (`exit code 0`):

- `php artisan config:cache` — **PASS** (`0` `env()` calls outside `config/`)
- `php artisan route:cache` — **PASS**
- `php artisan view:cache` — **PASS**

## 3. Caching & Privacy Boundaries (PHẦN 23)

- **Cached Safely**: Language catalog (15m), System Settings (10m), SEO Settings (15m), Sitemap XML shards with ETag/304, daily precomputed `growth_analytics` snapshots.
- **Strictly Never Cached Publicly**: Authenticated personal feeds, private posts/communities, payments, wallet balances, private messages, admin screens. `public/sw.js` explicitly excludes `/admin`, `/wallet`, `/payments`, `/webhooks`, `/messages`, auth & Inertia XHR requests.

## 4. Core Web Vitals & Field Data Status (PHẦN 29 — Honest Reporting)

- **Local Lab Status**: Initial HTML payload sizes are lightweight (`21 KB – 31 KB` uncompressed), guest bundle loads zero Admin JS chunks, fonts (`Be Vietnam Pro`) are self-hosted woff2 with `scrollbar-gutter: stable` eliminating layout shifts.
- **Field Data (CrUX / Real-User LCP, INP, CLS)**: **Not Yet Available / Not Verified** — SyncViet is pre-public-launch and does not yet have 28-day real-user CrUX telemetry or live production PageSpeed Insights runs. Owner should run PageSpeed Insights against live `https://syncviet.com` post-deploy and track CrUX in Google Search Console.
