# SyncViet Production Deployment Checklist & Runbook (PHẦN 42)

**Primary Production Domain**: `https://sysnoxis.com` (legacy `syncviet.com` & `www.*` 301-redirected via `EnforceCanonicalDomainMiddleware`, `/webhooks/*` exempt)  
**Target Production Path on Hostinger**: `~/domains/syncviet.com/syncviet` (`public_html` symlinked to `syncviet/public`; `sysnoxis.com` attached as parked alias on same vhost & database)  
**PHP Runtime Requirement**: **PHP 8.4+** (Hostinger SSH default `php` may be 8.3 — always use detected PHP 8.4 binary!)

## 1. Pre-Deployment Checklist (Mandatory Before Every Release)

- [ ] `git status` clean on `main`; target release commit verified.
- [ ] All automated tests pass (`php artisan test --compact` = `138/138 PASS`), PHPStan Level 7 = `0`, `vue-tsc` = `0`, `vp check` = `0`.
- [ ] Symfony patch advisories (`AUD-008`) resolved via approved `composer update symfony/http-kernel symfony/mailer symfony/routing symfony/serializer` & `composer audit` = `0`.
- [ ] Production frontend built (`npm run build`) and packaged cleanly (`D:\build.zip` containing `build/`).
- [ ] Pre-release database backup executed (`$PHP_BIN artisan app:backup:database`) BEFORE running migrations.
- [ ] Production `.env` verified:
    - `APP_ENV=production`
    - `APP_DEBUG=false`
    - `APP_URL=https://sysnoxis.com`
    - `SEO_CANONICAL_URL=https://sysnoxis.com`
    - `DOMAIN_REDIRECT_ENABLED=true`
    - `DOMAIN_LEGACY_HOSTS=syncviet.com,www.syncviet.com`
    - `DOMAIN_REDIRECT_WWW_TO_APEX=true`
    - `APP_LOCALE=en` / `APP_FALLBACK_LOCALE=en`
    - `HASH_DRIVER=argon2id`
    - `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`
    - `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`
    - `LOG_STACK=daily`, `LOG_DAILY_DAYS=14`, `LOG_LEVEL=warning`
    - `TRUSTED_PROXIES=<Cloudflare/Hostinger proxy IPs>`
    - `PAYMENT_WEBHOOK_SECRET=<strong random 64-char secret>` (if live webhooks enabled)
    - NEVER overwrite `.env`, `storage/app/public/`, or `storage/app/private/`.

## 2. Ordered Zero-Data-Loss Hostinger SSH Deployment

1. Upload `build.zip` to `~/domains/syncviet.com/syncviet/public/build.zip`.
2. Execute via SSH (never runs `migrate:fresh`, `db:wipe`, or deletes `storage/`):

```bash
cd ~/domains/syncviet.com/syncviet && \
PHP_BIN=$(for p in /opt/alt/php84/usr/bin/php /usr/bin/php8.4 /usr/bin/php84 /usr/local/bin/php84; do [ -x "$p" ] && echo "$p" && break; done) && \
echo "Using PHP: $($PHP_BIN -v | head -n 1)" && \
$PHP_BIN artisan app:backup:database && \
git fetch origin main && \
git reset --hard origin/main && \
if [ -f public/build.zip ]; then rm -rf public/build && unzip -o public/build.zip -d public && rm -f public/build.zip; fi && \
$PHP_BIN artisan migrate --force && \
$PHP_BIN artisan app:rbac:sync && \
$PHP_BIN artisan app:growth:snapshot-analytics && \
$PHP_BIN artisan optimize:clear && \
$PHP_BIN artisan config:cache && \
$PHP_BIN artisan route:cache && \
$PHP_BIN artisan view:cache && \
$PHP_BIN artisan queue:restart
```

## 3. Post-Deployment Smoke Verification

- [ ] `GET https://sysnoxis.com/up` -> `200 OK`
- [ ] `GET https://sysnoxis.com/` -> `200 OK`, proper light/dark rendering, zero console/manifest errors
- [ ] `GET https://sysnoxis.com/robots.txt` & `/sitemap.xml` -> `200 OK` (all `<loc>` use `https://sysnoxis.com`)
- [ ] `GET https://syncviet.com/en/posts/example` -> `301` -> `https://sysnoxis.com/en/posts/example`
- [ ] Login + TOTP 2FA on Admin account -> `/admin/system-health` shows DB OK, Cache OK, `app_debug: off`, Backup recorded, Cron heartbeat updating
- [ ] Test Guest registration / login / public post view
- [ ] Verify Hostinger hPanel Cron jobs are active (`schedule:run` + bounded priority `queue:work --queue=payments,high,default,seo,ai`)
- [ ] Confirm `.env` and `.git` return `403`/`404` publicly (`curl -I https://sysnoxis.com/.env`)
