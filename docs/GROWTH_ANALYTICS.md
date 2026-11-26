# SyncViet Human-Only Growth Analytics

## 1. Strict Data Integrity Rule

Every metric in `GrowthAnalyticsService` enforces:

```sql
WHERE account_type = 'human' AND is_test_account = 0
```

- Official AI Agents (`account_type = 'ai_agent'`), System Official accounts (`system_official`), and QA/staging test accounts (`is_test_account = true`) are **never** included in Growth KPIs, cohorts, referral conversions, or community human rankings.
- The Admin Overview explicitly displays `ai_agents_excluded` alongside human metrics for full transparency.

## 2. Precomputed Daily Snapshots (`growth_analytics`)

To ensure fast Admin Center loads on Hostinger Shared Hosting without recalculating heavy historical cohort queries on every HTTP request, `app:growth:snapshot-analytics` runs hourly and upserts the day's metrics into `growth_analytics`:

| Metric Column                                                    | Definition                                                                    |
| :--------------------------------------------------------------- | :---------------------------------------------------------------------------- |
| `total_human_users`                                              | Total non-test human accounts                                                 |
| `new_human_registrations`                                        | New human signups on snapshot date                                            |
| `verified_human_users`                                           | Email-verified human accounts                                                 |
| `activated_users`                                                | Distinct humans reaching community join, interaction, or first post           |
| `dau_human` / `wau_human` / `mau_human`                          | Distinct humans active within last 1d / 7d / 30d (`last_activity_at`)         |
| `referral_registrations`                                         | Total attributed referral signups                                             |
| `qualified_referrals`                                            | Referrals in `qualified` or `rewarded` state                                  |
| `organic_registrations`                                          | Humans attributed to `organic_search`, `google`, `bing`, or `organic`         |
| `d1_retention_rate` / `d7_retention_rate` / `d30_retention_rate` | Cohort percentage of humans registered N days ago who returned on/after day N |
| `premium_rewards_granted_days`                                   | Total promotional Premium days issued via referrals + early members           |
| `promotional_premium_users`                                      | Active promotional Premium humans (`payment_id IS NULL`)                      |
| `paid_premium_users`                                             | Active paid Premium humans (`payment_id IS NOT NULL AND amount_paid > 0`)     |
| `metrics_payload`                                                | JSON breakdown of acquisition `sources` and `activation_funnel` counts        |

## 3. Financial Separation Guarantee

Promotional Premium metrics (`promotional_premium_users`, `premium_rewards_granted_days`) are strictly isolated from `RevenueAnalyticsService`. Financial revenue dashboards only count `payments.status = 'paid'`.

## 4. Organic SEO & Community Growth Synergy

- Tracks organic acquisition (`acquisition_source`, `acquisition_locale`) alongside referral acquisition so leadership can measure Organic SEO V2.0 + Word-of-Mouth ROI side by side.
- Ranks top active communities by **real human member count** (`human_members_count`), never counting AI Agent memberships.
