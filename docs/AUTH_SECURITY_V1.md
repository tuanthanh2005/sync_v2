# SyncViet — Advanced Authentication Security, Email Verification & Anti-Bot Protection V1.0

## 1. Executive Summary & Root Cause Audit (P0 / P1 / P2 / P3)

An end-to-end authentication security audit against **OWASP Top 10:2025** and **OWASP ASVS Level 2** identified critical vulnerabilities allowing unverified registrations to operate as authenticated members on SyncViet.com. All findings were remediated directly at the backend architecture layer without replacing Laravel Fortify or touching production data.

### Severity Matrix

| Severity          | Finding                                                                                                        | Root Cause                                                                                                                                                                          | Affected Files                                                                                                                                          | Fix Implemented                                                                                                                                                                                                                                                                                                                                                                |
| ----------------- | -------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **P0 (Critical)** | Unverified accounts created directly in `users` and immediately authenticated on `POST /register`              | Fortify's default `RegisteredUserController` inserted into `users` and called `Auth::login()` immediately before verifying email ownership.                                         | `routes/web.php`, `app/Actions/Fortify/CreateNewUser.php`                                                                                               | Implemented **Pending Registration Architecture** (`pending_registrations` + `PendingRegistrationController` + `PendingRegistrationService`). Zero official `users` row or full member session is granted before OTP/confirmation link verification succeeds.                                                                                                                  |
| **P0 (Critical)** | Authenticated unverified users could browse `/` News Feed and call member routes/APIs                          | Public/member routes (`/`, `/posts`, `/communities`, `/u/*`, `/payments`, `/user/two-factor-*`) did not enforce a global verified-email guard across authenticated sessions.        | `bootstrap/app.php`, `routes/web.php`, `app/Policies/*`                                                                                                 | Added global `EnsureAuthenticatedEmailIsVerified` middleware in `web` stack right after `CheckMaintenanceMode` (redirects web to `/email/verify`, returns `403 JSON {code: "EMAIL_NOT_VERIFIED"}` for XHR/API) plus defense-in-depth `hasVerifiedEmail()` checks across Policies, `SearchService`, `ProfileViewController`, `MonetizationPaymentService`, and Growth services. |
| **P0 (Critical)** | Unverified existing users logging in via password received full member session                                 | `Fortify::authenticateUsing()` authenticated any active user regardless of `email_verified_at`.                                                                                     | `app/Providers/FortifyServiceProvider.php`                                                                                                              | Unverified password logins now redirect to `/email/verify` inside a restricted verification-only session (`auth.unverified_user_id`) without calling `Auth::login()`, triggering a fresh 6-digit OTP email.                                                                                                                                                                    |
| **P0 (Critical)** | `email_verified_at` mass-assignable on `User` model                                                            | `#[Fillable([... 'email_verified_at'])]` allowed mass assignment of verification timestamps.                                                                                        | `app/Models/User.php`, `app/Http/Controllers/Auth/SocialAuthController.php`, `app/Services/Ai/AiAgentService.php`                                       | Removed `email_verified_at` from `User` `#[Fillable]`; verified timestamps are now assigned exclusively via explicit server-owned code / `forceFill()`.                                                                                                                                                                                                                        |
| **P1 (High)**     | Direct unverified email change via `PATCH /settings/profile`                                                   | `ProfileController::update()` mutated `$user->email` immediately without verifying current password, without OTP confirmation on the new email, and without alerting the old email. | `app/Http/Controllers/Settings/ProfileController.php`, `app/Http/Requests/Settings/ProfileUpdateRequest.php`, `resources/js/pages/settings/Profile.vue` | Blocked direct email mutation on `PATCH /settings/profile`; enforced `EmailChangeController` (`POST /settings/email/change-request` + `POST /settings/email/verify-otp`) requiring current password, keeping old email active until new OTP verified, and notifying old email immediately.                                                                                     |
| **P1 (High)**     | Zero anti-bot protection, disposable email filter, or global OTP email budget                                  | Signup, forgot-password, and OTP resend endpoints lacked Cloudflare Turnstile, disposable email blocking, and global SMTP exhaustion caps.                                          | `app/Services/Auth/*`, `resources/js/components/auth/TurnstileWidget.vue`                                                                               | Implemented fail-closed `TurnstileService` (encrypted secret, replay cache, action + trusted hostname validation, CSP allowlist), `DisposableEmailService` (built-in + Admin-managed domains preserving corporate & major providers), and `AuthRateLimiterService` (IP + normalized email + account + global hourly email budget).                                             |
| **P1 (High)**     | Password reset did not revoke active DB sessions or rotate `remember_token`; 60-min token window               | `ResetUserPassword` updated password only without clearing `sessions` rows or rotating `remember_token`; `config/auth.php` allowed 60-min expiry.                                   | `app/Actions/Fortify/ResetUserPassword.php`, `config/auth.php`, `app/Http/Controllers/Auth/SecureForgotPasswordController.php`                          | Shortened reset expiry to `15` min (`AUTH_PASSWORD_RESET_EXPIRE`), enforced anti-enumeration uniform response + Turnstile on `POST /forgot-password`, atomically revoked ALL DB sessions + rotated `remember_token` on reset, explicitly preserved `email_verified_at` & 2FA, never auto-logged in, and dispatched `SecurityAlertNotification`.                                |
| **P1 (High)**     | Social OAuth Pre-Account-Takeover & missing `email_verified` claim enforcement                                 | Pre-registered unverified password accounts retained attacker password & sessions when victim later linked Google OAuth.                                                            | `app/Http/Controllers/Auth/SocialAuthController.php`                                                                                                    | Enforced `email_verified` claim check when returned by provider, invalidated pre-existing unverified password hash (`has_password = false`), rotated `remember_token`, deleted pre-existing sessions, and honored Emergency Registration Pause.                                                                                                                                |
| **P2 (Medium)**   | Email link scanners could pre-fetch GET verification URLs & OTPs lacked purpose binding                        | Standard GET links risk automated corporate/antivirus link scanners triggering unintended state mutations.                                                                          | `app/Services/Auth/EmailVerificationChallengeService.php`, `app/Http/Controllers/Auth/EmailVerificationChallengeController.php`                         | Cryptographic 6-digit OTP (`HMAC-SHA256`, 5-min TTL, max 5 attempts, 60s resend cooldown, cumulative brute-force budget preserved across resends, strict `purpose` + `email` binding) + scanner-safe confirmation link (`GET /verify-email/confirm/{token}` renders read-only screen; activation requires `POST` with CSRF + `DB::transaction`/`lockForUpdate`).               |
| **P2 (Medium)**   | No automated cleanup for abandoned registrations or non-destructive remediation for legacy unverified sessions | Abandoned unverified signups accumulated without TTL pruning; legacy unverified sessions remained valid.                                                                            | `routes/console.php`, `app/Services/Auth/PendingRegistrationService.php`                                                                                | Added scheduled hourly `app:auth:cleanup-pending` (chunked, Hostinger-safe) and `app:auth:remediate-unverified` (revokes sessions & rotates `remember_token` for unverified accounts without deleting data, posts, balances, or auto-verifying).                                                                                                                               |
| **P3 (Low)**      | Lack of centralized Auth Protection visibility, Emergency Pause, & anomaly telemetry                           | Admins lacked real-time auth attack KPIs, Turnstile key management, disposable domain rules, and one-click registration pause.                                                      | `app/Services/Admin/AdminSecurityService.php`, `app/Http/Controllers/Admin/SecurityAuditController.php`, `resources/js/pages/admin/Security/Index.vue`  | Added `/admin/security?tab=protection` with 12 live KPIs, Emergency Registration Pause, encrypted Turnstile settings, OTP/rate-limit controls, disposable domain CRUD, pending queue, and frequency-capped Admin anomaly alerts.                                                                                                                                               |

---

## 2. Authentication Flow — Before vs After

### Before (Vulnerable)

1. Visitor submits `POST /register` → Fortify inserts row directly into `users` (`email_verified_at = null`) and immediately calls `Auth::login($user)`.
2. Unverified session can navigate back to `/`, interact with endpoints lacking `verified` middleware, or retain a persistent `remember_token`.
3. `PATCH /settings/profile` directly mutated `users.email` without verifying current password or new email ownership.

### After (Hardened V1.0)

1. Visitor submits `POST /register`:
    - Checks Emergency Registration Pause & `feature_registration`.
    - Validates Honeypot, Cloudflare Turnstile (fail-closed, replay-protected, action + hostname verified), multi-dimensional Rate Limits (IP / normalized email / global OTP budget), and Disposable Email blocklist.
    - Inserts record into `pending_registrations` ONLY (no `users` row, `Auth::guest() === true`).
    - Stores limited verification session token (`auth.pending_registration_token`) and sends 6-digit OTP + scanner-safe confirmation URL via `AuthOtpVerificationNotification`.
    - Redirects to `/email/verify` displaying _"Vui lòng xác minh địa chỉ email để hoàn tất đăng ký tài khoản."_ (localized across 15 languages).
2. User enters 6-digit OTP (`POST /email/verify/otp`) OR clicks link (`GET /verify-email/confirm/{token}` read-only → `POST /verify-email/confirm/{token}`):
    - Verifies `HMAC-SHA256` OTP hash or `SHA-256` token hash inside `DB::transaction()` with `lockForUpdate()`.
    - Atomically creates verified `users` record (`email_verified_at = now()`, `UserRole::User`, `UserStatus::Active`, `AccountType::Human`), invalidates remaining challenges, triggers Organic/Referral/Onboarding/EarlyMember attribution, logs in, and regenerates session ID.
3. Any existing unverified account:
    - Logging in via password enters restricted `auth.unverified_user_id` session (not `Auth::login()`) and redirects to `/email/verify`.
    - Any authenticated unverified session attempting to hit any non-allowlisted route is immediately blocked by `EnsureAuthenticatedEmailIsVerified` (`302 -> /email/verify` for web; `403 JSON` for XHR/API).

---

## 3. Database Schema (Additive, Non-Destructive)

Migration: `database/migrations/2026_12_02_970001_create_auth_security_and_pending_registration_tables.php`

- `pending_registrations` — Pre-activation registration records (`public_token`, `normalized_email`, `email_fingerprint`, `password_hash`, `risk_score`, `expires_at`, `status`).
- `auth_verification_challenges` — Purpose-bound cryptographic challenges (`purpose`, `otp_hash` HMAC-SHA256, `link_token_hash` SHA-256, `attempts`, `max_attempts`, `expires_at`, `used_at`, `invalidated_at`).
- `auth_security_events` — Scrubbed security telemetry (never stores OTPs, passwords, Turnstile secrets, or raw tokens).
- `disposable_email_domains` — Admin-extensible disposable email blocklist layered over built-in burner domains.

---

## 4. Production Configuration Checklist (Hostinger Shared Hosting / VPS)

```env
# Trusted domain origin (used for signed URLs, password resets, and Turnstile hostname validation)
APP_URL=https://syncviet.com

# Cloudflare Proxy / Hostinger Reverse Proxy IP trust (set explicitly if behind Cloudflare)
TRUSTED_PROXIES=

# Cloudflare Turnstile (configure in .env OR encrypted via Admin Center -> Security -> Auth Protection)
AUTH_TURNSTILE_ENABLED=true
TURNSTILE_SITE_KEY=your_cloudflare_turnstile_site_key
TURNSTILE_SECRET_KEY=your_cloudflare_turnstile_secret_key

# Shortened Password Reset Token Expiry (minutes)
AUTH_PASSWORD_RESET_EXPIRE=15

# SMTP (already verified live on Hostinger)
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=support@syncviet.com
MAIL_FROM_ADDRESS=support@syncviet.com
MAIL_FROM_NAME=SyncViet
```

### Post-Deploy Artisan Commands (Run Manually When Deploying)

```bash
php artisan migrate --force
php artisan app:auth:remediate-unverified
php artisan optimize:clear && php artisan optimize
```
