# Admin SEO Center Operations Guide

## 1. Navigation & Permissions

Located under **Admin → SEO Center** (`AdminLayout.vue`), protected by 2FA + granular RBAC permissions (`seo.view`, `seo.manage`, `seo.audit`, `seo.integrations`, `seo.translations`, `seo.redirects`):

1. **SEO Overview** (`/admin/seo`): KPI cards (Registered, Eligible, In Sitemap, Google Indexed, Internal Technical Health Score), Search Console performance (honest empty state until connected), Organic Conversion Funnel, Critical/High Alerts, 15-Language distribution, and Recent Background Jobs.
2. **Integrations & GSC** (`/admin/seo/integrations`): Google & Bing ownership verification, Google Search Console (Service Account JSON or OAuth 2.0), Bing IndexNow key & batch submission, Controlled GA4, Cloudflare Cache Purge (purge-only), and API Usage ledger.
3. **URLs & Sitemaps** (`/admin/seo/urls`): Searchable/filterable URL Registry with bulk actions (`Force Noindex`, `Allow Index`, `Queue Inspection`, `Queue IndexNow`), Sync All Entities backfill, formula-safe CSV export, Sharded Sitemaps status & rebuild, URL Inspection Queue, Managed Redirects (301/302/307/308), and Dynamic `robots.txt` rules.
4. **International SEO** (`/admin/seo/international`): 15-language matrix, Multilingual Content Translation editorial workflow (Draft → Approve & Publish / Unpublish), Live Hreflang Validator, and Global Metadata Templates.
5. **Audit & Growth** (`/admin/seo/audit`): On-demand Technical SEO Audit runner, open issue resolution, GSC-backed Keyword Intelligence & CTR recommendations, active Topic Clusters, and Quality Gate & Quota guardrails.

## 2. Artisan CLI Commands

- `php artisan app:seo:backfill` — Initial/full backfill of all existing public entities + full sitemap rebuild.
- `php artisan app:seo:sync` — Incremental dirty sitemap shard rebuild + queued IndexNow batch (scheduled every 5 min).
- `php artisan app:seo:inspect` — Bounded GSC URL Inspection batch (scheduled hourly).
- `php artisan app:seo:analytics` — Sync 28-day GSC Search Analytics + refresh recommendations (scheduled daily 03:15).
- `php artisan app:seo:audit` — Automated technical SEO audit (scheduled weekly Mondays 04:00).
