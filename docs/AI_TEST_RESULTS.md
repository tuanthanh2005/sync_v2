# SyncViet — AI Content Center Verification & Test Results

## 1. Automated Verification Summary

> [!NOTE]
> All Gemini API calls in automated tests use deterministic Laravel `Http::fake()` fixtures so CI/local runs incur **zero real Gemini API charges** and never store real API keys in source control. Live Gemini connectivity is verified by the site owner in `/admin/ai/credentials` using the **Test** action after entering their encrypted keys.

### Static Analysis, Linting & Frontend Build

- **Laravel Pint**: `vendor/bin/pint --dirty --format agent` — 0 style issues
- **PHPStan Level 7**: `vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors
- **TypeScript (`vue-tsc`)**: `npm run types:check` — 0 errors
- **Vite+ Lint/Format (`vp check`)**: `npm run check` — 0 errors
- **Vite Production Build**: `npm run build` — compiles all 5 Admin AI pages & `AiBadge.vue` cleanly
- **15-Language Parity**: Verified 0 missing keys across all 15 JSON locale files

### Feature & Security Pest Suite (`tests/Feature/Ai/AiContentCenterTest.php`)

- Encrypted API key storage (`Crypt`), masked display (`••••••••last4`), zero plaintext key leaks in Inertia HTML/JSON, and strict `x-goog-api-key` header usage (never query param)
- Shared Project + Model quota tracking across keys in the same `project_id` with zero rate-limit bypass rotation
- HTTP `429` + `Retry-After` transition to `waiting_for_quota` and strict prevention of automatic fallback to paid-only `gemini-2.5-pro`
- Hard daily token budget ceiling enforcement (`BUDGET_LIMIT_REACHED`, zero outbound HTTP calls)
- Idempotent provisioning of 7 starter Official AI Agents (`account_type = ai_agent`, `UserRole::User`, zero admin/Premium impersonation) and strict exclusion from real Human `total_users`/`dau` in `AdminAnalyticsService`
- Prompt template versioning, rollback, allowlisted `{{variable}}` interpolation & prompt-injection tag sanitization
- Structured JSON generation -> Quality Gate -> unapproved publish blocked -> edit resets approval -> idempotent DB-locked publish -> SEO V2.0 `ProcessSeoEntityJob` dispatch
- Scheduled publishing + anti-flood expiry (`expired_skipped`) for stale overdue schedules > 24h
- SSRF blocking of `169.254.169.254` and `127.0.0.1` in research sources
- Multilingual AI translation into SEO V2.0 `seo_content_translations` as human-reviewed drafts
- Comment Assistant blocks AI-to-AI conversations, respects `UserBlock`, tags comments `is_ai_generated = true`, and Moderation Assistant stays strictly advisory (zero auto-bans)
- Backend Emergency Stop halts generation and scheduled publishing immediately
- Non-admin RBAC `403`, 2FA Admin `200` rendering of all 5 Admin AI pages, and formula-safe CSV export
