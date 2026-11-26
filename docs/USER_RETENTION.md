# SyncViet User Retention & Digest Notification System

## 1. Respectful, Spam-Free Retention Philosophy

SyncViet retains members by surfacing real discussions, community activity, and personalized digests — **never** fake urgency ("Only 2 minutes left!"), fake notifications, or unsolicited email blasts.

## 2. Granular Notification Preferences (`users.notification_preferences`)

Members control all retention channels directly in `/referral` (`POST /referral/preferences`):

| Channel Key         |           Default           | Purpose                                                                             |
| :------------------ | :-------------------------: | :---------------------------------------------------------------------------------- |
| `weekly_digest`     |           `true`            | Rule-based weekly summary of real community posts                                   |
| `reengagement`      |           `true`            | Gentle check-in notifications when inactive for 3 / 7 / 30 days                     |
| `referral_updates`  |           `true`            | Milestone progress and qualified referral status updates                            |
| `community_updates` |           `true`            | Highlights from voluntarily joined communities                                      |
| `marketing_emails`  | **`false` (Strict Opt-In)** | Promotional updates — disabled by default for GDPR/international privacy compliance |

## 3. Signed One-Click Unsubscribe (`/growth/unsubscribe/{user}/{channel}`)

- Generated via `RetentionAndDigestService::unsubscribeUrl(User $user, string $channel = 'all')` using `URL::temporarySignedRoute(..., now()->addDays(60))`.
- Protected by Laravel's `signed` middleware — any tampered or unsigned request is rejected with `HTTP 403`.
- Supports per-channel opt-out (`weekly_digest`, `reengagement`, `marketing`) or global `all`.

## 4. Frequency Capping & Multi-Stage Inactive Re-Engagement

Executed daily via Hostinger-safe Cron (`php artisan app:growth:retention`):

1. **Explicit Admin Opt-In**: `retention_reengagement_enabled` and `retention_digest_enabled` both default to `false` until enabled in `/admin/growth`.
2. **Human Verified Active Filter**: Strictly targets `account_type = human`, `is_test_account = false`, `status = active`, and `email_verified_at IS NOT NULL`.
3. **Configurable Multi-Stage Cadence**: Defaults to `3,7,30` days (`retention_reengagement_days`). Tracks progress in `users.reengagement_stage` so each stage is sent at most once per inactivity cycle.
4. **Strict Global Frequency Cap**: Enforces `retention_frequency_cap_hours` (default `72` hours, minimum `24` hours) via `users.last_reengagement_sent_at` across both re-engagement and weekly digests.
5. **Bounded Hostinger Batch Size**: Processes bounded chunks (`batchLimit = 50` per run) so shared hosting PHP memory and execution limits are never exceeded.
