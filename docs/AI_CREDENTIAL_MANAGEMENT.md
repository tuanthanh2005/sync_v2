# SyncViet — AI Credential Management & Security

## 1. Unlimited Encrypted Multi-Key Storage

- Administrators can register any number of Gemini API credentials (e.g., 7+ keys across separate Google Cloud projects or billing tiers) at `/admin/ai/credentials`.
- Each key is encrypted immediately using `Crypt::encryptString()` (`APP_KEY`) and stored in `ai_credentials.api_key_encrypted`.
- `AiCredential` declares `#[Hidden(['api_key_encrypted'])]` and exposes only `maskedKey()` (`••••••••1234`) and `key_last4`.
- Duplicate keys are rejected deterministically via `key_fingerprint` (`SHA-256`).
- **Zero Plaintext Exposure**: Plaintext keys are never written to Git, `.env` examples, logs, error messages, CSV exports, or Inertia props.

## 2. Project ID Grouping & Anti-Bypass Quota Policy

- Every credential requires a `project_id` matching its Google Cloud Project.
- Multiple keys belonging to the same Google Cloud Project share one quota bucket (`provider_id + project_id + model_id + window_type + window_key`) in `ai_quota_tracking`.
- When a Project+Model bucket reaches its configured RPM, TPM, or RPD limit—or receives HTTP `429`—`AiCredentialAndQuotaService::selectCredentialForModel()` returns `waiting_for_quota = true` and **NEVER** rotates keys solely to circumvent provider rate limits.

## 3. Billing Tier & Paid-Model Protection

- Credentials specify `billing_tier` (`free` or `paid`).
- Models flagged `is_paid_only = true` (such as `gemini-2.5-pro`) are strictly blocked for `free` credentials and never auto-escalated without explicit Admin enablement (`allow_paid_model_fallback = true`).

## 4. Connection Status Honesty

- Newly stored keys start in `health_status = untested`.
- The Admin Overview reports `Setup Required` when zero keys exist, `Untested Key` before a live test succeeds, `Connected (Verified)` only after `GET /v1beta/models` returns `200`, and `Degraded / Auth Issue` on failures.
