# SyncViet — Global Localization Architecture V1.0

## 1. Single Source of Truth

All backend, middleware, routing, and Admin Center components resolve platform locales from a single canonical definition:

- **Canonical Configuration**: [`config/localization.php`](../config/localization.php) defines `default_locale` (`en`), `fallback_locale` (`en`), `cookie_name` (`syncviet_locale`), all 15 supported locale metadata entries (`code`, `bcp47`, `native_name`, `english_name`, `flag_code`, `direction`, `is_rtl`, `is_enabled`, `is_default`, `fallback_locale`, `sort_order`), and `non_translatable_tokens`.
- **Runtime Governance Service**: [`App\Services\System\LanguageService`](../app/Services/System/LanguageService.php) synchronizes `config/localization.php` with the `languages` database table, caches the catalog in-memory per request + 15-minute cache (`syncviet.languages.catalog.v1`), and exposes `enabledCodes()`, `publicEnabledLanguages()`, `defaultCode()`, and `isRtl()`.
- **Routing & Middleware**:
    - `routes/web.php` derives `$seoLocaleRegex` dynamically from `LanguageService::allCodes()`.
    - [`App\Http\Middleware\SetLocale`](../app/Http/Middleware/SetLocale.php) validates every candidate strictly against `LanguageService::enabledCodes()`.
    - [`App\Http\Requests\LocaleUpdateRequest`](../app/Http/Requests/LocaleUpdateRequest.php) restricts language switching strictly to enabled codes via `Rule::in(...)`.

## 2. Supported Locales (15 Languages + RTL)

English (`en`) is the permanent default and fallback language.

| Code    | BCP-47   | Native Name        | English Name         | Direction | Default / Fallback     |
| :------ | :------- | :----------------- | :------------------- | :-------: | :--------------------- |
| `en`    | `en-US`  | English            | English              |   `ltr`   | **Default & Fallback** |
| `vi`    | `vi-VN`  | Tiếng Việt         | Vietnamese           |   `ltr`   | Fallback: `en`         |
| `es`    | `es-ES`  | Español            | Spanish              |   `ltr`   | Fallback: `en`         |
| `pt-BR` | `pt-BR`  | Português (Brasil) | Portuguese (Brazil)  |   `ltr`   | Fallback: `en`         |
| `id`    | `id-ID`  | Bahasa Indonesia   | Indonesian           |   `ltr`   | Fallback: `en`         |
| `hi`    | `hi-IN`  | हिन्दी              | Hindi                |   `ltr`   | Fallback: `en`         |
| `th`    | `th-TH`  | ภาษาไทย            | Thai                 |   `ltr`   | Fallback: `en`         |
| `fil`   | `fil-PH` | Filipino           | Filipino             |   `ltr`   | Fallback: `en`         |
| `fr`    | `fr-FR`  | Français           | French               |   `ltr`   | Fallback: `en`         |
| `de`    | `de-DE`  | Deutsch            | German               |   `ltr`   | Fallback: `en`         |
| `ja`    | `ja-JP`  | 日本語             | Japanese             |   `ltr`   | Fallback: `en`         |
| `ko`    | `ko-KR`  | 한국어             | Korean               |   `ltr`   | Fallback: `en`         |
| `zh-CN` | `zh-CN`  | 简体中文           | Chinese (Simplified) |   `ltr`   | Fallback: `en`         |
| `ar`    | `ar-SA`  | العربية            | Arabic               | **`rtl`** | Fallback: `en`         |
| `tr`    | `tr-TR`  | Türkçe             | Turkish              |   `ltr`   | Fallback: `en`         |

## 3. Three-Layer Translation File Architecture

1. **Frontend UI (`resources/js/i18n/locales/{code}.json`)**:
    - Nested modular namespaces (`common`, `accessibility`, `auth`, `navigation`/`nav`, `profile`, `settings`, `dashboard`, `home`, `post`, `communities`, `notifications`, `messages`, `moderation`, `report`, `wallet`, `ads`, `monetization`, `seo`, `ai`, `growth`, `admin.*`, `footer`, `policy`, `toast`, `dialog`, `pwa`).
    - Only `en.json` is statically bundled in the entry chunk; the 14 other locale chunks are **dynamically lazy-loaded on demand** via Vite code splitting (`resources/js/i18n/index.ts`), ensuring minimal initial payload on Hostinger Shared Hosting.
    - Missing keys fall back to `en` automatically without crashing or displaying raw keys; dev mode logs missing key diagnostics once per key.
2. **Backend JSON Messages (`lang/{code}.json`)**:
    - Covers all Enum labels, Controller/Service flash & validation responses, Notification templates, Email copy (VerifyEmail / ResetPassword / Fortify), Footer groups, standard Policy page labels, Blade error pages (`403`, `404`, `419`, `429`, `500`, `503`), and zero-cloaking `<noscript>` SEO SSR headings (`resources/views/partials/seo-ssr.blade.php`).
3. **Laravel Standard PHP Translation Files (`lang/{code}/{auth,pagination,passwords,validation}.php`)**:
    - Full native translations across all 15 locales for every Laravel 13 validation rule, authentication throttle/error message, password reset broker response, and pagination link.

## 4. Detection, Persistence & Isolation

- **Resolution Priority (`SetLocale`)**:
    1. Explicit URL locale prefix on localized public SEO routes (`/{locale}/posts/...`, `/{locale}/topics/...`, `/{locale}/communities/...`) — never IP-redirects crawlers/Googlebot.
    2. Active session `locale` (explicit user choice).
    3. `syncviet_locale` (`HttpOnly`, `SameSite=Lax`, 1-year) cookie.
    4. Authenticated `users.preferred_locale`.
    5. First-visit `Accept-Language` header matching (`LanguageService::detectFromAcceptLanguage()`).
    6. Active default locale (`en`).
- **Session, Checkout & Referral Safety**:
    - `LocaleController` updates only session `locale`, `syncviet_locale` cookie, and `users.preferred_locale` — never clearing `sv_ref_code` referral cookies, never regenerating/dropping auth sessions, and never mutating existing payment order amounts or currencies.
- **Email & Queue Worker Locale Isolation**:
    - `App\Models\User` implements `Illuminate\Contracts\Translation\HasLocalePreference` (`preferredLocale()` validates against `LanguageService::allCodes()` and defaults to `en`).
    - Every Laravel email notification (`VerifyEmail`, `ResetPassword`, queued notifications) renders in the **recipient's** locale and cleanly restores the worker locale afterward.
    - `SocialActivityNotification` stores `title_key` + `title_params` so `NotificationService::forUser()` renders internal notifications dynamically in any of the 15 locales (with English fallback, never Vietnamese leakage).

## 5. Locale-Aware Formatting & Currency Decoupling

- [`resources/js/lib/formatters.ts`](../resources/js/lib/formatters.ts): `Intl.NumberFormat`, `Intl.DateTimeFormat`, `Intl.RelativeTimeFormat`, `formatPercent`, `formatBytes` mapped cleanly to all 15 BCP-47 tags.
- [`resources/js/lib/currency.ts`](../resources/js/lib/currency.ts): `formatVnd`, `formatUsdCents`, `formatMoney`, `defaultCurrencyForLocale`. Language selection never alters stored financial amounts or currencies in `payments`, `subscriptions`, or `ad_credit_accounts`.

## 6. Dynamic Content, User Content & International SEO V2.0

- **Admin-Generated Content**: Policy pages (`PolicyPage`) and Footer links (`FooterMenuService`) resolve localized standard titles across all 15 languages via `Lang::get()` and fall back to English for custom content (Vietnamese when `locale === 'vi'`).
- **User-Generated Content (UGC)**: Switching UI language never overwrites another user's original post, comment, or message. Optional multilingual publishing for SEO-eligible posts flows strictly through the existing `ContentTranslationService` + `AiTranslationWorkflowService` (`seo_content_translations` with human review and caching — zero live Gemini API calls on UI render).
- **International SEO Consistency**: Server-rendered `<html lang="..." dir="rtl|ltr">` in `app.blade.php` and `errors/minimal.blade.php`, reciprocal BCP-47 `hreflang` + `x-default` via `HreflangService`, sharded multilingual sitemaps via `SitemapService`, and zero duplicate indexable URLs from language cookies.

## 7. Security, Performance & Admin Governance

- **Path Traversal & Injection Defense**: Locale codes are strictly allowlisted against `LanguageService::allCodes()`; user input is never interpolated into filesystem paths.
- **XSS Defense on DB Overrides**: `LanguageService::importOverrides()` strips HTML/scripts (`strip_tags`), validates keys against the `en.json` schema allowlist, enforces length limits, records `TranslationRevision` + `AuditLogger`, and invalidates only `syncviet.lang.overrides.{code}`.
- **Admin Governance (`/admin/languages`)**: Powered by [`LocalizationAuditService`](../app/Services/System/LocalizationAuditService.php), providing live coverage KPIs across Frontend/Backend/PHP Lang layers, a searchable side-by-side Translation Key Explorer (English source vs target locale), formula-safe CSV & JSON audit report exports, safe JSON override import, and one-click override rollback.
