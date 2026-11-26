# SyncViet Frontend, UI/UX, Responsive & Multilingual Audit (PHẦN 24–27)

## 1. Production Bundle & Code-Splitting Audit (`public/build/manifest.json`)

- **Vite Build (`vp build`)**: **3,937 modules** compiled cleanly (`0` warnings/errors), strict TypeScript (`vue-tsc --noEmit` = **0 errors**), `vp check` = **0 lint/format errors across 281 files**.
- **Strict Admin Isolation**: Verified programmatically via `manifest.json` — **`Admin chunks statically imported by guest app.ts: []` (ZERO)**. Guests never download `AdminLayout`, `AdminChart` (`182.6 KB`), or any `admin/*` pages (`75` dynamically code-split chunks!).
- **Core Entry Assets**:
    - `app.js`: `356.75 KB` raw / **`82.80 KB` gzip**
    - `app.css`: `147.00 KB` raw / **`23.31 KB` gzip** + `14.91 KB` / **`3.04 KB` gzip**
    - Self-hosted `Be Vietnam Pro` woff2 (`400/500/600/700`): `~87 KB` total, zero third-party Google Fonts latency/privacy leaks.
    - 14 non-English locale chunks dynamically lazy-loaded (`49–92 KB` raw / `19–23 KB` gzip each) — English stays bundled as zero-latency fallback.

## 2. Global Layout Stability & Auto-Hide Scrollbar Compliance (PHẦN 25)

- `html { scrollbar-gutter: stable; }` preserved globally (no `overflow-x: hidden/clip` on `html`/`body`).
- Persistent `AppProviders.vue` (`<ConfigProvider :scroll-body="false">`), non-modal `DropdownMenu` (`modal: false`), non-locking `SelectContent` (`bodyLock: false`) prevent header/sidebar shifting on dropdown/modal open.
- Payment/checkout confirmation modals (`PaymentConfirmModal`) use fixed header + `<AutoHideScrollArea>` body + fixed footer with `100dvh` safe-area support so close/confirm actions remain reachable on `320px–430px` screens.
- Zero native browser `alert()`/`confirm()` calls (`ConfirmActionDialog` / `ReasonDialog` + `Sonner` `appToast` everywhere).

## 3. Responsive & Cross-Browser Verification Status (PHẦN 26)

- **Verified in Code & Desktop Lab**: Mobile Bottom Nav, Mobile Drawer, `viewport-fit=cover` (pinch-to-zoom enabled), `16px` mobile inputs preventing iOS auto-zoom, `touch-action: manipulation`, normal text selection & copy/paste preserved, keyboard navigation & ARIA labels on interactive dialogs.
- **Honest Unverified Matrix**: Real physical **Safari iOS**, **Chrome Android**, **Firefox**, and **macOS Safari** browser testing was **Not Verified** in this local Windows CLI environment — Owner should perform a 10-minute smoke check on iPhone Safari + Android Chrome on staging/production right after deploy.

## 4. 15-Language Localization Audit (PHẦN 27)

Programmatically verified across all 15 JSON files in `resources/js/i18n/locales/`:

- **English (`en`)**: Default & fallback (`1,108` keys).
- **Full 15-Locale Parity**: `ar`, `de`, `en`, `es`, `fil`, `fr`, `hi`, `id`, `ja`, `ko`, `pt-BR`, `th`, `tr`, `vi`, `zh-CN` — **1,108 / 1,108 keys each, `0` missing keys, `0` extra keys**.
- **RTL Support**: `ar` triggers `dir="rtl"` on `<html>` cleanly via `HandleInertiaRequests` + `LanguageService`.
- **Locale Switching Safety**: Preserves auth session, route, `sv_ref_code` referral cookie, and never alters server-computed VND/USD prices.
- **Note**: `.env.example` updated from `APP_LOCALE=vi` to `APP_LOCALE=en` (AUD-012) to match international default policy.
