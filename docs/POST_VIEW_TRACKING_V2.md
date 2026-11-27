# SyncViet – Advanced Post View Tracking V2.0

## 1. Overview & Motivation

Advanced Post View Tracking V2.0 upgrades SyncViet's News Feed view measurement from the legacy `2s / 6-hour (30m)` rule to an industry-standard Social News Feed architecture:

- **Visibility threshold**: `>= 50%` of the post card (or `>= 50%` of the representative viewport window for tall posts exceeding `90%` of viewport height).
- **Minimum continuous dwell**: `>= 500ms` (configurable `100–10,000ms`) in an active browser tab (`document.visibilityState === 'visible'`).
- **Server-side deduplication**: **5 minutes (`300s`)** per `(post_id, viewer_hash)` using both sliding-window lookup (`created_at > now - window`) and atomic database unique constraint `(post_id, viewer_hash, viewed_bucket)` + cache lock for race-condition safety.
- **Real-time counter synchronization**: `POST /posts/views/batch` returns backend-confirmed counts (`counts[post_id]`), updating News Feed, Profile, Community, Search, Topic, and Post Detail cards immediately without reload or WebSockets. Frontend NEVER optimistically increments views before backend confirmation.
- **Anti-idle guarantee**: Staying idle on an open page past 5 minutes does not auto-increment views; a fresh qualifying view (viewport exit + re-entry, revisit, or reload after `>= 5 minutes`) is required.

## 2. Viewer Eligibility & Source Classification

| Source / Viewer Type                                                          | Column on `posts`           | Included in `views_count`                 | Included in `unique_human_viewers_count` / Paid Ad Reach / DAU |
| ----------------------------------------------------------------------------- | --------------------------- | ----------------------------------------- | -------------------------------------------------------------- |
| Logged-in Human / Premium / Admin / Moderator                                 | `human_views_count`         | Yes                                       | Yes (Human)                                                    |
| Anonymous Guest (valid session)                                               | `guest_views_count`         | Yes                                       | `unique_viewers_count` & Human/Guest Organic/Promoted          |
| AI Agent (Web UI with viewport + 500ms)                                       | `ai_views_count`            | Yes (with disclosure tooltip)             | **Strictly Excluded**                                          |
| AI Agent (Backend content processing: reply draft, translation)               | `ai_content_accesses_count` | Yes (with disclosure tooltip)             | **Strictly Excluded**                                          |
| Pre-V2.0 Historical Uncategorized Views                                       | `legacy_views_count`        | Preserved untouched                       | Never fabricated as human                                      |
| Author Self-View                                                              | —                           | **No** (default OFF; toggleable by Admin) | No                                                             |
| Googlebot, Search Crawlers, Prefetch, Background Jobs, Test & System Accounts | `invalid_views_count`       | **No**                                    | No                                                             |

### Strict AI Agent Rules

- AI Agents NEVER trigger views merely from creation, publishing posts, running schedulers, fetching post ID lists, SEO sync jobs, or database sync.
- Backend AI content access (`PostViewTrackingService::recordAiContentAccess()`) is ONLY recorded when an enabled AI Agent genuinely reads and processes post content (e.g., drafting an AI reply or translating a post), respects the exact 5-minute deduplication window per `ai_agent:{id}`, and NEVER simulates browser viewport telemetry.
- AI views are strictly excluded from `unique_human_viewers_count`, Human DAU/MAU (`AdminAnalyticsService`), Organic Human Traffic (`OrganicGrowthService`), Trending Discovery (`SearchService`), Referral Qualification, and Paid Ad / Banner Impressions (`AdDeliveryService`, `BannerAdvertisingService`).

## 3. Database Schema & Legacy Preservation

Migration `2026_12_01_960003_upgrade_post_view_tracking_v2.php` non-destructively extends:

- `posts`: `human_views_count`, `guest_views_count`, `ai_views_count`, `ai_content_accesses_count`, `legacy_views_count`, `invalid_views_count`, `unique_human_viewers_count`. Existing `views_count` is preserved and copied into `legacy_views_count` for historical rows without resetting or guessing viewer source.
- `post_view_events`: `ai_agent_id`, `viewer_type`, `visible_ms`, `visible_ratio`, `is_valid`, plus composite indexes `post_view_events_sliding_dedup_idx`, `post_view_events_type_created_idx`, and `post_view_events_unique_type_idx`.

Raw rows in `post_view_events` are automatically pruned by `app:views:prune` (daily at `02:45`, default `30` days) while all aggregate counters on `posts` remain permanent.

## 4. Admin Configuration & Auditability

Configurable at `/admin/settings?tab=views` (requires `settings.manage` RBAC permission; all changes recorded via `AuditLogger`):

- `views_min_duration_ms` (default `500`, `100–10000`)
- `views_min_visible_ratio` (default `50`, `10–100`)
- `views_dedup_window_seconds` (default `300`, `30–86400`)
- `views_track_guests` (default `true`)
- `views_track_ai` (default `true`)
- `views_track_author_self` (default `false`)
- `views_bot_filtering` (default `true`)
- `views_retention_days` (default `30`, `7–180`)
