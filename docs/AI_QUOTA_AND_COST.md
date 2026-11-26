# SyncViet — AI Quota Governance & Cost Control

## 1. Shared Project + Model Quota Enforcement

Google Cloud enforces Gemini API quotas at the **Project + Model** level. SyncViet mirrors this honestly in `ai_quota_tracking`:

- Keys sharing the same `project_id` share per-minute (`minute`) and daily (`day`) request/token counters for each `model_id`.
- When a bucket hits configured RPM, TPM, RPD, or receives HTTP `429` with `Retry-After`, the system transitions the task to `waiting_for_quota` and records `waiting_for_quota_until`.
- SyncViet **NEVER** rotates keys solely to circumvent provider rate limits.

## 2. Token Telemetry & Integer Micro-USD Accounting

- Every API call inserts an immutable row in `ai_usage_records` capturing `input_tokens`, `output_tokens`, `thinking_tokens`, `cached_tokens`, `duration_ms`, `http_status`, and integer `estimated_cost_micro_usd` (1 USD = 1,000,000 micro-USD, zero floating-point drift).
- Models start with `pricing_verified = false` until the administrator confirms official pricing rates in `/admin/ai/credentials`; unverified pricing is explicitly labeled in Admin analytics rather than presenting fabricated dollar figures.

## 3. Hard Budget Ceilings

Before every outbound call, `AiAutomationControlService::checkBudgetGate()` enforces:

- `max_daily_tokens` (default `500,000` tokens/day)
- `max_daily_cost_cents` (optional USD cent ceiling, `0` = track only)
- `max_monthly_cost_cents` (optional USD cent ceiling, `0` = track only)
- `allow_paid_model_fallback = false` (prevents silent escalation to `gemini-2.5-pro`).
