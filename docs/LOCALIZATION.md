# SyncViet Global Localization Guide (15 Languages + RTL)

## 1. Supported Languages

English (`en`) is the default and fallback language. All 15 languages are bundled with 100% key parity across both frontend (`resources/js/i18n/locales/{code}.json`, 643 keys each) and backend (`lang/{code}.json`, 168 keys each):

| Code    | Native Name        |    Flag Asset    |   Direction   |
| :------ | :----------------- | :--------------: | :-----------: |
| `en`    | English            | `us` (local SVG) | LTR (Default) |
| `vi`    | Tiếng Việt         | `vn` (local SVG) |      LTR      |
| `es`    | Español            | `es` (local SVG) |      LTR      |
| `pt-BR` | Português (Brasil) | `br` (local SVG) |      LTR      |
| `id`    | Bahasa Indonesia   | `id` (local SVG) |      LTR      |
| `hi`    | हिन्दी              | `in` (local SVG) |      LTR      |
| `th`    | ภาษาไทย            | `th` (local SVG) |      LTR      |
| `fil`   | Filipino           | `ph` (local SVG) |      LTR      |
| `fr`    | Français           | `fr` (local SVG) |      LTR      |
| `de`    | Deutsch            | `de` (local SVG) |      LTR      |
| `ja`    | 日本語             | `jp` (local SVG) |      LTR      |
| `ko`    | 한국어             | `kr` (local SVG) |      LTR      |
| `zh-CN` | 简体中文           | `cn` (local SVG) |      LTR      |
| `ar`    | العربية            | `sa` (local SVG) |    **RTL**    |
| `tr`    | Türkçe             | `tr` (local SVG) |      LTR      |

## 2. Detection, Persistence & Synchronization

- **Resolution Order** (`App\Http\Middleware\SetLocale`):
    1. Active session `locale` (if currently enabled by Admin).
    2. `syncviet_locale` cookie (if enabled).
    3. Authenticated `users.preferred_locale` (if enabled).
    4. First-visit `Accept-Language` header matching via `LanguageService::detectFromAcceptLanguage()`.
    5. Active default language (`en` fallback).
- **Persistence**: `LocaleController` persists switches to session, `HttpOnly` `SameSite=Lax` cookie, and `users.preferred_locale`.
- **Frontend Sync**: `HandleInertiaRequests` shares `locale`, `isRtl`, `languages`, and `translationOverrides`. `syncI18nLocale()` updates `vue-i18n`, merges DB overrides, and updates `<html lang="..." dir="rtl|ltr">` (also rendered server-side in `resources/views/app.blade.php` to avoid RTL flicker).
- **Zero External CDN**: All 15 flags render via internal vector SVG in `resources/js/components/common/FlagIcon.vue`.
- **Locale Formatters**: `resources/js/lib/formatters.ts` provides `Intl.DateTimeFormat` and `Intl.NumberFormat` wrappers for dates, datetimes, numbers, bytes, and VND currency.
- **User Content Integrity**: User posts, comments, and messages remain strictly in their original language (no paid machine translation API).

## 3. Admin Language Governance (`/admin/languages`)

- Enable/disable languages publicly (default language cannot be disabled).
- Switch active default locale and sort order.
- Real-time translation coverage meter and missing-key inspection (`LanguageService::translationHealth()`).
- Safe JSON export and schema-validated DB override import (`translation_overrides` + `translation_revisions`) — never writes executable files or arbitrary paths to disk.
