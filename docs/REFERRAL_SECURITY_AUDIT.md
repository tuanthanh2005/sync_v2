# SyncViet Referral & User Growth Security Audit (PHẦN 19)

**Audit Scope**: PROMPT 13 (`app/Services/Growth/*`, `app/Http/Controllers/Growth/*`, `app/Http/Controllers/Admin/Growth/*`, `tests/Feature/Growth/UserGrowthAndReferralTest.php`).

## 1. Anti-Abuse & Attribution Integrity

- **PII-Free Non-Sequential Codes**: Cryptographically random `SV-XXXXXX` codes using `random_int()` over confusion-free alphabet; AI Agents, System Official, and Test accounts are strictly rejected.
- **SEO & Open-Redirect Safe Landing (`/r/{code}`)**: Always emits `X-Robots-Tag: noindex, nofollow`, filters crawlers/bots from inflating `visits_count`, sets `HttpOnly`/`SameSite=Lax` First-Touch cookie `sv_ref_code`, and strictly allows only relative internal `?next=/...` paths (rejecting `https://evil.com`, `//`, `\`).
- **First-Touch Lock**: Never overwrites `users.referred_by_user_id` or allows retroactive claims.
- **Self-Referral & Loop Hard Blocks**: Blocks identical user IDs, canonical Gmail dot/plus aliases (`john.doe@gmail.com` === `johndoe+promo@gmail.com`), non-human accounts, and direct mutual loops (`A -> B` prevents `B -> A`).
- **Fair Shared-IP Multi-Signal Review**: Repeated HMAC-SHA256 hashed IP + high hourly velocity routes to `under_review` (`growth_fraud_reviews`) for human inspection **without automatically banning shared office/campus Wi-Fi users**.

## 2. Cumulative Reward Engine & Financial Safety

- **Zero Double-Granting**: Calculates strictly incremental delta days (`3d -> +4d=7d -> +8d=15d`) inside `User::lockForUpdate()` + `GrowthCampaign::lockForUpdate()` with deterministic `idempotency_key` (`UNIQUE`).
- **Zero Revenue Contamination**: Promotional Premium calls `PremiumSubscriptionService::grantPromotionalPremium()` (`payment_id = null`, `amount_paid = 0`, `promotional_days > 0`), leaving `RevenueAnalyticsService` completely unaffected.
- **Paid-Floor Safe Revocation**: Revoking fraudulent rewards deducts ONLY promotional days and strictly preserves any active legally purchased Premium expiry floor.
- **Atomic Early Member Cap**: Row-locked enrollment guarantees concurrent signups never exceed `early_member_max_slots` (`1,000`) and revoked `member_number` values are never reassigned.
- **Privacy & Compliance**: Member `/referral` displays masked `@username` only (zero referee email/IP PII); signed one-click unsubscribe (`/growth/unsubscribe/{user}/{channel}`) enforces Laravel `signed` middleware (`403` on tampered URLs); Admin CSV export neutralizes spreadsheet formula injection (`=`, `+`, `-`, `@`).

## 3. Default Production Launch Posture

Reward auto-grant, Early Member enrollment, Founding Member, and Retention email campaigns default to **OFF / Draft** until an Administrator reviews caps and enables them in `/admin/growth`.
