# SyncViet — Gemini API Integration Guide

## 1. Official Endpoint & Authentication

- **Base URL**: `https://generativelanguage.googleapis.com/v1beta` (configurable via `AI_GEMINI_BASE_URL` in `.env` / `config/syncviet.php`).
- **Generate Endpoint**: `POST /v1beta/models/{model}:generateContent`
- **Model Discovery & Connection Test**: `GET /v1beta/models`
- **Authentication Header**: Strictly `x-goog-api-key: <decrypted_key>`. SyncViet **NEVER** passes API keys in URL query strings (`?key=...`), logs, Inertia payloads, or frontend bundles.

## 2. Verified Active Model Registry

Seeded in `ai_models` by `AiContentCenterSeeder`:

| Model ID                | Role Group | Capabilities                                            | Default State         | Billing Guard                         |
| ----------------------- | ---------- | ------------------------------------------------------- | --------------------- | ------------------------------------- |
| `gemini-2.5-flash-lite` | `low_cost` | Structured Output, High Volume, Translation, Moderation | Enabled               | Free / Paid                           |
| `gemini-2.5-flash`      | `fast`     | Structured Output, Thinking, Editorial Posts & Research | Enabled               | Free / Paid                           |
| `gemini-2.5-pro`        | `advanced` | Deep Reasoning, Structured Output, Thinking             | **Disabled** (opt-in) | **Paid Only** (`is_paid_only = true`) |

## 3. Structured Outputs & Error Handling

- Content generation, translation, and moderation advisory pass `generationConfig.responseMimeType = "application/json"` with `generationConfig.responseJsonSchema`.
- HTTP status mapping in `GeminiProviderAdapter`:
    - `400`: `BAD_REQUEST`
    - `401` / `403`: `AUTH_ERROR` (marks credential `auth_error` so failing key is not hammered)
    - `404`: `MODEL_NOT_AVAILABLE`
    - `429`: `RATE_LIMITED` — honors numeric `Retry-After` header (default 120s backoff), sets `waiting_for_quota_until` on the shared Project+Model quota bucket, and marks draft `waiting_for_quota`.
    - `500` / `503` / network timeout: `PROVIDER_UNAVAILABLE` with bounded Shared Hosting timeout (`35s`).
- Error sanitization strips any accidental occurrence of the decrypted API key before persisting `last_error` or flashing UI messages.

## 4. Provider Compliance Classification

SyncViet distinguishes three workflows per Gemini API Terms:

1. **Internal Editorial Content** (Drafts authored by Admins/AI Agents before publication).
2. **User-Facing AI Features** (Comment Assistant replies) — locked behind `compliance_user_facing_verified = true` and transparent `AiBadge.vue` disclosure.
3. **User Content Processing** (Moderation screening) — strips PII/untrusted markup via `AiPromptService::sanitizeUntrustedData()` and never sends private DMs or payment credentials to free-tier APIs.
