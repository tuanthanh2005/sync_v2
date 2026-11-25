# SyncViet Banner Advertising System

## 1. Placements & Distribution Modes

Placements are stored in `banner_placements` and managed via `/admin/monetization/banners`:

- **Desktop**: `desktop_feed_top` (728×90 Exclusive), `desktop_sidebar_right` (300×250 Rotation), `desktop_sidebar_premium` (300×600 Exclusive), `desktop_explore_top` (728×90 Rotation), `desktop_community_top` (728×90 Rotation).
- **Mobile**: `mobile_after_composer` (320×100 Exclusive), `mobile_in_feed` (3:1 Rotation), `mobile_explore` (320×100 Rotation), `mobile_community` (320×100 Rotation).

Pricing supports 7, 15, 30, and 90-day durations in both integer VND and USD cents.

## 2. Double-Booking & Concurrency Protection

`BannerAdvertisingService::assertSlotAvailable()` executes inside `DB::transaction` while holding a row lock (`BannerPlacement::lockForUpdate()`):

- **Exclusive (`exclusive`)**: Strictly rejects any overlapping campaign (`pending_review`, `scheduled`, or `active`) whose `[starts_at, ends_at)` overlaps the requested range.
- **Rotation (`rotation`)**: Enforces `overlappingCount < max_active_banners`.

## 3. Creative Security, Moderation & Display

- Accepts separate Desktop and Mobile uploads (JPG/PNG/WebP via `SafeImageUploadService` — magic-byte verified, GD re-encoded, EXIF stripped, stored on configured `Storage` disk) OR SSRF-safe public HTTPS image URL import (`importImageFromUrl` blocking private/loopback/CGNAT IPs and redirects).
- Destination `target_url` is validated by `SafeLinkPreviewService::isSafePublicUrl()`.
- All self-serve campaigns require payment settlement followed by Admin review (`pending_payment` → `pending_review` → `scheduled`/`active` or `rejected`).
- **Emergency Pause**: Admins can immediately halt any live banner (`emergency_paused_at` set, status `paused`), removing it instantly from all placements.
- **Clean UX**: `BannerSlot.vue` auto-collapses when no active banner is available, labels every banner clearly as **Sponsored**, provides a 3-dot menu (`About this ad`, `Hide this ad`, `Report ad`), never displays popups/overlays, and honors Premium `ad_free` browsing.
- Clicks route through `/banners/{id}/click` with deduplicated `banner_events` tracking (`impression`, `click`, `hide`, `report`).
