# SyncViet System Settings & Feature Management

All platform runtime configuration is persisted in `system_settings` and cached via `App\Services\System\SystemSettingService` (10-minute TTL, invalidated on update, Hostinger `database` cache compatible).

## 1. Feature Flags (Live Backend Enforcement)

Every toggle maps to a real implemented module and is enforced in backend services:

| Setting Key            | Enforced In                              | Effect When Disabled                                  |
| :--------------------- | :--------------------------------------- | :---------------------------------------------------- |
| `feature_registration` | `App\Actions\Fortify\CreateNewUser`      | Rejects new account sign-ups with validation error    |
| `feature_google_login` | `SocialAuthController`                   | Blocks Google OAuth redirect & callback               |
| `feature_posting`      | `PostService::create`                    | Rejects new post creation                             |
| `feature_image_upload` | `PostService::create`                    | Rejects image attachments on new posts                |
| `feature_comments`     | `CommentService::create`                 | Rejects new comments                                  |
| `feature_reactions`    | `PostService::toggleReaction`            | Rejects reaction mutations                            |
| `feature_communities`  | `CommunityService::create`               | Rejects new community creation                        |
| `feature_polls`        | `PostService::create`                    | Disables poll attachment on posts                     |
| `feature_messaging`    | `MessagingService`                       | Blocks starting conversations & sending DMs           |
| `feature_ads`          | `AdCampaignService`, `AdDeliveryService` | Blocks new campaigns & stops sponsored feed injection |
| `feature_payments`     | `WalletService::createTopUpCheckout`     | Blocks new wallet top-ups                             |

## 2. Safe Maintenance Mode

- Setting `maintenance_enabled = true` activates `App\Http\Middleware\CheckMaintenanceMode`.
- Non-staff requests receive `HTTP 503 Service Unavailable` with `Retry-After: 300` and the configured `maintenance_message`.
- **Safe Recovery Guarantee**: Staff accounts (`isStaff() && isActive()`), `/login`, `/logout`, `/two-factor-challenge`, `/forgot-password`, `/reset-password/*`, `/admin*`, `/up`, and `/webhooks/*` remain accessible so administrators can never lock themselves out.

## 3. Content Limits, Advertising Engine & Branding

- **Content Limits**: `content_post_max_length`, `content_post_max_images`, `content_upload_max_mb`, `content_comment_max_length`, rate limits, and moderation mode.
- **Advertising Engine**: `ad_feed_frequency`, `ad_min_daily_budget_vnd`, `ad_max_daily_budget_vnd`, `ad_min_total_budget_vnd`, `ad_max_total_budget_vnd`, `ad_impression_cost_vnd`, `ad_click_cost_vnd` (all strict integer VND).
- **Security Guarantee**: Settings accept only typed scalar values validated against `SystemSettingService::defaults()`. No arbitrary PHP, JS, SQL, or shell code execution is ever permitted.
