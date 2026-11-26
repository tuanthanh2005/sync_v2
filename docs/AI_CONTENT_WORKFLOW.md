# SyncViet — AI Content Workflow & Human Review Queue

## 1. End-to-End Editorial Pipeline

1. **Source Verification (`AiSourceResearchService`)**: Optional reference URLs pass `SafeLinkPreviewService::isSafePublicUrl()` (blocks private IPs, loopback, metadata endpoints).
2. **Structured Generation (`AiContentGeneratorService`)**: Enforces Emergency Stop, daily draft cap (`max_generated_drafts_per_day`), restricted topic rules, and Gemini `responseJsonSchema`.
3. **Automated Quality Gate (`AiContentQualityService`)**: Evaluates word count, exact/near-duplicate content hash against existing drafts and posts, unsafe HTML/script residue, PII leakage patterns, and unverified breaking-news claims (`quality_score` 0–100, `passed`/`warning`/`failed`).
4. **Mandatory Human Review (`pending_review`)**: Drafts NEVER auto-publish unapproved. Drafts with `quality_status = failed` cannot be approved until edited and re-validated.
5. **Edit-Resets-Approval Rule**: Calling `AiPublishingAndSchedulerService::updateDraftContent()` on an `approved` or `scheduled` draft automatically resets status to `pending_review` and cancels pending schedules.
6. **Idempotent Publishing**: `publishApprovedDraft()` runs inside `DB::transaction()` with `lockForUpdate()`, checks `published_post_id` for idempotency (preventing duplicate posts on retries), publishes through existing `PostService`, tags `content_origin = ai_generated` + `is_ai_assisted = true`, saves SEO metadata, and dispatches `ProcessSeoEntityJob` after commit.

## 2. Export & Bulk Operations

- `/admin/ai/content` supports filtering, single/bulk approve & reject, and formula-safe CSV export (`/admin/ai/drafts/export-csv` prefixes `=`, `+`, `-`, `@` cells with `'`).
