# SyncViet — AI Content Center Security Model

## 1. Secret Management & Key Isolation

- All API keys are encrypted at rest with Laravel `Crypt::encryptString()` (`APP_KEY`) in `ai_credentials.api_key_encrypted`.
- `AiCredential` hides `api_key_encrypted` from serialization and returns only `maskedKey()` (`••••••••1234`).
- Outbound Gemini requests authenticate strictly via the `x-goog-api-key` HTTP header—NEVER via URL query string.
- Error handlers redact decrypted keys before writing `last_error` or user-facing alerts.
- Zero real API keys are committed to Git, `.env.example`, or documentation.

## 2. Authorization & Anti-Abuse

- Every `/admin/ai/*` route enforces `auth`, `verified`, `active`, `admin.2fa` (mandatory Admin TOTP 2FA), `can:access-admin`, and granular `permission:ai.*` RBAC middleware.
- Official AI Agent accounts use `UserRole::User` and `AccountType::AiAgent` with `has_password = false`—zero staff privileges.

## 3. Injection, SSRF & Export Defenses

- **Prompt Injection**: Untrusted reference excerpts and user content are stripped of HTML/scripts and delimiter breakout tags via `AiPromptService::sanitizeUntrustedData()`.
- **No Code Execution**: Prompt templates support only allowlisted `{{variable}}` substitution—zero Blade/PHP evaluation.
- **SSRF Protection**: `AiSourceResearchService` validates every external source URL via `SafeLinkPreviewService::isSafePublicUrl()`, blocking `127.0.0.1`, `169.254.169.254`, private RFC1918 ranges, and non-HTTP(S) schemes.
- **Formula-Safe CSV**: `/admin/ai/drafts/export-csv` prefixes cells beginning with `=`, `+`, `-`, `@`, `\t`, or `\r` with `'`.
- **Backend Emergency Stop**: Checked inside `AiAutomationControlService::assertNotStopped()` and `AiProviderManager::execute()`, immediately halting all generation, translation, replies, and scheduled publishing.
