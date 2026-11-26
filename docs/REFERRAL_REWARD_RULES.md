# SyncViet Referral Qualification & Cumulative Reward Rules

## 1. Anti-Abuse Qualification Criteria

A referral **never** qualifies at the moment of registration. `ReferralService::processPendingQualifications()` evaluates pending referrals once `eligible_at <= now()` and requires ALL enabled gates:

1. **Human & Active Account**: Referee must be `account_type = human`, `is_test_account = false`, and `status = active`.
2. **Verification Hold Elapsed**: Default **72 hours** (`referral_verification_hours`), configurable by Admin (`0`–`720` hours).
3. **Email Verified**: `referee.email_verified_at !== null` (when `referral_require_email_verified = true`).
4. **Meaningful Activity or Onboarding**: Referee has completed `/onboarding` OR triggered at least one real activation event (`interests_selected`, `first_community_joined`, `first_interaction`, `first_post_published`).
5. **Fraud Re-Evaluation Passed**: Re-checks `FraudDetectionService::evaluateReferral()`; suspicious bursts are moved to `under_review` (`growth_fraud_reviews`) rather than auto-qualified or auto-banned.

## 2. Cumulative Milestone Table (Seeded Defaults)

| Milestone Rule    | Qualified Referrals Required | Cumulative Target Premium Days | Incremental Delta Granted |
| :---------------- | :--------------------------: | :----------------------------: | :-----------------------: |
| Starter Referrer  |              1               |             3 days             |          +3 days          |
| Community Sharer  |              3               |             7 days             |          +4 days          |
| Active Connector  |              5               |            15 days             |          +8 days          |
| Growth Champion   |              10              |            30 days             |         +15 days          |
| Ambassador Silver |              25              |            90 days             |         +60 days          |
| Ambassador Gold   |              50              |            180 days            |         +90 days          |
| Legend Advocate   |             100              |            365 days            |         +185 days         |

### Critical Non-Double-Granting Math

`RewardEngineService::evaluateAndGrantForUser()` strictly calculates incremental delta days:

```php
$deltaDays = $rule->is_repeatable
    ? $rule->cumulative_premium_days
    : max(0, $rule->cumulative_premium_days - $alreadyGrantedDays);
```

- Crossing 1 qualified referral grants **+3 days** (cumulative = 3d).
- Crossing 3 qualified referrals grants **7 - 3 = +4 days** (cumulative = 7d — **never 10d!**).
- Crossing 5 qualified referrals grants **15 - 7 = +8 days** (cumulative = 15d).

## 3. Atomic Concurrency, Idempotency & Budget Ceilings

- Locks the referrer row (`User::lockForUpdate()`) and linked `GrowthCampaign` (`lockForUpdate()`) inside `DB::transaction()`.
- Deterministic idempotency key: `ref_reward:{user_id}:rule:{rule_id}:claim:{claim_index}` backed by a database `UNIQUE` index on `referral_rewards.idempotency_key` and `(user_id, rule_id, claim_index)`.
- Enforces **per-user annual/lifetime ceiling** (`referral_max_reward_days_per_user`, default `365`).
- Enforces **campaign active window** (`starts_at` / `ends_at`, status = `active`) and **reward days budget** (`max_reward_days_budget`).

## 4. Integration with SyncViet Premium Membership

- Calls `PremiumSubscriptionService::grantPromotionalPremium()`:
    - Creates a real `Subscription` row with `payment_id = null`, `amount_paid = 0`, `promotional_days = $deltaDays`, `source = 'referral_reward'`.
    - **Zero-Loss Stacking**: If the user already has an active paid or promotional `premium_until` in the future, promotional days are added **on top of** `premium_until` instead of overwriting it.
    - **Safe Revocation**: `PremiumSubscriptionService::revokePromotionalPremium()` recalculates a `paidFloor` from all active paid subscriptions (`payment_id IS NOT NULL AND amount_paid > 0`) and ensures deducting promotional days **never reduces a member's legally purchased Premium duration**.
    - **Zero Revenue Contamination**: `RevenueAnalyticsService` sums paid `payments` only — promotional Premium is tracked separately under Growth Analytics (`promotional_premium_users` vs `paid_premium_users`).
