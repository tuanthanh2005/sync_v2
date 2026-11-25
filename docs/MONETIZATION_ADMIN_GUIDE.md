# SyncViet Monetization Administration Guide

## 1. Accessing the Monetization Center

Authorized staff with 2FA enabled access the **Monetization** sidebar group inside `/admin`:

1. **Revenue Dashboard (`/admin/monetization/revenue`)** — View dual-currency VND & USD totals separated into Recognized Service Revenue vs Prepaid Advertising Balance Deposits, inspect daily cashflow and operational KPIs, and export formula-safe CSV reports.
2. **Premium Plans & Members (`/admin/monetization/premium`)** — Edit/create Premium plans (VND, USD cents, promo pricing, duration, ad reduction mode), grant complimentary/promotional Premium (audited without fake payments), and extend, suspend, restore, or revoke member subscriptions.
3. **Banner Advertising (`/admin/monetization/banners`)** — Configure 9 desktop/mobile placements (Exclusive vs Rotation, 7/15/30/90d pricing), create Admin-managed banners directly, review self-serve submissions, and trigger one-click **Emergency Pause** on problematic creatives.
4. **Boost Packages (`/admin/monetization/boosts`)** — Configure fixed-duration Boost Post packages (24h/3d/7d/30d/Featured) and link to `/admin/ads` for campaign moderation.
5. **Payments, AdSense & Compliance (`/admin/monetization/settings`)** —
    - **Methods**: Toggle fiat gateways, configure basis-point fees, set USD/VND rate and max pending orders cap (default 5). Keep crypto adapters disabled until legal/merchant clearance.
    - **Pending**: Manually reconcile Bank Transfer / VietQR orders with bank reference + audit note + Admin password.
    - **AdSense**: Configure validated `ca-pub-*` Publisher ID, numeric slot IDs, allowed public pages, and `ads.txt`.
    - **Compliance**: Govern `web`, `pwa`, `android_play`, and `ios_appstore` capabilities.
    - **Webhooks**: Inspect HMAC signature verification audit logs.

## 2. Scheduled Maintenance (`app:monetization:sync`)

Ensure Hostinger cron runs `php artisan schedule:run` every minute. Every 5 minutes, `app:monetization:sync` automatically:

- Expires elapsed Premium subscriptions and dispatches deduplicated 7d / 3d / 1d / expired notifications.
- Activates scheduled banners when `starts_at` arrives and expires ended banners.
- Completes elapsed fixed-package Boost campaigns.
- Expires unpaid pending payment orders older than 24 hours to release pending slots.
