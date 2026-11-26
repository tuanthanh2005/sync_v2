# SyncViet — Internationalization & Localization Guidelines (Mandatory SOP)

All features, pages, components, modals, emails, notifications, and backend responses on SyncViet MUST support all 15 platform languages from day one. English (`en`) is the default and fallback language.

## 1. Mandatory Checklist for Every New Feature

Before marking any UI or backend change complete, complete every step:

1. **Zero Hardcoded UI Text**:
    - In Vue components (`<script setup lang="ts">`), use `const { t } = useI18n();` for every visible label, button, heading, empty state, tooltip, modal, toast, `placeholder`, `aria-label`, `title`, and `sr-only` text.
    - In Blade views, use `__('...')` (never `app()->isLocale('vi') ? ... : ...`).
    - In PHP controllers, services, enums, and form requests, wrap every user-facing message in `__('...')` using stable English source strings and `:named` parameters.
2. **Add Keys Across All 15 Locales**:
    - Frontend: add key under the matching module namespace in `resources/js/i18n/locales/en.json` and translate into all 14 other `resources/js/i18n/locales/{code}.json` files.
    - Backend: add English string key to `lang/en.json` and translate into all 14 other `lang/{code}.json` files.
    - Never copy English strings into non-English files just to satisfy key parity (except approved brand/technical tokens in `docs/TRANSLATION_GLOSSARY.md`).
3. **Preserve Placeholders & Avoid Concatenation**:
    - Frontend (`vue-i18n`): `{name}`, `{count}`, `{amount}`, `{date}`.
    - Backend (`Laravel`): `:name`, `:count`, `:amount`, `:date`.
    - Never concatenate translated fragments with `+` when grammar order differs across languages (especially Japanese, Korean, Turkish, Hindi, and RTL Arabic).
4. **Preserve Business Semantics**:
    - Never compare translated labels in business logic; always compare enum values (`PaymentStatus::Pending->value`, `UserRole::Admin->value`, etc.).
    - Never change payment currency or price amounts when a user switches UI language.
    - Never translate brand names (`SyncViet`, `Google`, `Gemini`, `PayPal`, `Cloudflare`, `VietQR`) or distort financial/legal meaning.
5. **RTL & Multilingual Responsive Layout**:
    - Use CSS logical utilities (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`, `text-end`) instead of physical `ml-*`/`mr-*`/`text-left`/`text-right`.
    - Avoid rigid fixed widths on buttons/tabs/badges; allow wrapping or `min-w-0 truncate` so German, Vietnamese, Thai, Devanagari, CJK, and Arabic strings fit cleanly on mobile (`320px–430px`) and desktop.
    - Keep code blocks, URLs, emails, and transaction codes readable (`font-mono`, LTR where appropriate).
6. **Run Automated Localization Audit & Tests**:
    ```bash
    php artisan localization:audit --strict
    php artisan test tests/Feature/Localization/GlobalLocalizationCompletionTest.php --compact
    npm run types:check
    npx vp check
    npm run build
    ```

## 2. How to Add a New Translation Key

### Frontend (`vue-i18n`)

1. Choose the existing module namespace (`common`, `accessibility`, `auth`, `settings`, `dashboard`, `home`, `post`, `communities`, `notifications`, `messages`, `monetization`, `seo`, `ai`, `growth`, `admin.*`).
2. Add the English value in `resources/js/i18n/locales/en.json`.
3. Add idiomatic translations in all 14 other `resources/js/i18n/locales/{code}.json` files preserving every `{placeholder}`.
4. Run `php artisan localization:audit --strict`.

### Backend (`Laravel`)

1. Write `__('Your message with :param.', ['param' => $val])` in PHP/Blade.
2. Add the exact English key to `lang/en.json` and translate into all 14 other `lang/{code}.json` files preserving every `:param`.
3. For internal notifications (`SocialActivityNotification`), pass `titleKey` and `titleParams` so `NotificationService::forUser()` translates dynamically into the viewer's current locale.
4. For emails/queued notifications, rely on `User::preferredLocale()` (`HasLocalePreference`) — never read `app()->getLocale()` from an Admin or Queue Worker session.

## 3. How to Add a 16th Platform Language

1. Add the locale entry to `config/localization.php` (`code`, `bcp47`, `native_name`, `english_name`, `flag_code`, `direction`, `is_rtl`, `sort_order`).
2. Add the lazy chunk loader and code to `SUPPORTED_LOCALES` in `resources/js/i18n/index.ts`, BCP-47 mapping in `resources/js/lib/formatters.ts`, and inline SVG flag in `resources/js/components/common/FlagIcon.vue`.
3. Create:
    - `resources/js/i18n/locales/{code}.json`
    - `lang/{code}.json`
    - `lang/{code}/{auth,pagination,passwords,validation}.php`
4. Run `php artisan app:sync-governance` (seeds `languages` table cleanly) and `php artisan localization:audit --strict`.

## 4. Automated Audit CLI Reference

```bash
# Full table audit across all 15 locales + Vue/PHP/Blade source scan
php artisan localization:audit

# Strict CI/release gate (exits non-zero on ANY missing, empty, duplicate, placeholder, or hardcoded issue)
php artisan localization:audit --strict

# Audit a single locale or top-level module
php artisan localization:audit --locale=ar --module=monetization

# Export machine-readable report (JSON or formula-safe CSV)
php artisan localization:audit --format=json
php artisan localization:audit --format=csv
```

## 5. CI / Release Gate Policy

A release MUST NOT be marked complete if `php artisan localization:audit --strict` fails. Any missing required key, empty value, duplicate JSON key, placeholder mismatch, `isLocale('vi')` shortcut, or hardcoded UI text must be resolved before merge/release.
