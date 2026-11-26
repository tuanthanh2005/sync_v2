# SyncViet Lightweight Logging & Production Monitoring Guide (PHẦN 38 & 39)

## 1. Lightweight Shared-Hosting Logging Configuration

- **Production `.env` Settings**:
    - `LOG_CHANNEL=stack`
    - `LOG_STACK=daily`
    - `LOG_DAILY_DAYS=14` (prevents unbounded `laravel.log` growth on Hostinger 50 GB storage!)
    - `LOG_LEVEL=warning` (reduces noisy info writes while capturing errors, exceptions, and security warnings)
- **Strict Redaction Policy**: Passwords, `APP_KEY`, Gemini API keys (`x-goog-api-key`), PayPal/Webhook secrets, private message contents, and session tokens are NEVER written to logs or Inertia error payloads (`AuditLogger` redacts secret keys).

## 2. Public vs. Authenticated Health Monitoring

- **Public Uptime Probe (`GET /up`)**: Lightweight Laravel health endpoint returning `200 OK` without exposing sensitive internals. Connect a free external uptime ping (e.g., UptimeRobot / Better Stack every 5 minutes) to monitor availability and HTTP 5xx spikes.
- **Admin Diagnostic Center (`GET /admin/system-health`)** — strictly protected by `auth`, `verified`, `active`, `admin.2fa`, `can:access-admin`, `permission:system_health.view`:
    - Database live ping & latency (`ms`)
    - Live Cache read/write probe (`OK` / `DEGRADED`)
    - Queue backlog (`pending_jobs`, `failed_jobs`) & Scheduler last heartbeat (`cron_last_heartbeat`)
    - Backup count, last backup timestamp, type, and status
    - Storage media bytes & free disk space
    - Trusted Proxies & `APP_DEBUG` check
    - Integration readiness: Active Payment Methods, Payment Webhook HMAC Secret status, Google Search Console status, AI Healthy Key count & Emergency Stop state
    - Security Alerts: 24h failed login attempts (`login_histories`) & pending manual fraud reviews (`growth_fraud_reviews`)
    - **Honest Host Telemetry**: CPU, RAM, and PHP Workers explicitly report `Unavailable` inside PHP with guidance to check Hostinger hPanel (zero fabricated metrics).

## 3. Daily & Weekly SRE Check Routine

- **Daily (2 minutes)**: Check `/admin/system-health` (`failed_jobs = 0`, `cron_last_heartbeat` recent, `last_backup_at` within 24h, `app_debug = false`), check `/admin/security` for suspicious login bursts or failed webhooks (`late_reconciliation`), check `/admin/growth/referrals` for pending fraud reviews.
- **Weekly**: Inspect Hostinger hPanel Resource Usage (CPU, RAM, Entry Processes, Disk/Inodes) to ensure SyncViet stays safely below 60% and never affects the co-hosted website.
