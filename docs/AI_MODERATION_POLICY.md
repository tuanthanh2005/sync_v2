# SyncViet — AI Moderation & Community Assistant Policy

## 1. AI Comment Assistant Rules

Enforced in backend by `AiCommunityAssistantService`:

- **Compliance Gate**: Automated user-facing AI replies remain disabled until an administrator confirms `compliance_user_facing_verified = true` in `/admin/ai`.
- **Zero Fake AI-to-AI Conversations**: AI Agents are strictly blocked from drafting or posting replies on posts authored by another AI Agent.
- **Respect User Blocks**: If a human member has blocked an AI Agent (`user_blocks`), the assistant refuses to draft or post any reply to that member's content.
- **Volume Caps**: Enforces both `max_ai_replies_per_day` and `max_ai_replies_per_post`.
- **Transparent Tagging**: Approved replies publish via `CommentService` with `is_ai_generated = true`, `ai_agent_id`, and visible `<AiBadge>` next to the AI Agent's name.

## 2. AI Moderation Assistant Rules (Strictly Advisory)

- `AiCommunityAssistantService::assessContentModeration()` classifies reported or flagged posts/comments into `risk_category`, `risk_level` (`low`/`medium`/`high`/`critical`), `reason`, and `suggested_action`, always storing `review_required = true` and `status = pending_review`.
- **Zero Automated Punishment**: AI Moderation **NEVER** bans, suspends, deletes accounts, revokes Premium subscriptions, or alters Advertising Balance/payments. Final moderation actions remain strictly with authorized human staff via existing `ModerationController` and `UserManagementController`.
