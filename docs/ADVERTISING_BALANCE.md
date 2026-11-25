# SyncViet Advertising Balance (Prepaid Ad Credit Ledger)

## 1. Strict Product Scope

The user-facing **Advertising Balance** (`/wallet`) — powered by `ad_credit_accounts` and append-only `ad_credit_entries` — is strictly a prepaid credit balance for SyncViet advertising services (`Boost Post` and eligible ad campaigns):

- **NOT** a general e-wallet, bank account, or stored-value payment instrument.
- **No** cash withdrawals, **no** P2P transfers between users, **no** interest or investment returns.
- **Strict Separation from Premium**: SyncViet Premium NEVER auto-deducts a user's Advertising Balance.
- **Bucket Separation**: Paid deposits (`balance`) and Admin-granted promotional credits (`promo_balance`) are accounted separately in both `ad_credit_accounts` and `ad_credit_entries.wallet_bucket`.

## 2. Accounting & Ledger Integrity

- Every mutation (`deposit`, `hold`, `release`, `spend`, `refund`, `promo_grant`, `admin_adjustment`, `admin_refund`) runs inside `DB::transaction` with `AdCreditAccount::lockForUpdate()`.
- Non-negative invariants guarantee `balance >= 0`, `promo_balance >= 0`, and `held_balance >= 0`.
- Every entry enforces a unique `idempotency_key` preventing double credits or duplicate charges.
- **Revenue Accounting Distinction**: In `/admin/monetization/revenue`, Advertising Balance top-ups are reported under **Prepaid Ad Balance Deposits** (liability until spent), separated cleanly from **Recognized Service Revenue** (Premium, Banner, direct Boost, and settled `total_spent`).
