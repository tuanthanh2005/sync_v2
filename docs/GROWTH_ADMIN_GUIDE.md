# SyncViet Growth Center — Administrator Operations Guide

## 1. Access & RBAC Security

The Growth Center lives inside the dedicated `AdminLayout` sidebar under **Growth Center** and requires:

1. Authenticated Admin/Staff account with **mandatory TOTP 2FA** (`EnsureAdminHasTwoFactor`).
2. Granular RBAC permissions (synced automatically by `php artisan app:rbac:sync`):
    - `growth.view` — View Growth Overview KPIs and logs
    - `growth.manage` — Update Growth settings & guardrails
    - `growth.referrals` — Inspect referrals, run qualification batches, toggle codes
    - `growth.rewards` — Manage milestone rules, grant manual rewards, revoke promotional rewards
    - `growth.campaigns` — Create/activate/pause/version Growth campaigns
    - `growth.early_members` — Enroll/revoke Early Members & grant/revoke permanent Member Badges
    - `growth.fraud` — Review and resolve manual fraud review queue items
    - `growth.export` — Export formula-safe CSV referral reports

## 2. Safe Post-Deploy Activation Checklist

After deploying Growth System V1.0, reward programs ship **safely paused/draft** so no unintended rewards are issued before you verify your limits. To launch publicly:

1. Open **`/admin/growth`** (**Growth Overview & Settings**):
    - Review `referral_verification_hours` (default `72` hours), `referral_attribution_days` (`30` days), and `referral_max_reward_days_per_user` (`365` days).
    - Enable `referral_rewards_auto_grant` when ready for automatic milestone rewards.
    - Enable `early_member_enabled` when ready to open the 1,000 Early Member slots (adjust `early_member_max_slots` and `early_member_premium_days` as desired).
    - Optionally enable `retention_reengagement_enabled` and `retention_digest_enabled`.
    - Click **Save Guardrails**.
2. Open **`/admin/growth/campaigns`** (**Campaigns, Early Members & Badges**):
    - Switch `SyncViet Member Referral Program` (`syncviet-referral-v1`) from `draft` -> **`active`** (every status/budget change automatically creates an immutable `growth_campaign_versions` record).
    - Switch `SyncViet Early Members 1000` from `draft` -> **`active`** when ready to open enrollment.
3. Open **`/admin/growth/referrals`** (**Referrals, Fraud Review & Reward Rules**):
    - Verify the 7 cumulative milestone rules (`1->3d`, `3->7d`, `5->15d`, `10->30d`, `25->90d`, `50->180d`, `100->365d`) or customize targets/caps.
    - Monitor the **Manual Fraud Review Queue**; always provide a clear audit reason when approving, rejecting, or revoking.
    - Use **Run Qualification Now** to test-evaluate eligible pending referrals on demand, or let the 30-minute Cron handle it automatically.

## 3. Manual Grants, Safe Revocations & Audit Trail

- **Manual Promotional Grant** (`/admin/growth/referrals`): Grant 1–365 promotional Premium days to a verified human `@username` with a mandatory reason (never recorded as revenue).
- **Safe Revocation**: Revoking any `ReferralReward` or `EarlyMember` requires a mandatory audit reason, immediately records a `growth_activity_logs` entry, and preserves any paid Premium expiry floor.
- **CSV Export**: Exports the latest 1,000 referrals with automatic CSV formula injection protection.
