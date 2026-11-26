# SyncViet Database Integrity & Query Performance Audit (PHẦN 20 & 21)

**Target Audited**: Local MySQL `8.4.3` (`connecthub` database)  
**Audit Date**: 2026-11-26

## 1. Schema Integrity Verification

| Audit Check                                                                          | Measured Result                              |  Status  |
| :----------------------------------------------------------------------------------- | :------------------------------------------- | :------: |
| Total Tables                                                                         | **117 tables**                               | **PASS** |
| Storage Engine (`InnoDB`)                                                            | **117 / 117 InnoDB** (`0` MyISAM/non-InnoDB) | **PASS** |
| Character Set & Collation (`utf8mb4`)                                                | **117 / 117 `utf8mb4`** (`0` non-utf8mb4)    | **PASS** |
| Primary Keys                                                                         | **117 / 117 tables have PK** (`0` missing)   | **PASS** |
| Foreign Key Constraints                                                              | **200 Foreign Keys**                         | **PASS** |
| Total Database Size (Baseline)                                                       | **6.53 MB**                                  | **PASS** |
| Negative Balance Accounts (`ad_credit_accounts`)                                     | **0**                                        | **PASS** |
| Orphan Record Checks (`posts`, `comments`, `payments`, `subscriptions`, `referrals`) | **0 orphans across all 5 checks**            | **PASS** |

## 2. Composite Index Optimization Applied (`AUD-014`)

Migration `database/migrations/2026_11_30_950001_add_production_audit_composite_indexes.php` (100% additive, zero data risk, executed in `169.62 ms`):

- `payments`: `payments_user_status_exp_idx (user_id, status, expires_at)` — accelerates global max-5 pending payment checks and expiry cleanup.
- `referrals`: `referrals_status_eligible_idx (status, eligible_at)` — accelerates 15-minute `app:growth:qualify-referrals` batches.
- `communities`: `communities_vis_status_members_idx (visibility, status, members_count)` — accelerates active public community search and onboarding lists.

## 3. `EXPLAIN` Query Plan Audit (9 Core Hot Paths)

All 9 representative production queries verified on MySQL `8.4.3`:

| Query Path                                    | Access Type | Index Used                                                           | Full Table Scan? |
| :-------------------------------------------- | :---------: | :------------------------------------------------------------------- | :--------------: |
| Public News Feed (`posts`)                    |    `ref`    | `posts_feed_idx` (Backward index scan)                               |      **No**      |
| User Profile Posts (`posts`)                  |    `ref`    | `posts_author_feed_idx`                                              |      **No**      |
| Community Feed (`posts`)                      |    `ref`    | `posts_community_feed_idx`                                           |      **No**      |
| User Notifications (`notifications`)          |    `ref`    | `notifications_notifiable_type_notifiable_id_index`                  |      **No**      |
| Post Comments Thread (`comments`)             |    `ref`    | `comments_post_thread_idx`                                           |      **No**      |
| Pending Payments Cap (`payments`)             |    `ref`    | `payments_user_id_created_at_index` / `payments_user_status_exp_idx` |      **No**      |
| SEO Sitemap Registry (`seo_url_registry`)     |    `ref`    | `seo_registry_eligibility_sitemap_idx`                               |      **No**      |
| AI Pending Review Queue (`ai_content_drafts`) |    `ref`    | `ai_drafts_status_sched_idx`                                         |      **No**      |
| Due Pending Referrals (`referrals`)           |    `ref`    | `referrals_status_index` / `referrals_status_eligible_idx`           |      **No**      |

## 4. Unindexed `*_id` Column Review

21 polymorphic or secondary identifier columns (e.g., `audit_logs.auditable_id`, `notifications.notifiable_id`, `seo_url_registry.entity_id`, `reports.reportable_id`) are paired with composite `(type, id)` indexes where `type` is the leading column (`SEQ_IN_INDEX = 1`), which is optimal for polymorphic queries and avoids redundant write overhead.
