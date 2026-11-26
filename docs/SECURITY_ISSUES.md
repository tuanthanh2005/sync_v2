# SyncViet Production Audit — Security, Business & Performance Issue Register

All issues below were discovered through active code, database, security, and runtime profiling during PROMPT 14.

## Summary by Severity

- **P0 (Critical)**: 0 unresolved
- **P1 (High)**: 8 identified — **7 Fixed & Regression-Tested**, **1 Deferred for Owner Dependency Approval (`AUD-008` Symfony 8.1.12 patch)**
- **P2 (Medium)**: 7 identified — **7 Fixed & Verified**
- **P3 (Low)**: 1 identified — **1 Fixed & Verified**

---

## Detailed Issue Reports

### AUD-001 — `WalletService::settlePaymentCredit()` Allowed Settling Cancelled/Expired/Refunded Top-Up Orders

- **Severity**: P1 – HIGH
- **Affected Files**: `app/Services/Billing/WalletService.php`, `app/Services/Billing/HmacPaymentWebhookService.php`
- **Root Cause**: `WalletService::settlePaymentCredit()` only short-circuited `status === Paid` and lacked a guard rejecting `Cancelled`, `Expired`, or `Refunded` orders (unlike `MonetizationPaymentService::settleOrder()`).
- **Impact**: A late or replayed settlement call against a cancelled or refunded top-up order could credit Advertising Balance erroneously.
- **Fix Applied**: Added strict status guard inside `WalletService::settlePaymentCredit()`. Updated `HmacPaymentWebhookService::handleWebhook()` so signed webhooks arriving for closed/expired orders are never blindly credited AND never silently discarded — they log `late_reconciliation` in `payment_webhook_logs` and raise `payment.late_webhook_reconciliation_required` in `audit_logs` for Finance review.
- **Test Evidence**: `ProductionLaunchAuditTest` (`rejects currency mismatches on signed webhooks, blocks settling cancelled top-ups, and logs late valid webhooks for manual reconciliation`) — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-002 — Max-5 Pending Payment Orders Cap Scoped Per `service_type` Instead of Global

- **Severity**: P1 – HIGH
- **Affected Files**: `app/Services/Monetization/MonetizationPaymentService.php`, `app/Services/Billing/WalletService.php`
- **Root Cause**: Both services counted open pending orders with `->where('service_type', ...)`, permitting up to 20 simultaneous unpaid orders (5 Premium + 5 Banner + 5 Boost + 5 Top-Up).
- **Impact**: Bypassed the global anti-spam pending order guardrail across services and payment providers.
- **Fix Applied**: Removed `service_type` scoping inside `User::lockForUpdate()` transactions in both services and in `WalletService::walletPayload()`.
- **Test Evidence**: `ProductionLaunchAuditTest` (3 Premium + 2 Top-Up = 5 -> 6th blocked across both services) + `MonetizationSystemTest` — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-003 — AI Agents Not Explicitly Blocked From Creating Financial Orders / Wallet Top-Ups

- **Severity**: P1 – HIGH
- **Affected Files**: `app/Services/Monetization/MonetizationPaymentService.php`, `app/Services/Billing/WalletService.php`
- **Root Cause**: `createOrder()` and `createTopUpCheckout()` did not assert `$user->isHumanMember()`.
- **Impact**: Violated strict financial isolation of AI Agent and System Official accounts.
- **Fix Applied**: Rejected non-human accounts (`! $user->isHumanMember()`) with `ValidationException` at entry of both financial creation services.
- **Test Evidence**: `ProductionLaunchAuditTest` — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-004 — Missing Webhook Currency Validation & Full Premium Refund Did Not Revoke Linked Subscription

- **Severity**: P1 – HIGH
- **Affected Files**: `app/Services/Billing/HmacPaymentWebhookService.php`, `app/Services/Monetization/MonetizationPaymentService.php`
- **Root Cause**: Webhook handler verified `amount` but not `currency`. Full Premium refund updated `payments.status = refunded` without revoking the linked `subscriptions` row or updating `users.premium_until`.
- **Impact**: Currency spoofing risk on webhooks; refunded users could retain active Premium badge until original expiry.
- **Fix Applied**: Added strict `currency` comparison (`422 currency_mismatch`) in `HmacPaymentWebhookService`. Updated `MonetizationPaymentService::adminRefundOrder()` to revoke active subscriptions tied to a fully refunded Premium payment and recompute `users.premium_until` from any remaining active non-revoked subscriptions.
- **Test Evidence**: `ProductionLaunchAuditTest` — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-005 — Missing Trusted Proxy Configuration for Cloudflare / Hostinger Reverse Proxy

- **Severity**: P1 – HIGH
- **Affected Files**: `app/Http/Middleware/TrustConfiguredProxies.php`, `bootstrap/app.php`, `config/syncviet.php`, `.env.example`
- **Root Cause**: Application had no configurable `TrustProxies` middleware.
- **Impact**: Behind Cloudflare + Hostinger proxy, `$request->ip()` could collapse all visitors onto the proxy IP (breaking per-IP rate limiting) and `$request->isSecure()` could evaluate false (breaking `Secure` cookies/HSTS).
- **Fix Applied**: Added `TrustConfiguredProxies` driven by cached `config('syncviet.trusted_proxies')` (`TRUSTED_PROXIES` env var, strictly `null` unless configured — never blindly trusting spoofed headers).
- **Test Evidence**: `config:cache` & full 138-test suite — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-006 — Incomplete Account Deletion Cleanup (Other Sessions, Marketing Prefs, Referral Codes, SEO Sitemap)

- **Severity**: P1 – HIGH
- **Affected Files**: `app/Http/Controllers/Settings/ProfileController.php`
- **Root Cause**: `ProfileController::destroy()` only logged out the current session and soft-deleted `User`.
- **Impact**: Other device sessions remained in `sessions`, referral codes remained active, marketing flags stayed enabled, and deleted profiles remained in `seo_url_registry` until next backfill.
- **Fix Applied**: Updated `destroy()` to revoke all `sessions` rows for `$user->id`, set all `notification_preferences` to `false`, deactivate `referral_codes`, dispatch `ProcessSeoEntityJob(Profile, removed: true, 410)`, and write an immutable `account.deleted_by_user` audit entry.
- **Test Evidence**: `ProductionLaunchAuditTest` — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-007 — No Automated Restorable Full Logical Database Backup for Hostinger Shared Hosting

- **Severity**: P1 – HIGH
- **Affected Files**: `app/Services/Admin/DatabaseBackupService.php`, `routes/console.php`
- **Root Cause**: Existing `AdminSecurityService::createSnapshot()` backed up only 5 governance tables as JSON rather than a full restorable database dump.
- **Impact**: No automated Laravel-native daily MySQL dump existed outside Hostinger panel backups.
- **Fix Applied**: Implemented pure-PDO streaming `DatabaseBackupService::createDatabaseBackup()` producing gzipped `.sql.gz` archives strictly inside private `storage/app/private/backups/` with SHA-256 checksums, `BackupRecord` tracking, automatic retention pruning (`SYNCVIET_BACKUP_RETENTION_DAYS=7`, always keeping ≥ 3 newest), scheduled daily at `02:00` via `app:backup:database`.
- **Test Evidence**: `ProductionLaunchAuditTest` + Isolated MySQL 8.4.3 full Restore Test (`syncviet_restore_test`, `117/117` tables, 100% row parity) — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-008 — 7 Upstream Symfony 8.x Patch CVEs Reported by `composer audit` (2026-11-25)

- **Severity**: P1 – HIGH (Dependency Patch Required Before Public Launch)
- **Affected Packages**: `symfony/http-kernel` (`CVE-2026-133743`), `symfony/mailer` (`CVE-2026-133751`, `CVE-2026-133755`), `symfony/routing` (`CVE-2026-133754`), `symfony/serializer` (`CVE-2026-133761`, `CVE-2026-133752`) — all patched in `8.1.12`.
- **Root Cause**: Newly disclosed `2026-11-25` upstream Symfony advisories.
- **Impact**: Security advisories in transitive framework packages.
- **Fix Status**: **Requires Owner Approval** (per `AGENTS.md` rule prohibiting unapproved dependency modifications). Owner should execute `composer update symfony/http-kernel symfony/mailer symfony/routing symfony/serializer` and verify `composer audit` reports `0` advisories before Go-Live.

### AUD-009 — AI Agents & Crawlers Not Excluded From Paid Ad Impressions/Clicks

- **Severity**: P2 – MEDIUM
- **Affected Files**: `app/Services/Ads/AdDeliveryService.php`, `app/Services/Monetization/BannerAdvertisingService.php`
- **Fix Applied**: Excluded non-human (`! $viewer->isHumanMember()`) accounts and known crawler/bot User-Agents from Boost Post and Banner impression/click billing and counters.
- **Test Evidence**: `ProductionLaunchAuditTest` — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-010 — Suspended Communities Visible in Search and Guest `CommunityPolicy::view()`

- **Severity**: P2 – MEDIUM
- **Affected Files**: `app/Services/Social/SearchService.php`, `app/Policies/CommunityPolicy.php`
- **Fix Applied**: Added `->where('status', 'active')` in `SearchService` and denied non-staff viewing non-active communities in `CommunityPolicy::view()`.
- **Test Evidence**: `ProductionLaunchAuditTest` — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-011 — Image Decompression Bomb Ceiling (25 MP -> 16 MP RAM Guard)

- **Severity**: P2 – MEDIUM
- **Affected Files**: `app/Services/Media/SafeImageUploadService.php`
- **Fix Applied**: Added `MAX_TOTAL_PIXELS = 16_000_000` check before `imagecreatefromstring()` to protect Hostinger PHP worker memory.
- **Status**: **Fixed & Verified**

### AUD-012 — `.env.example` Duplicate Key, Unbounded Single Log, Default Locale & AdSense CSP

- **Severity**: P2 – MEDIUM
- **Affected Files**: `.env.example`, `app/Http/Middleware/SecurityHeaders.php`
- **Fix Applied**: Removed duplicate `SESSION_LIFETIME`, set `APP_LOCALE=en`, enabled `LOG_STACK=daily` + `LOG_DAILY_DAYS=14`, documented `SESSION_SECURE_COOKIE` & `TRUSTED_PROXIES`. Updated `SecurityHeaders` to allow Google AdSense domains conditionally ONLY when enabled on non-admin public pages.
- **Status**: **Fixed & Verified**

### AUD-013 — Admin System Health Center Missing Live Cache, Backup, Integration & Honest Host Telemetry Status

- **Severity**: P2 – MEDIUM
- **Affected Files**: `app/Services/Admin/AdminAnalyticsService.php`, `resources/js/pages/admin/SystemHealth.vue`
- **Fix Applied**: Added live Cache read/write probe, Backup status/type, free disk space, Payment/Webhook/GSC/AI integration readiness, 24h failed login & pending fraud review counters, and explicit honest `Unavailable — view in Hostinger hPanel` cards for CPU/RAM/PHP Workers (zero fake host numbers).
- **Test Evidence**: `ProductionLaunchAuditTest` + `vue-tsc` + `vp build` — **PASS**.
- **Status**: **Fixed & Verified**

### AUD-014 — Missing Composite Indexes & SEO Queue Priority Isolation

- **Severity**: P2 – MEDIUM
- **Affected Files**: `database/migrations/2026_11_30_950001_add_production_audit_composite_indexes.php`, `app/Jobs/Seo/ProcessSeoEntityJob.php`
- **Fix Applied**: Added additive indexes (`payments_user_status_exp_idx`, `referrals_status_eligible_idx`, `communities_vis_status_members_idx`) and routed `ProcessSeoEntityJob` to dedicated `seo` queue (`--queue=payments,high,default,seo,ai`).
- **Status**: **Fixed & Verified**

### AUD-015 — `AdminAnalyticsService` Did Not Filter `is_test_account = false`

- **Severity**: P3 – LOW
- **Affected Files**: `app/Services/Admin/AdminAnalyticsService.php`
- **Fix Applied**: Added `->where('is_test_account', false)` alongside `account_type = human`.
- **Status**: **Fixed & Verified**

### AUD-016 — High Per-Request SQL Overhead Under `CACHE_STORE=database` (101 Queries on `/`, 64 on `/robots.txt`)

- **Severity**: P1 – HIGH (Shared Hosting Performance Bottleneck)
- **Affected Files**: `app/Providers/AppServiceProvider.php`, `app/Services/System/LanguageService.php`, `app/Services/System/SystemSettingService.php`, `app/Services/Seo/SeoConfigService.php`, `app/Http/Middleware/HandleInertiaRequests.php`
- **Root Cause**: Repeated `Schema::hasTable()` (`information_schema.tables`) and database `cache` table lookups inside a single HTTP request without per-request memoization, plus `HandleInertiaRequests::share()` executing layout queries on non-Inertia endpoints (`/robots.txt`, `/sitemap.xml`, `POST` redirects).
- **Impact**: Unnecessary MySQL query pressure and PHP worker latency on Hostinger Shared Hosting.
- **Fix Applied**: Scoped `LanguageService`, `SystemSettingService`, and `SeoConfigService` per request with in-memory memoization (reset cleanly on `flushCache()`), moved `Schema::hasTable()`/`ensureSeeded()` inside cache-miss closures, and short-circuited `HandleInertiaRequests::share()` on non-GET and non-Inertia utility routes.
- **Test Evidence**: Measured query reduction on live MySQL 8.4.3 (`/robots.txt`: `64 -> 4` queries `-93.7%`, `/sitemap.xml`: `61 -> 5` queries `-91.8%`, `/topics`: `78 -> 18` queries `-76.9%` — full table in `PERFORMANCE_AUDIT.md`).
- **Status**: **Fixed & Verified**
