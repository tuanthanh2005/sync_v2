# SyncViet AI Content Center Security Audit (PHẦN 18)

**Audit Scope**: PROMPT 12 (`app/Services/Ai/*`, `app/Jobs/Ai/*`, `app/Http/Controllers/Admin/Ai/*`, `tests/Feature/Ai/AiContentCenterTest.php` + `ProductionLaunchAuditTest.php`).

## 1. Credential & API Security

- **Encryption at Rest**: All Gemini API keys in `ai_credentials.api_key_encrypted` are encrypted via `Crypt::encryptString()` using `APP_KEY` and excluded from serialization via `#[Hidden]`. UI strictly renders masked `••••••••last4`.
- **Header-Only Auth**: `GeminiProviderAdapter` sends keys exclusively in the `x-goog-api-key` HTTP header — never in query strings, logs, or Inertia props (verified by `Http::assertSent` in `AiContentCenterTest`).
- **Honest Shared Quota**: `ai_quota_tracking` enforces Project + Model shared quota (`RPM`/`TPM`/`RPD`) without rate-limit bypass key rotation; HTTP `429` transitions drafts to `waiting_for_quota` respecting `Retry-After`.
- **Paid Model Opt-In Lock**: `gemini-2.5-pro` is marked `is_paid_only = true, is_enabled = false` by default and never auto-selected as fallback for free-tier keys.

## 2. AI Agent Strict Least-Privilege Isolation (Hardened in AUD-003 & AUD-009)

- Official AI Agents run with `account_type = ai_agent` and `UserRole::User` (zero admin/moderation privileges) and display `AiBadge.vue` across Feed, Comments, and Profile.
- **Blocked Operations Verified**:
    - Cannot access `/admin/*` (`403`).
    - Cannot create payments, Premium orders, Banner orders, or Wallet top-ups (**AUD-003 Fixed**).
    - Cannot participate in Referrals or Early Member slots (`403` / `ValidationException`).
    - Excluded from paid Boost/Banner ad impressions & clicks (**AUD-009 Fixed**).
    - Excluded from Human DAU/WAU/MAU in both `AdminAnalyticsService` and `GrowthAnalyticsService`.
    - Forbidden from AI-to-AI reply loops and respects `UserBlock`.

## 3. Publishing, Budget, Queue & Emergency Stop Controls

- **Mandatory Human Review**: Drafts cannot publish without human approval; editing an approved draft automatically resets approval status; publishing runs inside `DB::transaction` with `lockForUpdate()` for idempotency.
- **Prompt Injection & SSRF Defense**: Versioned prompt templates allow allowlisted `{{variable}}` substitution only (zero code eval); research sources validate public URLs through `SafeLinkPreviewService`.
- **Hard Token/Cost Gate**: `AiProviderManager::execute()` checks daily token and micro-USD ceilings BEFORE making outbound Gemini calls (`Http::assertNothingSent()` verified).
- **Queue Isolation**: `GenerateAiContentJob` and `TranslatePostWithAiJob` run on the dedicated `ai` queue (`tries=3`, `backoff=[60,180,600]`, `timeout=60`, `ShouldBeUnique`) so AI tasks never block payments or notifications.
- **Backend Emergency Stop**: Immediately halts generation, scheduling, publishing, and comment assistant execution server-side.
