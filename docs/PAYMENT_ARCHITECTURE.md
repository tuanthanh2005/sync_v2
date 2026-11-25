# SyncViet Payment Architecture

## 1. Unified Order & Gateway Pipeline

All monetary transactions across SyncViet (`topup`, `premium`, `banner`, `boost`) flow through a single audited `payments` table and `MonetizationPaymentService`:

1. **Manual Yes/No Confirmation**: Frontend components (`PaymentConfirmModal.vue`) and backend validation (`confirmed: accepted`) strictly prevent automatic order creation on page open, refresh, or package click.
2. **Server-Only Integer Pricing**: Client-supplied prices/amounts are ignored for catalog services (`PremiumPlan`, `BannerPlacement`, `BoostPackage`). All monetary math uses integer minor units (`VND` whole dong, `USD` cents) and basis points (`fee_percent_bps`) — zero floating-point arithmetic.
3. **Strict Max 5 Pending Orders**: Inside `DB::transaction` with `User::lockForUpdate()`, open (`pending` / `processing`) orders are capped at 5 per user (configurable 1–10 in Admin). Stale orders auto-expire after 24 hours; cancelling or completing an order immediately frees a slot.
4. **Signed Webhook Settlement**: `HmacPaymentWebhookService` verifies HMAC-SHA256 signatures (`X-SyncViet-Timestamp`, `X-SyncViet-Signature`) with a ±300-second replay window, verifies order amount match, logs every attempt to `payment_webhook_logs`, and settles via `MonetizationPaymentService::settleOrder()` strictly once.

## 2. Payment Methods & Crypto Hold

Seeded in `payment_methods` and managed in `/admin/monetization/settings`:

- **Fiat Methods**: `vietqr` (VND), `bank_transfer` (VND), `paypal` (USD).
- **Non-Production Dev Only**: `sandbox` (automatically blocked with `404` when `APP_ENV=production`).
- **Crypto Adapters (Disabled by Default)**: `binance_pay`, `metamask`, `usdt`, `usdc` carry `is_enabled = false`, `is_live_ready = false`, `requires_legal_clearance = true` and cannot be enabled until merchant & legal compliance verification is completed.

## 3. Centralized Payment History (`/payments`)

Authenticated members view all orders across all services with search, service/status filters, one-click order code copy, transfer instructions, pending order cancellation, and anti-IDOR receipt protection (`403` for non-owners).
