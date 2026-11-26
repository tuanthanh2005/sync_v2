# SyncViet Hostinger Shared Hosting, Cloudflare & Scaling Plan (PHẦN 30, 31, 34, 49)

## 1. Hostinger Shared Hosting Resource Budget & Co-Tenancy Safeguards

Reference Hosting Tier (verify exact live limits in Hostinger hPanel):

- `2 CPU`, `3 GB RAM`, `50 GB Storage`, ~`60 PHP Workers` (shared with another live AI-store website on the same account).
- **Mandatory Co-Tenancy Rule**: SyncViet MUST NEVER starve CPU, RAM, PHP Workers, or Disk/Inodes of the co-hosted website.

### Built-In Shared-Hosting Protections

1. **80%+ Query Overhead Eliminated (`AUD-016`)**: Request-scoped memoization cut `/` from `101 -> 30` queries and `/robots.txt` from `64 -> 4` queries under `CACHE_STORE=database`.
2. **Strict Upload RAM Ceiling (`AUD-011`)**: `5 MB` file limit + `16 MP` total pixel decompression ceiling prevents GD worker OOM.
3. **Log Rotation & Backup Pruning (`AUD-007`, `AUD-012`)**: Daily rotating logs (`LOG_STACK=daily`, `LOG_DAILY_DAYS=14`) + automatic database backup pruning (`SYNCVIET_BACKUP_RETENTION_DAYS=7`) protect the `50 GB` disk and `600,000` inode cap.
4. **Priority-Ordered Bounded Queue Worker (No Supervisor Required)**:
   Run two lightweight hPanel Cron jobs (replacing `/opt/alt/php84/usr/bin/php` with verified Hostinger PHP 8.4 binary):
    ```bash
    # Every minute: Laravel Scheduler (all commands use withoutOverlapping())
    * * * * * cd ~/domains/syncviet.com/syncviet && /opt/alt/php84/usr/bin/php artisan schedule:run >> /dev/null 2>&1

    # Every minute: Priority-ordered bounded queue worker (exits cleanly in <= 40s, never blocks payments with AI/SEO jobs)
    * * * * * cd ~/domains/syncviet.com/syncviet && /opt/alt/php84/usr/bin/php artisan queue:work database --queue=payments,high,default,seo,ai --stop-when-empty --max-time=40 --timeout=25 --tries=3 --memory=128 >> /dev/null 2>&1
    ```
5. **Verified Host Telemetry Honesty**: Because Shared Hosting PHP does not expose host-wide CPU/RAM/PHP Worker APIs, `/admin/system-health` displays `Unavailable` with instructions to monitor real-time host metrics in Hostinger hPanel (zero fake numbers).

## 2. Cloudflare & Network Security Checklist (PHẦN 30)

- **SSL/TLS Mode**: Use **Full (strict)** with valid Hostinger Origin Certificate (never Flexible TLS).
- **Reverse Proxy Trust (`AUD-005`)**: Configure `TRUSTED_PROXIES` in production `.env` with Cloudflare IPv4/IPv6 CIDR ranges (only if origin restricts non-Cloudflare direct hits or Hostinger proxy IP is explicitly listed) so per-IP rate limits, login history, and `Secure` cookies work accurately.
- **CDN Caching Rules**: Cache static `/build/assets/*`, fonts, and public media aggressively; **BYPASS** cache on `/admin*`, `/wallet*`, `/payments*`, `/messages*`, `/settings*`, `/login`, `/register`, `/referral`, and all `X-Inertia: true` requests.
- **Security Headers**: `SecurityHeaders` middleware enforces `CSP`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, and `HSTS` (`max-age=31536000; includeSubDomains`, without premature `preload`) on HTTPS production.
- **Crawler Safety**: Do not block verified Googlebot/Bingbot in Cloudflare WAF; never trust spoofed `User-Agent` strings for authorization.

## 3. Evidence-Based Infrastructure Scaling Roadmap (PHẦN 49)

Only upgrade when real hPanel metrics (CPU > 75% sustained, PHP Workers > 70%, DB latency > 200ms, or queue backlog growing) prove a bottleneck — **never upgrade solely based on registered account count**:

- **Stage A (Current Shared Hosting — Zero Extra Cost)**: Query caching on `/services` & `/premium` (60s TTL), Cloudflare static CDN, WebP media compression, bounded Cron queues.
- **Stage B (Targeted Offloading)**: Move uploaded media to **Cloudflare R2** via `.env` (`MEDIA_DISK=s3` — zero code rewrite!) if storage approaches 60% of 50 GB; optionally offload heavy AI queue worker only when needed.
- **Stage C (Dedicated VPS)**: Move Laravel app + MySQL + Redis (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis` via `.env` only) + Supervisor workers when sustained concurrent traffic exceeds Shared Hosting PHP Worker or CPU caps.
- **Stage D (Horizontal Scaling)**: Separate DB instance, load balancer, and dedicated search index only if multi-server demand is proven.
