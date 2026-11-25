# SyncViet Boost Post Specification

## 1. Architecture (Zero Duplicate Ad Tables)

SyncViet Boost Post builds directly on top of the audited `ad_campaigns`, `ad_events`, `ad_daily_stats`, and `ad_credit_accounts` tables — adding `boost_packages` and extending `ad_campaigns` with `boost_package_id`, `payment_id`, `billing_mode` (`'fixed_package'` vs `'cpc_cpm'`), `is_featured_slot`, and `activated_at`.

Default Admin-configurable packages:

- **Boost 24 Hours** (`boost-24h`): 24h · \$0.99 / 25,000 VND
- **Boost 3 Days** (`boost-3d`): 72h · \$2.99 / 75,000 VND
- **Boost 7 Days** (`boost-7d`): 168h · \$4.99 / 125,000 VND
- **Boost 30 Days** (`boost-30d`): 720h · \$14.99 / 379,000 VND
- **Featured Post (7 Days)** (`featured-post-7d`): 168h · \$9.99 / 249,000 VND (`is_featured_slot = true`)

## 2. Eligibility, Payment & Duration Rules

- **Eligible Posts Only**: Only the author's own public, approved, non-deleted posts may be boosted. Duplicate concurrent boost campaigns on the same post are blocked.
- **Two Payment Sources**:
    1. **Advertising Balance (VND)**: Atomically reserves funds via `WalletService::holdForCampaign()` and enters `pending_review`.
    2. **Direct Checkout (VND/USD)**: Creates a pending payment order (`service_type = 'boost'`) after manual Yes/No confirmation; moves from `pending_payment` → `pending_review` once settled via signed webhook.
- **Fair Duration Start**: When Admin approves a `fixed_package` boost in `AdCampaignService::reviewCampaign()`, `activated_at = now()`, `starts_at = now()`, and `ends_at = now()->addHours($package->duration_hours)`. Review wait time never consumes paid duration.
- **Organic Integrity**: Boosted posts are served exclusively through `AdDeliveryService::pickSponsoredPostForFeed()` with a clear **Sponsored** badge. Organic `Trending` feed ordering and engagement counters are never artificially inflated.
- **Safety Auto-Stop & Refund**: If a boosted post is rejected, hidden by moderation, soft-deleted, or its author is banned, the campaign stops immediately and held Advertising Balance funds are refunded 100% atomically.
