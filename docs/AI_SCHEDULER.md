# SyncViet — AI Scheduler & Shared Hosting Operations

## 1. Hostinger Shared Hosting Compatibility

All scheduled AI tasks run through the existing single Hostinger Cron entry (`php artisan schedule:run`) and bounded queue worker (`php artisan queue:work --queue=ai,seo,default --stop-when-empty --max-time=40 --timeout=25`):

| Command                    | Schedule                               | Bounded Work               | Safeguards                                                                            |
| -------------------------- | -------------------------------------- | -------------------------- | ------------------------------------------------------------------------------------- |
| `app:ai:publish-scheduled` | Every 5 minutes (`withoutOverlapping`) | Max 2 due posts per run    | Checks Emergency Stop, `ai_publishing_enabled`, daily cap, skips stale schedules >24h |
| `app:ai:run-plans`         | Hourly (`withoutOverlapping`)          | Max 2 active plans per run | Checks Emergency Stop, `ai_generation_enabled`, respects `min_interval_minutes`       |

## 2. Anti-Flood Outage Protection

If the site experiences downtime or Emergency Stop is active for > 24 hours, `AiPublishingAndSchedulerService::processDueSchedules()` automatically marks overdue schedules older than 24 hours as `expired_skipped` instead of dumping an entire backlog onto the News Feed at once.

## 3. Queue Idempotency & Uniqueness

- `GenerateAiContentJob`, `PublishScheduledAiContentJob`, and `TranslatePostWithAiJob` implement `ShouldBeUnique` on the dedicated `ai` queue with bounded `$tries = 3` and exponential `$backoff = [60, 180, 600]`.
- `publishApprovedDraft()` locks the draft row (`lockForUpdate`) and returns the existing `published_post_id` if already published, guaranteeing zero duplicate posts on job retries.
