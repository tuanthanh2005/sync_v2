# SyncViet Moderation & Trust Safety Guide

## 1. Report Lifecycle & Triage

- **Report Reasons** (`App\Enums\ReportReason`): `spam`, `scam`, `fraud`, `harassment`, `hate_speech`, `impersonation`, `misinformation`, `copyright`, `illegal_content`, `inappropriate_content`, `misleading_ad`, `other`.
- **Statuses** (`App\Enums\ReportStatus`): `pending`, `under_review`, `resolved`, `rejected`, `dismissed`, `appealed`.
- **Triage**: Staff can set priority (`low`, `normal`, `high`, `critical`), assign a moderator (`assigned_to`), and record internal notes without exposing private moderation notes to reporters.
- **No Auto-Ban**: Accounts are never automatically banned solely by report count; every sanction requires review and an explicit policy reason.
- **Privacy Boundary**: Staff never receive arbitrary access to read all private user messages; only explicitly reported content is inspected.

## 2. User Sanctions & Granular Restrictions

- **Sanction Types**: `warning`, `suspend`, `ban`, `unban`, `unsuspend`, `restrict_post`, `restrict_comment`, `restrict_community`, `restrict_message`, `restrict_ad`, `lift_restriction`.
- **Supported Durations**: `1h`, `24h`, `3d`, `7d`, `30d`, `custom`, `permanent`.
- **Backend Enforcement**:
    - `ban` / `suspend` immediately deletes active records in `sessions` and rotates `remember_token`.
    - Granular restrictions are enforced directly inside `PostService`, `CommentService`, `CommunityService`, `MessagingService`, and `AdCampaignService`.
    - Elapsed timed sanctions and restrictions expire automatically via BOTH `EnsureUserIsActive` middleware (instant on request) AND `app:sanctions:expire` scheduler (every 5 minutes).

## 3. Content & Advertising Consistency

- **Posts**: Hiding, marking as spam, or soft-deleting a promoted post automatically cancels linked active/paused/pending `AdCampaign` records and refunds 100% of remaining `held_amount` back to the advertiser's wallet via `WalletService::releaseCampaignHold()`.
- **Comments**: Hiding, restoring, soft-deleting, or bulk-moderating comments recalculates `posts.comments_count` atomically inside a DB transaction.
- **Polls**: Staff can close, hide, restore, or delete policy-violating polls, but can NEVER alter vote counts.
- **Blocked Keywords & Domains**: Managed via `/admin/content/keywords` and evaluated by `ContentFilterService` (`block`, `flag` -> pending moderation, or `mask` -> `***`).
- **Appeals**: Approving an appeal linked to a user sanction automatically deactivates the sanction and restores `UserStatus::Active`, sending an account notification and recording an audit trail entry.
