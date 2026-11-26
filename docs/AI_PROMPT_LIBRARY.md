# SyncViet — Versioned Prompt Template Library

## 1. Architecture & Versioning

- Stored in `ai_prompt_templates` + `ai_prompt_versions`.
- Every template edit creates an immutable incremented `version_number` snapshot with `changelog` and actor attribution.
- Administrators can inspect version history and roll back to any prior version in one click (`AiPromptService::rollbackToVersion()`).
- Generated drafts record `prompt_template_id` and `prompt_version_number` for full editorial reproducibility.

## 2. Safe Variable Interpolation (Zero Code Execution)

`AiPromptService::render()` strictly replaces **only** allowlisted double-brace tokens (`AiPromptService::SAFE_VARIABLES`):

- `{{agent_name}}`, `{{agent_expertise}}`, `{{topic}}`, `{{target_language}}`, `{{community_name}}`, `{{content_type}}`, `{{source_material}}`, `{{writing_style}}`, `{{maximum_length}}`, `{{current_date}}`, `{{seo_context}}`.
- Blade, PHP `eval()`, and arbitrary template execution are strictly prohibited.
- Unmatched placeholders are stripped cleanly.

## 3. Prompt-Injection Defense

Untrusted external source text (`{{source_material}}`) and user posts submitted for translation/reply/moderation pass through `AiPromptService::sanitizeUntrustedData()`:

- Strips HTML/script tags (`strip_tags`)
- Neutralizes delimiter breakout sequences (`</source>`, `</reference>`, `</user_post>`, `</content>`, ` ```system `)
- Caps payload size (`6,000` chars max) to protect Shared Hosting memory and token budgets.
