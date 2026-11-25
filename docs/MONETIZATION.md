# SyncViet Monetization Architecture Overview

## 1. Business Model

SyncViet monetization is designed to be transparent, international, Hostinger-compatible, and non-intrusive. Core social features (registration, posting, commenting, reacting, sharing, following, communities, messaging, search, notifications, reporting, blocking) remain **100% free**.

Three primary paid services power the platform:

1. **SyncViet Premium** (`/premium`, `/account/premium`) — Time-limited prepaid membership with the custom SyncViet blue badge (`#1D9BF0`), reduced/ad-free browsing, and post performance visibility.
2. **SyncViet Banner Advertising** (`/advertising/banner`) — Time-based (7/15/30/90 days) desktop & mobile banner rentals with Exclusive locking or multi-brand Rotation.
3. **SyncViet Boost Post** (`/advertising/boost`) — Fixed-duration (24h/3d/7d/30d/Featured) post promotion built on top of SyncViet's moderated `ad_campaigns` engine.

Expansion readiness is built into the core without fake UI or unverified shortcuts:

- **Google AdSense** (controlled structured configuration, zero raw JS injection).
- **Native App Store Readiness** (`PlatformCapabilityService`, `platform_compliance_rules`, `GooglePlayBillingAdapter` & `AppleStoreKitAdapter` contracts).
- **Future Crypto Payment Adapters** (`binance_pay`, `metamask`, `usdt`, `usdc`) disabled by default until merchant/legal clearance.

## 2. Services Center & Public SEO

Publicly accessible without authentication:

- `/services` — Unified Services Center overview with live DB pricing, VND/USD switcher, benefits, and FAQ.
- `/premium` — SyncViet Premium pricing & badge transparency page.
- `/advertising`, `/advertising/banner`, `/advertising/boost` — Advertising catalog, live placement availability, creative preview, and package specs.
- `/sitemap.xml` — Dynamic XML sitemap covering public pages, Services Center, published policies, and public posts (strictly excluding `/wallet`, `/payments`, `/admin`, and draft policies).
- `/ads.txt` — Dynamic `ads.txt` endpoint served only when a valid AdSense Publisher ID is configured.

Checkout, Advertising Balance (`/wallet`), Payment History (`/payments`), Account Premium (`/account/premium`), and all `/admin/*` routes emit `<meta name="robots" content="noindex, nofollow">`.

## 3. Server Portability

Every monetization service operates cleanly on **Hostinger Shared Hosting** (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, scheduled `php artisan schedule:run` every minute invoking `app:monetization:sync` every 5 minutes) and migrates seamlessly to VPS/Cloud without code changes.
