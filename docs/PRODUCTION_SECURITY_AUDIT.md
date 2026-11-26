# SyncViet Production Security Audit (OWASP Top 10:2025 & ASVS Level 2)

**Audit Scope**: Full source code, middleware, routes, policies, Fortify auth, uploads, API rate limits, UGC rendering, privacy/account deletion, headers, dependencies, and secret hygiene.

## 1. Authentication & Session Security (PHẦN 4)

- **Password Hashing**: `HASH_DRIVER=argon2id` verified on live DB user hashes (`password_get_info()['algoName'] === 'argon2id'`). Production password policy enforces min 12 chars, mixed case, letters, numbers, symbols, and `uncompromised()` (`AppServiceProvider::configureDefaults()`).
- **Session Hygiene**: `SESSION_DRIVER=database`, `serialization => 'json'` (prevents PHP object-injection gadget chains), `http_only => true`, `same_site => 'lax'`, `secure` auto-enables on `https://` URLs (and documented via `SESSION_SECURE_COOKIE=true` for production).
- **2FA Enforcement**: Fortify TOTP 2FA with `confirm => true` and `confirmPassword => true`. `EnsureAdminHasTwoFactor` enforces mandatory 2FA across all `/admin/*` routes.
- **Brute-Force & Rate Limiting**: Fortify `login`, `two-factor`, and custom `throttle:register` limiters active; `RecordAuthSecurityEvents` logs failed/succeeded logins to `login_histories`.
- **Reverse Proxy IP Integrity (AUD-005 Fixed)**: Implemented `TrustConfiguredProxies` replacing default `TrustProxies` via `config('syncviet.trusted_proxies')` so Cloudflare/Hostinger proxy IPs are resolved accurately without trusting spoofed `X-Forwarded-For` headers when directly exposed.

## 2. Authorization & RBAC Isolation (PHẦN 5)

- **Backend Enforcement**: 7 Eloquent Policies (`PostPolicy`, `CommunityPolicy`, `CommentPolicy`, `ConversationPolicy`, `AdCampaignPolicy`, `AccountPolicy`, `PolicyPagePolicy`), 3 Gates, and 119 granular RBAC permissions enforced server-side via `permission:*`, `role:*`, `active`, and `admin.2fa`.
- **Anti-IDOR Verified**: Users cannot view/edit/delete others' private posts, cancel/inspect others' payments (`403`), access others' conversations, or access Admin/Growth/AI/SEO/Finance endpoints (`403`).
- **Community Suspension Isolation (AUD-010 Fixed)**: `CommunityPolicy::view()` and `SearchService::search()` now strictly restrict non-active (`suspended`/`archived`) communities from guests and public search.
- **AI Agent Financial Isolation (AUD-003 Fixed)**: `AccountType::AiAgent` and `SystemOfficial` accounts are explicitly blocked from `MonetizationPaymentService::createOrder()`, `WalletService::createTopUpCheckout()`, `ReferralService::getOrCreateCode()`, and excluded from paid ad impression/click counting (`AUD-009`).

## 3. OWASP Top 10, UGC & Upload Hardening (PHẦN 6, 7, 8)

- **SQL Injection**: Zero string-concatenated user input in SQL. All 54 raw expressions audited use static SQL aggregates or bound `?` parameters (`orderByRaw` uses bindings).
- **XSS / UGC**: Zero `v-html` on user-generated content (only 2 `v-html` usages exist: Fortify backend QR SVG in `TwoFactorSetupModal.vue` and `PolicySanitizer`-filtered admin legal HTML in `policies/Show.vue`). Only 2 Blade `{!! !!}` usages exist: `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)` for JSON-LD and `nl2br(e(...))` for `<noscript>` SSR body.
- **File Uploads (`SafeImageUploadService`)**: Validates true magic bytes via `finfo`, validates via `getimagesizefromstring()`, strictly allows `image/jpeg`, `image/png`, `image/webp` (SVG, PHP, HTML, archives rejected), enforces `5 MB` cap + `5000px` per-side limit + **new `16,000,000` total pixel cap (AUD-011 Fixed)** against GD decompression bombs, re-encodes every image through GD (stripping EXIF/GPS and PHP polyglots), and stores under random UUID filenames. URL imports enforce `SafeLinkPreviewService::isSafePublicUrl()` (blocks loopback/private/RFC1918/metadata IPs) with `allow_redirects => false`.
- **Dangerous Functions & Env Hygiene**: `0` `env()` calls outside `config/`. `0` `eval()`, `unserialize()`, `shell_exec()`, `passthru()`, or `proc_open()` calls in `app/` and `routes/`.

## 4. Privacy & Account Deletion Compliance (PHẦN 17)

- **Account Deletion Hardening (AUD-006 Fixed)**: `ProfileController::destroy()` now revokes **all** rows in `sessions` for the user across devices, disables all `notification_preferences` (including marketing/digest/reengagement), deactivates the user's `referral_codes`, dispatches `ProcessSeoEntityJob(Profile, removed: true, 410)` to purge deleted profiles from public sitemaps, and writes an immutable `account.deleted_by_user` audit log while retaining financial ledger records required by accounting rules.
- **API Data Minimization**: Models use `#[Hidden]` / `#[Fillable]` so password hashes, 2FA secrets, encrypted Gemini API keys, and private payment secrets are never serialized to Inertia props or JSON.

## 5. Dependency & Supply Chain Audit (PHẦN 40)

- **`npm audit`**: **0 vulnerabilities** (`0` critical, `0` high, `0` moderate, `0` low across 325 packages).
- **`composer audit`**: **7 advisories affecting 4 Symfony 8.x packages** disclosed `2026-11-25` (`AUD-008`, fixed in `8.1.12`):
    - `symfony/http-kernel` (`CVE-2026-133743`)
    - `symfony/mailer` (`CVE-2026-133751`, `CVE-2026-133755`)
    - `symfony/routing` (`CVE-2026-133754`)
    - `symfony/serializer` (`CVE-2026-133761`, `CVE-2026-133752`)
    - **Recommended Owner Action before Go-Live**: Run `composer update symfony/http-kernel symfony/mailer symfony/routing symfony/serializer` and re-run `composer audit` & `php artisan test` (deferred from automatic execution per `AGENTS.md` dependency change approval rule).

## 6. Secret Scanning (PHẦN 41)

- Static scan across `app/`, `config/`, `routes/`, `resources/` found **0 leaked credentials** (only 1 UI placeholder string in `Integrations.vue:446`).
- `.env` is excluded by `.gitignore`; `.env.example` cleaned (AUD-012) with zero real secrets.
