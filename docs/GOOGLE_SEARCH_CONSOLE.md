# Google Search Console Official API Integration Guide

## 1. Strict API Compliance Policy

SyncViet integrates **only** the 4 official Google Search Console APIs:

1. **Sites API** (`GET /webmasters/v3/sites`) — verifies accessible properties and tests live connectivity.
2. **Sitemaps API** (`PUT/GET/DELETE /webmasters/v3/sites/{siteUrl}/sitemaps/{feedpath}`) — submits `/sitemap.xml` officially (never calls deprecated `/ping`).
3. **Search Analytics API** (`POST /webmasters/v3/sites/{siteUrl}/searchAnalytics/query`) — pulls real clicks, impressions, CTR, and average position grouped by date, page, query, country, and device.
4. **URL Inspection API** (`POST https://searchconsole.googleapis.com/v1/urlInspection/index:inspect`) — inspects priority eligible URLs within a strict daily quota budget (default `1,800/day`).

**Explicit Prohibitions:**

- SyncViet **NEVER** uses the Google Indexing API (`indexing.googleapis.com`) for social posts, profiles, communities, or topics (reserved by Google strictly for `JobPosting` and `BroadcastEvent`).
- SyncViet **NEVER** uses a Gemini API key to connect to Search Console.
- When unconfigured, the Admin UI honestly displays `not_connected` / `Setup Required` and displays zero fabricated Google metrics.

## 2. Authentication Methods Supported

### Option A — Google Service Account JSON (Recommended for Server Automation)

1. In Google Cloud Console, enable **Google Search Console API** (free — no paid billing required).
2. Create a Service Account and download its JSON key.
3. In Google Search Console → **Settings → Users and permissions**, add the Service Account `client_email` as a **Full** user on `sc-domain:sysnoxis.com`.
4. In SyncViet **Admin → SEO Center → Integrations & GSC**, paste the Service Account JSON and enter `sc-domain:sysnoxis.com`.
5. SyncViet signs RS256 JWT assertions natively via PHP `openssl_sign()` and encrypts the credentials with Laravel `Crypt::encryptString()` (`APP_KEY`).
6. Click **Test Connection**.

### Option B — OAuth 2.0 Web Client

1. Create an OAuth 2.0 Client ID (Web Application) in Google Cloud Console.
2. Add the Authorized Redirect URI displayed in Admin (`/admin/seo/integrations/gsc/oauth-callback`).
3. Enter Client ID and Client Secret in SyncViet Admin and click **Authorize with Google** (CSRF-protected `state` parameter).

## 3. Scheduled Operations

- `php artisan app:seo:inspect` — runs hourly via Hostinger Cron, bounded to `SEO_GSC_INSPECTION_BATCH_SIZE=25` URLs and daily quota tracking in `seo_api_usage`.
- `php artisan app:seo:analytics` — runs daily at `03:15` to sync the latest 28 days of final Search Analytics and refresh keyword recommendations.
