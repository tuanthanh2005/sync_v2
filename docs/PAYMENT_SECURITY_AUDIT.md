# SyncViet Payment, Financial Ledger & Monetization Security Audit

## 1. Server-Side Pricing & Tampering Prevention (PHẦN 10)

- **Verified**: Premium plans (`PremiumSubscriptionService::createPurchaseOrder`), Banner campaigns (`BannerAdvertisingService::createCampaign`), and Boost Post packages (`BoostPostService::createBoost`) strictly resolve plan/placement/package IDs and integer prices from database tables on the backend. Client-supplied `amount` parameters are ignored (verified in `MonetizationSystemTest`: tampering `amount => 1` still produces full server price).
- **Disabled Unapproved Providers**: Crypto payment methods remain disabled by default; `sandbox` is automatically rejected in production (`config('syncviet.sandbox_topup_enabled') === false` when `APP_ENV=production`).

## 2. Webhook Verification, Idempotency & Race Conditions (PHẦN 11)

- **Signature & Replay Protection (`HmacPaymentWebhookService`)**:
    - Verifies `X-SyncViet-Timestamp` (±300s replay window) and constant-time `hash_equals` HMAC-SHA256 signature.
    - Verifies `payment_id`, `provider`, `amount`, and **(AUD-004 Fixed) `currency`** against the server `Payment` record.
    - All settlement operations (`MonetizationPaymentService::settleOrder`, `WalletService::settlePaymentCredit`) lock `Payment` (`lockForUpdate()`), enforce unique `provider_reference`, and check `SubscriptionEvent` / `AdCreditEntry.idempotency_key` so duplicate or concurrent webhooks NEVER double-credit or double-extend.
- **Late Webhook Reconciliation (AUD-001 Fixed)**:
    - Settling `Cancelled`, `Expired`, or `Refunded` orders is blocked in BOTH `MonetizationPaymentService` and `WalletService`.
    - If a valid signed webhook arrives after order expiry/cancellation, `HmacPaymentWebhookService` records `status = late_reconciliation` in `payment_webhook_logs` and writes an immutable `payment.late_webhook_reconciliation_required` alert in `audit_logs` so real received funds are reconciled manually rather than blindly credited or silently dropped.

## 3. Global Max-5 Pending Payment Orders (PHẦN 12)

- **Fixed in AUD-002**: Both `MonetizationPaymentService::createOrder()` and `WalletService::createTopUpCheckout()` lock the user row (`User::lockForUpdate()`), expire elapsed pending orders, and count ALL open `pending`/`processing` orders for that user **across ALL services and payment methods** before allowing a new order. A 6th order (whether same service or cross-service) is rejected with `pending_limit`.

## 4. Financial Ledger & Accounting Integrity (PHẦN 13)

- **Integer Minor Units Only**: All VND amounts and USD cents use `BIGINT`/`INT` integer arithmetic — zero floats.
- **Non-Negative & Double-Entry Invariants**: `WalletService` locks `ad_credit_accounts`, enforces `balance >= 0`, `promo_balance >= 0`, `held_balance >= 0`, appends an immutable `AdCreditEntry` with unique `idempotency_key` for every movement (`deposit`, `hold`, `spend`, `release`, `refund`, `promo_grant`, `admin_adjustment`, `admin_refund`), and strictly separates `paid` vs `promo` credit buckets.
- **Revenue Separation**: `RevenueAnalyticsService` strictly separates Prepaid Advertising Balance Deposits (`service_type = topup`) from Recognized Direct Service Revenue (`premium`, `banner`, `boost`), and excludes promotional Premium (`payment_id = null, amount_paid = 0`).
- **Live DB Reconciliation Check**: `0` negative balance accounts, `0` orphaned payments/subscriptions.

## 5. Premium, Banner & Boost Business Integrity (PHẦN 14)

- **Immediate Backend Expiry**: `User::isPremium()` checks `premium_until->isFuture() && premium_suspended_at === null` in real time — never depending solely on Cron timing.
- **Full Premium Refund Revocation (AUD-004 Fixed)**: Fully refunding a paid Premium order automatically revokes the linked `Subscription` and recalculates `users.premium_until` cleanly.
- **AI & Bot Exclusion (AUD-003, AUD-009 Fixed)**: AI Agents cannot create payments or wallet top-ups, and neither AI Agents nor bots increment paid Boost/Banner impressions or clicks.
- **Boosted Post Safety**: Hidden/deleted boosted posts trigger automatic cancellation and 100% hold refund (`AdCampaignService`).
