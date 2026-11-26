# SyncViet User Growth, Referral, Early Member & Retention System V1.0

## 1. Strategic Mission & Honest Growth Principles

The SyncViet User Growth System (`V1.0`) is designed to organically grow SyncViet from **1 to 1,000+ real human members** and build long-term community retention without paid-ad dependency or artificial metrics.

### Non-Negotiable Governance Rules

1. **Zero Fake Data**: Never generate fake users, fake referrals, fake engagement, fake scarcity timers, or fake social proof.
2. **Human-Only Accounting**: AI Agents (`account_type = ai_agent`), System Official accounts (`system_official`), and Test accounts (`is_test_account = true`) are strictly excluded from:
    - Referral code issuance and attribution
    - Early Member & Founding Member slot allocation
    - Growth KPIs (`DAU`, `WAU`, `MAU`, cohort retention, acquisition mix, community human growth)
3. **Zero Revenue Distortion**: Promotional Premium days granted via Referrals, Early Member, or Admin manual grants create `subscriptions` with `payment_id = null`, `amount_paid = 0`, and `promotional_days > 0`. `RevenueAnalyticsService` strictly aggregates paid `payments`, so promotional grants never contaminate financial revenue.
4. **Safe Defaults on Production Deploy**: Reward auto-grant (`referral_rewards_auto_grant`), Early Member enrollment (`early_member_enabled`), Founding Member (`founding_member_enabled`), and automated Retention campaigns (`retention_reengagement_enabled`, `retention_digest_enabled`) ship **OFF / Draft by default** until explicitly activated by an Administrator in `/admin/growth`.
5. **Hostinger Shared Hosting & VPS Portability**: Runs on PHP 8.4, Laravel 13, MySQL/MariaDB InnoDB, Database Queue, and bounded Cron commands (`schedule:run`), requiring zero Redis, Docker, or background daemon workers.

## 2. Modular Monolith Service Map

All Growth logic lives cleanly inside `app/Services/Growth/`:

| Service                          | Responsibility                                                                                                                                                                     |
| :------------------------------- | :--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GrowthConfigService`            | Cached DB-backed `growth_settings` reads/writes + `growth_activity_logs` audit logging                                                                                             |
| `ReferralService`                | PII-free `SV-XXXXXX` code generation, `/r/{code}` visit tracking, First-Touch registration attribution, Qualification Engine, Admin review, member dashboard payload               |
| `FraudDetectionService`          | Canonical Gmail dot/plus alias comparison, HMAC-keyed IP hashing, hard blocks (self-referral, non-human, mutual loops), and multi-signal risk scoring routing to Manual Review     |
| `RewardEngineService`            | Cumulative milestone delta computation, row-locked idempotent grant via `PremiumSubscriptionService::grantPromotionalPremium()`, campaign budget guardrails, safe revocation       |
| `EarlyMemberAndBadgeService`     | Atomic row-locked Early Member enrollment (max 1,000, non-reusable numbers), Founding Member / permanent Member Badges (batch-loaded with zero N+1), value-based Achievements      |
| `OnboardingAndActivationService` | Skippable optional onboarding (`/onboarding`) pulling real topics/communities/accounts, idempotent `user_activation_events` milestones                                             |
| `RetentionAndDigestService`      | Opt-in/opt-out notification preferences, signed one-click unsubscribe (`/growth/unsubscribe/{user}/{channel}`), frequency-capped re-engagement (3/7/30d) and weekly digest         |
| `GrowthAnalyticsService`         | Daily precomputed `growth_analytics` snapshots (`account_type = 'human' AND is_test_account = false`), D1/D7/D30 cohorts, organic vs referral attribution, human community ranking |

## 3. Core Database Schema (19 Growth Tables)

Migrations:

- `database/migrations/2026_11_29_900001_create_user_growth_and_referral_tables.php`
- `database/migrations/2026_11_29_900002_add_growth_columns_to_users_and_subscriptions.php`

Tables:
`growth_settings`, `growth_campaigns`, `growth_campaign_versions`, `growth_campaign_participants`, `referral_codes`, `referral_visits`, `referrals`, `referral_qualification_events`, `referral_reward_rules`, `referral_rewards`, `early_members`, `user_badges`, `achievements`, `user_achievements`, `onboarding_progress`, `user_activation_events`, `growth_analytics`, `growth_fraud_reviews`, `growth_activity_logs`.

## 4. Scheduled Hostinger-Safe Cron Tasks

Registered in `routes/console.php` with `withoutOverlapping()` and `onOneServer()`:

- `app:growth:qualify-referrals` — Every 30 minutes (`batchLimit = 100`)
- `app:growth:snapshot-analytics` — Hourly (precomputes daily snapshot in `growth_analytics`)
- `app:growth:retention` — Daily at `09:00` (bounded batch, respects opt-out & frequency cap)
- `app:growth:cleanup` — Daily at `03:45` (purges expired `referral_visits` beyond retention window)
