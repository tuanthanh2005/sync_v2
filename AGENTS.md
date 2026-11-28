<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.

- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>

# SyncViet Project Engineering Rules (Mandatory)

Read `docs/PROJECT_PLAN.md`, `docs/ARCHITECTURE.md`, `docs/DATABASE.md`, `docs/BUSINESS_RULES.md`, `docs/SECURITY.md`, `docs/SECURITY_CHECKLIST.md`, `docs/DESIGN_SYSTEM.md`, `docs/ADMINISTRATION.md`, `docs/PERMISSION_MATRIX.md`, `docs/MODERATION_GUIDE.md`, `docs/LOCALIZATION.md`, `docs/FEATURE_MANAGEMENT.md`, `docs/MOBILE_UI_UX.md`, `docs/MOBILE_COMPATIBILITY.md`, `docs/PWA_GUIDE.md`, `docs/PERFORMANCE_OPTIMIZATION.md`, `docs/MOBILE_TESTING.md`, `docs/DEPLOYMENT_HOSTINGER.md`, `docs/DEPLOYMENT_VPS.md`, `docs/MIGRATION_GUIDE.md`, `docs/SEO_ARCHITECTURE.md`, `docs/SEO_SECURITY.md`, `docs/SEO_ADMIN_GUIDE.md`, and `docs/TASKS.md` before starting any task.

## 1. Fixed Technology Stack

- **PHP 8.4**, **Laravel 13**, **MySQL / MariaDB** (InnoDB, `utf8mb4_unicode_ci`).
- **Vue 3** (`<script setup lang="ts">`), **TypeScript** (strict, zero arbitrary `any`), **Inertia.js 3**, **Tailwind CSS 4**, **shadcn-vue** (`reka-ui`), **@lucide/vue**, **vue-i18n**, **chart.js**, **Vite** (`vite-plus`), **Laravel Fortify**, **Laravel Wayfinder**, **Pest PHP 5**, **Laravel Boost** (dev only).
- **Do not** downgrade or swap stack components, do not install redundant packages, and do not add Docker-only runtime requirements, Supervisor-only assumptions, Node SSR daemon, jQuery, Bootstrap, React, or Livewire.

## 2. Server Portability & Production Constraints

- Must run cleanly on **Hostinger Shared Hosting** (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, Cron-driven `schedule:run` + bounded `queue:work --stop-when-empty --max-time=40 --timeout=25`, `ssr.enabled => false`, Vite static build in `public/build/`).
- **Official Product Identity (Global Brand Migration & Stylized "S" Logo — Mandatory for ALL New Work)**:
    - **Official Product Name**: **`SysNoxis`** (exact capitalization; NEVER `Sysnoxis`, `Sys Noxis`, `SYSNOXIS`, or legacy `SyncViet` in any new user-facing copy, UI, emails, SEO metadata, notifications, PWA, or AI prompts).
    - **Official Brand Slogan**: **`Connect Beyond Borders.`** (`brand.tagline` translated across all 15 locales; `Kết nối vượt mọi biên giới.` in Vietnamese; `system_settings.site_slogan` default).
    - **Official Brand Mark & Centralized Branding System**: Minimalist, futuristic **stylized geometric "S"** monogram (dual 180°-symmetric interlocking ribbons with 45° diagonal nexus seam; strictly flat using Light `#4F46E5` / Dark `#818CF8` / `#FFFFFF` / `#0B0F17`, zero gradients/3D/shadows; replaces legacy 3-dot triangle). Managed centrally via `config/syncviet.php` (`branding.asset_version = 'sysnoxis-2'`, `branding.assets.*`), `SystemSettingService::brandingPayload()` (exposing Admin overrides `logo_light_path`, `logo_dark_path`, `favicon_path`, `og_image_path` with automatic SysNoxis fallbacks), `AppLogoIcon.vue`, `AppLogo.vue`, `app.blade.php`, `errors/minimal.blade.php`, `vendor/mail/html/header.blade.php`, `/og-default.svg`, and internal assets under `public/brand/*`, `public/icons/*`, `public/favicon.{svg,ico}`, and `public/apple-touch-icon.png`. Never hardcode logo URLs or external brand images across components.
    - **Official Primary Domain**: `https://sysnoxis.com` (attached as a parked alias domain on the existing Hostinger Business vhost `~/domains/syncviet.com/syncviet` sharing the single codebase and `u137711404_syncviet` MySQL database).
    - **Legacy Domain Redirect**: `https://syncviet.com`, `https://www.syncviet.com`, and `https://www.sysnoxis.com` permanently **HTTP 301** redirect (preserving path + query string) to `https://sysnoxis.com` via `EnforceCanonicalDomainMiddleware` (`DOMAIN_REDIRECT_ENABLED=true`, `DOMAIN_LEGACY_HOSTS=syncviet.com,www.syncviet.com`), strictly exempting `/webhooks/*`, `/up`, `/.well-known/*`, and `/google*.html`.
    - **Market & Language**: Global market orientation; default & fallback language is **English (`en`)** across 15 supported locales (`SysNoxis` is non-translatable across all 15 locales).
    - **Technical Infrastructure Preservation**: Keep internal identifiers (`config/syncviet.php`, `syncviet.*` cache keys, `X-SyncViet-*` headers, `u137711404_syncviet` DB, `~/domains/syncviet.com/syncviet` Hostinger path, and legacy redirect configuration) intact.
- Must remain **100% portable to VPS / Cloud Server without code rewrites**:
    - Never hardcode absolute server paths, IPs, domains, brand names, or database credentials. Read all runtime settings from `.env` and `config/*.php` (`config('app.name')`, `config('app.url')`, `config('syncviet.media_disk')`).
    - Use Laravel Storage Abstraction (`Storage::disk(...)`) supporting `local`, `public`, and `s3` (Cloudflare R2).
    - Support switching `CACHE_STORE` / `QUEUE_CONNECTION` / `SESSION_DRIVER` between `database` and `redis` solely through `.env`.
    - Maintain `composer.lock`, `package-lock.json`, and database portability through Laravel Migrations.
    - Never depend on Hostinger-proprietary features; preserve `APP_KEY` across server migrations.

## 3. Source Code Architecture (Modular Monolith + Service Layer)

- **Controllers**: Handle HTTP request/response and Inertia/JSON rendering only.
- **Form Requests**: Validate and normalize all incoming user input (`app/Http/Requests`).
- **Services / Actions**: Encapsulate reusable domain logic (`app/Services/{Domain}`, `app/Actions/{Domain}`).
- **Policies / Gates / RBAC**: Authorize every non-public resource, mutation, and staff route in backend (`app/Policies`, `EnsureUserHasRole`, `EnsureUserHasPermission`, `EnsureUserIsActive`, `EnsureAdminHasTwoFactor`, `CheckMaintenanceMode`, `RbacService`).
- **Models**: Relationships, casts, scopes, and explicit `#[Fillable]` excluding privilege/financial columns (`role`, `rbac_role_id`, `status`, balances).
- **Localization (Global i18n V1.0 — Mandatory for ALL New Features)**: Single source of truth in `config/localization.php` + `LanguageService` (`en` default & fallback; 15 active locales: `en`, `vi`, `es`, `pt-BR`, `id`, `hi`, `th`, `fil`, `fr`, `de`, `ja`, `ko`, `zh-CN`, `ar` full RTL, `tr`), lazy-loaded frontend JSON packs (`resources/js/i18n/locales/{code}.json`), backend JSON packs (`lang/{code}.json`), and Laravel standard PHP lang packs (`lang/{code}/{auth,pagination,passwords,validation}.php`). `User` implements `HasLocalePreference` for recipient-locale email/queue isolation; `SocialActivityNotification` stores `title_key` + `title_params` for 15-language rendering. **MANDATORY DEVELOPMENT RULE**: Every new page, component, button, modal, aria-label, toast, email, notification, or API error MUST (1) create semantic translation keys (zero hardcoded UI text, zero `isLocale('vi')` shortcuts), (2) add English source copy, (3) translate all 15 supported locales preserving `{param}`/`:param` placeholders and `docs/TRANSLATION_GLOSSARY.md` semantics, (4) use RTL-safe CSS logical utilities (`ms-*`/`me-*`/`text-start`/`text-end`), and (5) pass `php artisan localization:audit --strict` before marking any feature or release complete. Never hardcode brand names or URLs in components; never let locale switching alter payment amounts, currencies, referral attribution, or sessions. See `docs/LOCALIZATION_ARCHITECTURE.md`, `docs/INTERNATIONALIZATION_GUIDELINES.md`, `docs/TRANSLATION_GLOSSARY.md`, and `docs/TRANSLATION_COVERAGE_REPORT.md`.

## 4. UI / UX, Toast System & Footer Standards

- Light Palette: Primary `#4F46E5` (Hover `#4338CA`, Active `#3730A3`, Soft `#EEF2FF`), Background `#F3F5F9`, Surface `#FFFFFF`, Text `#0F172A` / `#64748B`, Border `#E2E8F0`, Semantic `#16A34A` / `#D97706` / `#DC2626`. Dark Palette: Primary `#818CF8` (`primary-foreground: #0B0F17`, Hover `#A5B4FC`, Soft `#25264A`), Background `#0B0F17`, Surface `#161B26` (Hover `#1E293B`), Text `#F1F5F9` / `#94A3B8`, Border `#273244`, Semantic `#4ADE80` / `#FBBF24` / `#F87171`. Enforce ~90% neutral / 10% Indigo brand (no purple gradients, no duplicate sidebar promo cards at desktop widths), font `Be Vietnam Pro`, `@lucide/vue` icons, Loading Skeleton, Empty State, Error State, `ConfirmActionDialog`/`ReasonDialog` (zero browser `alert()`/`confirm()`), and unified `appToast` + `Sonner.vue` (X close button, Escape key close, hover-pausing progress bar, deduplication, Light/Dark & RTL support).
- **Administration Center**: Dedicated collapsible, responsive `AdminLayout.vue` grouped into 11 modules (`Overview`, `User Management`, `Content Management`, `Moderation`, `Advertising`, `Monetization`, `Finance`, `SEO Center`, `AI Content Center`, `Growth Center`, `System`), automatically applied to all `admin/*` Inertia pages, with Mobile Drawer & Mobile Card Lists on `< md`.
- **User Growth, Referral, Early Member & Retention System V1.0**: Unique PII-free `SV-XXXXXX` referral codes for real human members (strictly excluding AI Agents, System Official, Test Accounts), SEO-safe `/r/{code}` landing (`X-Robots-Tag: noindex, nofollow`, First-Touch `sv_ref_code` cookie, bot exclusion, sanitized internal `?next=`), anti-abuse Qualification Engine (verification hold `72h`, email verification, onboarding/activity, Gmail dot/plus self-referral & loop blocks, multi-signal velocity fraud scoring routing to Manual Review WITHOUT auto-banning shared Wi-Fi users), cumulative delta milestone Reward Engine (`User::lockForUpdate()`, deterministic `idempotency_key`, `PremiumSubscriptionService::grantPromotionalPremium()` stacking on paid Premium with `payment_id = null, amount_paid = 0` — zero revenue contamination, and `revokePromotionalPremium()` preserving paid Premium floor), atomic concurrency-safe Early Member Program (max 1,000, non-reusable `member_number`, permanent `EarlyMember` badge in `MemberBadges.vue` strictly distinct from `#1D9BF0` Premium & `AiBadge.vue`), Founding Member readiness, value-driven Achievements, optional skippable Onboarding (`/onboarding`, zero auto-join/auto-follow), frequency-capped Retention & signed one-click unsubscribe (`/growth/unsubscribe/...`), human-only daily precomputed `growth_analytics` snapshots, and 3 Admin Growth Center pages (`/admin/growth/*`) with 8 `growth.*` RBAC permissions & formula-safe CSV export. Reward/Early Member/Retention programs default OFF/Draft until Admin activates. See `docs/USER_GROWTH_SYSTEM.md`, `docs/REFERRAL_*.md`, `docs/EARLY_MEMBER_PROGRAM.md`, `docs/USER_ONBOARDING.md`, `docs/USER_RETENTION.md`, `docs/GROWTH_*.md`.
- **Production Security, Performance & Launch Audit V1.0**: Global cross-service Max-5 pending payment order cap, AI Agent financial & paid-ad exclusion, currency-validated HMAC webhooks with late-payment reconciliation logging, full Premium refund subscription revocation, config-driven `TrustConfiguredProxies`, complete account deletion hygiene (all sessions revoked, marketing opt-out, referral code disabled, 410 SEO de-index), 16 MP GD decompression guard, request-scoped memoization (`LanguageService`, `SystemSettingService`, `SeoConfigService`, non-Inertia `HandleInertiaRequests` short-circuit cutting `/` queries 70%+), queue priority (`payments,high,default,seo,ai`), automated daily private `.sql.gz` logical backups (`app:backup:database`) verified via isolated MySQL restore test, upgraded honest `/admin/system-health`, and full audit suite in `docs/PRODUCTION_READINESS_REPORT.md` & `docs/SECURITY_ISSUES.md`.
- **Advanced Authentication Security, Email Verification & Anti-Bot Protection V1.0**: Mandatory **Pending Registration Architecture** (`pending_registrations`, `PendingRegistrationService` — zero official `users` row or full member session before email verification succeeds), global backend verification guard (`EnsureAuthenticatedEmailIsVerified` blocking unverified sessions across web & API with 302 `/email/verify` or 403 JSON `EMAIL_NOT_VERIFIED`), defense-in-depth `hasVerifiedEmail()` checks across Policies (`PostPolicy`, `CommentPolicy`, `CommunityPolicy`, `AccountPolicy`), `SearchService`, `ProfileViewController`, `MonetizationPaymentService`, and Growth services, `email_verified_at` excluded from `User` `#[Fillable]`, cryptographic 6-digit OTP (`HMAC-SHA256`, 5-min expiry, max 5 attempts, 60s resend cooldown preserving brute-force budget, zero plaintext storage/logs/frontend leaks) + scanner-safe confirmation link (`GET /verify-email/confirm/{token}` read-only -> `POST` with CSRF + `DB::transaction`/`lockForUpdate`), fail-closed **Cloudflare Turnstile** (`TurnstileService`, encrypted secret, single-use replay cache, action + trusted hostname checks, CSP allowlist), multi-dimensional rate limiting (`AuthRateLimiterService` + progressive login Turnstile + global hourly OTP budget), updatable disposable email blocklist (`DisposableEmailService`, allowing major & corporate domains), hardened Forgot/Reset Password (15-min expiry, anti-enumeration uniform response, trusted `APP_URL` links, revokes all DB `sessions` + rotates `remember_token`, never auto-logins/auto-verifies/disables 2FA), secure Change Email (`EmailChangeController`: requires current password, keeps old email active until new OTP verified, alerts old email; `PATCH /settings/profile` rejects direct email mutation), Social OAuth Pre-Account-Takeover defense, hourly cleanup (`app:auth:cleanup-pending`) & safe non-destructive unverified session remediation (`app:auth:remediate-unverified`), and Admin Security Protection center (`/admin/security?tab=protection`) with Emergency Registration Pause. See `docs/AUTH_SECURITY_V1.md`.
- **AI Content Center & Multilingual Automation V1.0**: Multi-provider abstraction (`AiProviderInterface`) + Gemini REST `v1beta` (`x-goog-api-key` header only, never query param/logs/Inertia), unlimited encrypted keys (`ai_credentials`, `Crypt::encryptString`, masked `••••••••last4`), honest Project + Model shared quota (`ai_quota_tracking`, zero rate-limit bypass rotation, `waiting_for_quota` + `Retry-After`), verified active models (`gemini-2.5-flash-lite`, `gemini-2.5-flash`, opt-in paid-only `gemini-2.5-pro`), official transparent AI Agents (`account_type = ai_agent`, `UserRole::User`, distinct `AiBadge.vue`, excluded from Human DAU/MAU in `AdminAnalyticsService`), versioned prompt templates (allowlisted `{{var}}` only, zero eval, prompt-injection sanitization), SSRF-safe sources, Structured JSON generation -> Quality Gate -> Mandatory Human Review -> edit-resets-approval -> DB-locked idempotent publishing through `PostService` + SEO V2.0 `ProcessSeoEntityJob`, anti-flood scheduler (`app:ai:publish-scheduled` skips stale >24h), 15-language translation into `seo_content_translations`, compliant Comment Assistant (forbids AI-to-AI loops, respects `UserBlock`), advisory-only Moderation Assistant, hard token/cost ceiling gate, and backend-enforced Emergency Stop. See `docs/AI_*.md` and `docs/GEMINI_API_INTEGRATION.md`.
- **Advanced International SEO & Organic Growth Engine V2.0**: Server-rendered zero-cloaking `<noscript>` public HTML (`partials/seo-ssr.blade.php`), trusted-origin `SeoConfigService`/`CanonicalUrlService` (never trusting `Host` header), `SeoQualityGateService` (never bypassed by Premium/Boost), central `seo_url_registry` + incremental after-commit `ProcessSeoEntityJob`, chunked 5,000-URL atomic XML sitemap shards (`/sitemap.xml`, ETag/304), dynamic `/robots.txt`, anti-loop `seo_redirects`, 15-language `seo_content_translations` workflow + reciprocal `HreflangService` validator, factual JSON-LD (`StructuredDataService`), crawlable `/topics` clusters, honest Google Site Verification + encrypted Google Search Console API (Sites, Sitemaps, Search Analytics, URL Inspection — strictly zero Google Indexing API abuse), Bing IndexNow, controlled GA4 (zero raw JS), Cloudflare purge-only, automated `SeoAuditService`, and privacy-safe `OrganicGrowthService` funnel. See `docs/SEO_*.md`.
- **Complete Monetization System**: Services Center (`/services`, `/premium`, `/advertising`, `/advertising/banner`, `/advertising/boost`), **SyncViet Premium** (`#1D9BF0` `PremiumBadge.vue` strictly distinct from Identity Verification, direct checkout only, zero-loss extension, backend-enforced expiry), **Banner Advertising** (9 desktop/mobile placements, `Exclusive` double-booking lock & `Rotation` cap, Emergency Pause, SSRF-safe creative uploads/URL imports), **Boost Post** (fixed-duration packages on `ad_campaigns`, approval-time duration start, never fakes Trending), **Advertising Balance** (`/wallet`, strictly for advertising — zero withdrawals/P2P/interest), **Centralized Payment History** (`/payments`), manual Yes/No confirmation (`PaymentConfirmModal`), strict **max 5 open pending/processing orders per user**, disabled-by-default crypto adapters, controlled Google AdSense readiness (zero raw JS), and `PlatformCapabilityService` (`web`/`pwa`/`android_play`/`ios_appstore`). See `docs/MONETIZATION.md`, `docs/PREMIUM_MEMBERSHIP.md`, `docs/BANNER_ADVERTISING.md`, `docs/BOOST_POST.md`, `docs/PAYMENT_ARCHITECTURE.md`, `docs/ADVERTISING_BALANCE.md`, `docs/FINANCIAL_SECURITY.md`, `docs/ADSENSE_INTEGRATION.md`, `docs/MOBILE_PAYMENT_POLICY.md`, and `docs/MONETIZATION_ADMIN_GUIDE.md`.
- **Mobile First & PWA Standards**: `viewport-fit=cover` (never disable pinch-to-zoom), `16px` mobile inputs to prevent iOS auto-zoom, `touch-action: manipulation`, safe-area insets (`env(safe-area-inset-*)`), `100dvh` modals/bottom-sheets/full-screen mobile composer, full-screen pinch-zoom `ImageLightbox`, Visual Viewport keyboard-aware `MobileBottomNav`, normal OS text selection & copy/paste everywhere, dynamic-imported 14 non-English locale chunks, and secure static-only PWA Service Worker (`public/sw.js` strictly excluding `/admin`, `/wallet`, `/payments`, `/webhooks`, `/messages`, auth & Inertia XHR).
- **Global Layout Stability & Modern Auto-Hide Scrollbars**: Keep `html { scrollbar-gutter: stable; }` (never put `overflow-x: hidden/clip` on `html`/`body`), persistent `AppProviders.vue` (`<ConfigProvider :scroll-body="false">`), non-modal `DropdownMenu` (`modal: false`), non-locking `SelectContent` (`bodyLock: false`), and unified macOS-style `4px` (`6px` hover, transparent track, zero arrow buttons, `3000ms` auto-hide via zero-cascade state selectors in `app.css` + cached/throttled `useAutoHideScrollbar.ts` + `AutoHideScrollArea.vue`, never `@property ... inherits: true`; preserve native OS scrollbars on Mobile). Payment/Checkout/Confirmation modals must use `DialogContent structured` (Fixed Header + `<AutoHideScrollArea>` Body + Fixed Footer). Never use `transition-all`, `backdrop-blur-*` on overlays/sticky chrome, `w-screen`, `100vw` on layout containers, or hardcoded `padding-right` scrollbar hacks.
- **Global Modal Performance, Smooth Scrolling & Interaction V3.0**: Use `DialogOverlay.vue`/`SheetOverlay.vue` (`bg-slate-950/50`, zero blur, 150ms/100ms), `DialogContent.vue` (`size` presets `sm|md|lg|xl|full`, `structured` header/body/footer separation, `150ms` open / `100ms` close transform+opacity animations, `motion-reduce:animate-none`), `useModalRuntimeLock.ts` integrated into `Dialog.vue`/`Sheet.vue`/`ImageLightbox.vue` so `usePostViewTracker.ts` and `Welcome.vue`/`Messages.vue` polling pause cleanly when any modal is active, lazy `v-if` mounting on card modals (`PostCard.vue`), and rAF/debounced textarea + draft updates in `PostComposer.vue` (zero synchronous keystroke reflow or blocking `JSON.stringify`).
- **Global Sidebar Simplification & Social Navigation UX**: Keep `ConnectHubLayout.vue` Left Sidebar social-first (1 `Create Post` CTA reusing `PostComposer` + max 8 nav items: Feed, Explore, Communities, Profile, Workspace | subtle divider | `{brand} Premium`, Invite & Earn, `More` popover via `SidebarMoreMenu.vue` grouping Advertising Center, Settings & Privacy, Help & Support from approved `footer.links`, and RBAC-gated Admin), render unified `AdvertisingCenterNav.vue` across `/advertising*`, `/wallet`, `/payments`, `/ads` (wallet balance shown inside Advertising Center/More Menu — never truncated in main sidebar), provide Tablet (`md`–`< lg`) 72px Icon Rail with Tooltips and Mobile (`< md`) 5-tab `MobileBottomNav` + collapsible Drawer, and show only genuine non-empty social signals (Trending Hashtags, Featured Communities, cached `notableAccounts`, active Banner) + `MiniFooter` in Right Sidebar (zero empty card stacks, zero repeated ad sales boxes).
- **Advanced Post View Tracking V2.0 (Social News Feed Standard)**: Replaces legacy 2s/30m(6h) rules. Records a view when `>= 50%` of the post (or representative viewport probe for tall posts) is continuously visible for `>= 500ms` in an active browser tab (`document.visibilityState === 'visible'`) with server-side race-safe **5-minute (`300s`) deduplication** per `(post_id, viewer_hash)` (`user:{id}` for human/staff, `session:{id}` for guests, `ai_agent:{id}` for AI Agents). Excludes author self-views by default (`views_track_author_self = false`), Googlebot/crawlers, prefetch headers, background jobs, automated test accounts, and system official accounts. Supports classified `ai_view` (web UI) and `ai_content_access` (backend AI reading/processing content via authorized reply/translation tasks — never list fetches, publishing, or SEO sync); strictly excludes AI views from `unique_human_viewers_count`, Human DAU/MAU, Organic Human Traffic, Referral Qualification, and Paid Ad Impressions. Returns backend-confirmed live counters in `POST /posts/views/batch` to update PostCards & Post Detail synchronously without reload; preserves historical pre-V2 counts honestly in `legacy_views_count`; Admin-configurable via `/admin/settings?tab=views` with Audit Log. See `docs/POST_VIEW_TRACKING_V2.md`.
- **Advanced Post Publishing, Anti-Spam & Feed Ranking System V1.0**: Unified submission pipeline (`PostService`, `PostStoreRequest`, `PostUpdateRequest`, `CommentService`) across Home, Profile, Community, Mobile/Desktop, AI Content Center, and Admin actions. Enforces Trust Tiers (`TrustLevelResolver`: `NewMember` `< 7d` safety guardrail even with Premium -> `4` images, `StandardMember` -> `4`, `PremiumMember` -> `8`, `RestrictedMember`, `AiAccount`, `Staff`), row-locked rolling-window quotas (`PostingRateLimitService`, `publishing_quota_events` returning HTTP `429` + `Retry-After` without resetting quota on post deletion or consuming quota on validation errors), double-click/retry idempotency (`PostIdempotencyService`, `post_idempotencies`), exact/near-duplicate & coordinated-burst detection (`DuplicateDetectionService`), multi-signal anti-spam scoring (`AntiSpamEngine`, `spam_events` routing risky content to private `PendingReview` or `Rejected` with author-only moderation banners), lifecycle states (`PostLifecycleStatus`: `draft`/`processing`/`published`/`pending_review`/`rejected`/`hidden`/`deleted`), Edit safeguards (`edited_at`/`edit_count` preserving `published_at`), time-decayed organic `TrendingRankingService` `(V_h·w_v + R_v·w_r + C_v·w_c + B_v·w_b + S_v·w_s) / (1 + AgeHours/base)^exp` excluding author self/AI/boosted views + abnormal view dampening + deduplicated `PostShare`, personalized + cold-start `70/20/10` diversity-aware `RecommendedFeedService` (`following`, `recommended`, `trending`, `latest`, per-user `user_hidden_posts`), 5-minute batch recalculation (`RecalculateTrendingScoresJob` / `app:feed:recalculate-trending`) & cleanup commands (`app:media:cleanup-orphans`, `app:publishing:cleanup-ephemeral`), and Admin Content Settings & side-effect-free Ranking Simulator (`/admin/content/settings`) with RBAC & `AuditLogger`.
- **Footer architecture**:
    - **No large footer at the end of Infinite Scroll News Feed**.
    - **MiniFooter** placed below the Right Sidebar (`ConnectHubLayout`).
    - **FullFooter** on Landing / Public Policy pages (`PublicLayout` / Landing section).
    - **SimpleFooter** on Authentication layouts (`AuthSimpleLayout`).
    - **Mobile Footer Access**: Policy links, language switcher, and theme toggle in Mobile Drawer & Settings layout.
    - Draft/unapproved policies MUST remain `draft` (`404` publicly) and hidden from Footer links. Zero dead links or fake legal text.

## 5. Mandatory Security Standards (OWASP Top 10:2025 & ASVS Level 2)

Apply **Security by Design** and **Defense in Depth** across every phase (full details in `docs/SECURITY.md` & `docs/SECURITY_CHECKLIST.md`):

- **Authentication**: Fortify, `argon2id` hashing (`HASH_DRIVER=argon2id`), email verification, TOTP 2FA, **mandatory 2FA for Admin (`EnsureAdminHasTwoFactor`)**, rate limiting on login/register/2FA, session regeneration, logout all other devices (`BrowserSessionService`), instant session revocation on ban/suspend, `HttpOnly`, `SameSite=Lax`, and HTTPS `Secure` cookies.
- **Authorization**: Deny by default, Least Privilege, granular RBAC (`permission:*`, 8 hierarchical roles, anti-privilege-escalation, last `super_admin` protection), Policies & Gates on all backend endpoints, ownership checks, anti-IDOR, never trust `role`/`user_id`/price from client.
- **Input & Output**: Form Request validation, Eloquent/parameterized queries, CSRF, Mass Assignment protection, backend HTML sanitization (`PolicySanitizer`), SSRF protection (no arbitrary URL fetching), formula-safe CSV exports, never use `v-html` on unsanitized input, never leak stack traces or exceptions in Toast notifications.
- **File Uploads**: Strict extension/MIME allowlist + real file inspection, size/dimension limits, random filenames, re-encode images, strip EXIF, forbid PHP/executables/unsanitized SVG, store outside executable paths, signed URLs for private files.
- **Payments & Advertising**: Backend-only integer arithmetic (smallest currency unit, no float), `DB::transaction`, `lockForUpdate`, signed webhooks, replay/duplicate/race-condition protection (`idempotency_key` + unique constraint), password-confirmed financial refunds/adjustments, strict paid vs promotional credit bucket isolation, non-negative balance invariants, automatic ad cancellation & 100% refund when a promoted post is hidden/deleted, auditable ledger, anti-fraud ad event validation.
- **Headers, Backups & Monitoring**: `SecurityHeaders` (`CSP` with `frame-ancestors 'self'`, `HSTS`, `X-Content-Type-Options`, `Referrer-Policy`), immutable `AuditLogger` (redacting secrets), `RecordAuthSecurityEvents` (`LoginHistory`), Hostinger-safe SHA-256 snapshot backups (`AdminSecurityService`), no secrets/passwords in logs or Git, no default hardcoded admin credentials.
- **Dependency & Testing**: Run `composer audit`, `npm audit`, Pint, PHPStan level 7, `vue-tsc`, `vp check`, Pest security tests, and Vite build before marking any module complete. Never disable security controls or bypass validation/authorization as a workaround.
