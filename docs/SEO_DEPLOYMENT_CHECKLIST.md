# SEO V2.0 Production Deployment Checklist (Hostinger Shared Hosting)

## 1. Environment Variables (`.env`)

Verify on production before enabling indexing:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sysnoxis.com
SEO_CANONICAL_URL=https://sysnoxis.com
SEO_SITEMAP_SHARD_SIZE=5000
SEO_GSC_INSPECTION_DAILY_QUOTA=1800
```

## 2. Server Deployment Sequence (Manual SSH)

1. Pull latest `main` and upload & extract Vite static assets (`public/build`).
2. Confirm static `public/robots.txt` does **not** exist on the server (so Laravel serves dynamic `/robots.txt`).
3. Run migrations, RBAC/SEO defaults sync, and initial SEO backfill:

```bash
php artisan migrate --force
php artisan app:rbac:sync
php artisan app:seo:backfill
php artisan app:seo:audit
php artisan optimize:clear && php artisan optimize
```

4. Verify Hostinger Cron Job runs every minute:

```bash
* * * * * cd /home/<user>/domains/syncviet.com/public_html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

## 3. Manual External Setup Required by Site Owner

SyncViet never modifies your DNS or paid billing automatically:

1. **Google Search Console Ownership**: Add Domain Property `sc-domain:sysnoxis.com`, add the Google DNS TXT record in Cloudflare/Hostinger DNS, then enter the token in **Admin → SEO Center → Integrations & GSC** and click **Verify Live Now**.
2. **Google Search Console API**: Enable Search Console API (free) in Google Cloud Console, create a Service Account (or OAuth 2.0 Web Client), grant the Service Account email **Full** access to `sc-domain:sysnoxis.com` in GSC, paste the JSON into Admin, click **Test Connection**, then click **Submit Sitemap Index via API**.
3. **Bing Webmaster & IndexNow**: Toggle IndexNow ON in Admin → verify `https://sysnoxis.com/{key}.txt` returns HTTP 200 → optionally add Bing meta token.
4. **Optional**: Configure GA4 Measurement ID (`G-XXXXXXXXXX`), Cloudflare Cache Purge API token, or `PAGESPEED_INSIGHTS_API_KEY`.
