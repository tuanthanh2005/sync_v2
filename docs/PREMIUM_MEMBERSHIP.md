# SyncViet Premium Membership Specification

## 1. Plans & Dynamic Pricing

All plans live in `premium_plans` and are fully managed in `/admin/monetization/premium` (never hardcoded in frontend or business logic). Reference seeded defaults:

| Plan Slug     | Duration | Default USD          | Default VND | Ad Mode   |
| :------------ | :------- | :------------------- | :---------- | :-------- |
| `premium-1m`  | 30 days  | \$2.99 (299 cents)   | 79,000 VND  | `reduced` |
| `premium-3m`  | 90 days  | \$7.99 (799 cents)   | 199,000 VND | `reduced` |
| `premium-6m`  | 180 days | \$14.99 (1499 cents) | 389,000 VND | `reduced` |
| `premium-12m` | 365 days | \$24.99 (2499 cents) | 649,000 VND | `ad_free` |

Admin can edit names, USD cents, VND prices, promotional prices, duration, ad reduction modes, benefits, sort order, and active/featured flags at any time.

## 2. Premium Badge (`#1D9BF0`) & Identity Distinction

- Component: `resources/js/components/monetization/PremiumBadge.vue` (`sm`: 15px in posts/comments/replies/search/messages/members; `md`: 20px on profiles).
- Clicking or hovering the badge displays an accessible tooltip explicitly distinguishing **SyncViet Premium Member** from **Identity Verification (`Profile::is_verified_badge`)**, **Email Verification**, **Official Organization**, and **Administrator/Staff**.
- Premium membership NEVER grants moderation immunity or bypasses security/anti-spam controls.

## 3. Lifecycle, Extension & Zero-N+1 Reads

- **Direct Checkout Only**: Premium is billed directly via `POST /premium/checkout` (`service_type = 'premium'`) with mandatory manual Yes/No confirmation (`confirmed: accepted`). It NEVER auto-deducts a user's Advertising Balance.
- **Webhook Activation**: `PremiumSubscriptionService::activateFromPayment()` runs inside `DB::transaction` with `User::lockForUpdate()` and enforces `subscription_events(payment_id, event_type)` uniqueness so duplicate webhooks can NEVER activate or extend twice.
- **Zero-Loss Extension**: Renewing while `users.premium_until > now()` adds `plan.duration_days` directly onto the existing `premium_until`.
- **Real-Time Backend Expiry**: `User::isPremium()` verifies `premium_until->isFuture()` and `premium_suspended_at === null` on every read (0 extra DB queries per post/comment), ensuring expired badges vanish immediately even before cron runs.
- **Reminders**: `php artisan app:monetization:sync` marks elapsed subscriptions `expired` and dispatches deduplicated 7-day, 3-day, 1-day, and expired notifications.
- **Admin Grants**: Administrative comps and promotions record `source = 'admin_grant'` or `'promotion'` with `payment_id = null` and `amount_paid = 0` — never fabricating fake payments.
