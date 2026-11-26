# SyncViet Referral Anti-Fraud & Fair Review System

## 1. Core Design Principle: Protect Without False Bans

Shared office Wi-Fi, universities, family households, and carrier-grade NAT (CGNAT) mobile networks legitimately produce multiple real human registrations from the same IP address. Therefore:

- **SyncViet NEVER automatically bans or suspends a member based on IP similarity alone.**
- Hard blocks apply ONLY to provably disallowed actions (self-referral, Gmail alias self-referral, non-human accounts, mutual referral loops).
- Velocity and shared-IP anomalies route referrals to **Manual Review** (`status = under_review`, `growth_fraud_reviews.status = pending`) where a human Moderator/Admin can approve or reject with an audit reason.

## 2. Hard Blocks (Immediate Rejection)

Enforced in `FraudDetectionService::evaluateReferral()`:

1. **Self-Referral (Same User ID)**: `referrer_id === referee_id`.
2. **Self-Referral (Canonical Email Alias)**: Normalizes email aliases via `canonicalEmailKey()` — stripping `+tag` suffixes for all domains and stripping dots (`.`) for `gmail.com` / `googlemail.com` (e.g., `john.doe@gmail.com` === `johndoe+ref@gmail.com`).
3. **Non-Human Accounts**: Blocks AI Agents, System Official, and Test Accounts as either referrer or referee.
4. **Mutual Referral Loop**: Blocks `B -> A` if a referral `A -> B` already exists.

## 3. Multi-Signal Risk Scoring Matrix

| Risk Signal                         | Weight | Condition                                                                             |
| :---------------------------------- | :----: | :------------------------------------------------------------------------------------ |
| `repeated_ip_same_referrer_24h`     |  +35   | ≥ 2 prior referrals for the same referrer from the same HMAC-hashed IP in 24h         |
| `high_hourly_registration_velocity` |  +40   | ≥ `fraud_velocity_hourly_threshold` (default 5) referrals for same referrer within 1h |
| `moderation_risk_signal`            |  +35   | Referee non-active / has violations, or referrer has > 1 violation                    |
| `unverified_signup_burst`           |  +30   | ≥ 5 unverified pending referrals for same referrer in 24h                             |

- Risk score is capped at `100`.
- When `score >= fraud_manual_review_score` (default `50`, configurable `20`–`95`), the referral transitions to `under_review` and opens a `growth_fraud_reviews` queue item (reflected in the Admin sidebar pending badge).

## 4. Privacy & Compliance Controls

- **Zero Raw IP Storage**: `FraudDetectionService::hashIp()` hashes IP addresses using `hash_hmac('sha256', trim($ip), config('app.key'))`.
- **Automatic Visit Pruning**: `app:growth:cleanup` runs daily at `03:45` and deletes `referral_visits` rows past `referral_visit_retention_days` (default 90 days).
- **Safe Reward Clawback**: If fraud is confirmed after a reward was granted, revoking the `ReferralReward` deducts ONLY the promotional days granted and **never** subtracts legally purchased paid Premium days.
- **Formula-Safe CSV Exports**: `GrowthReferralsAndFraudController::exportReferralsCsv()` neutralizes spreadsheet formula injection (`=`, `+`, `-`, `@`, `\t`, `\r`) by prefixing a single quote `'`.
