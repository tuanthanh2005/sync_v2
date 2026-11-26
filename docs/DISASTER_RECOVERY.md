# SyncViet Disaster Recovery Plan (PHẦN 36)

## 1. Recovery Objectives (Initial Targets)

- **RPO (Recovery Point Objective)**: Up to **24 hours** for standard social content under daily backups. For **financial transactions**, combine latest DB backup with Payment Provider (Bank/VietQR/PayPal) transaction ledger reconciliation so zero settled payments are permanently lost.
- **RTO (Recovery Time Objective)**: Target **≤ 4 hours** depending on hosting access and dataset size (baseline 117-table local restore verified in **`3.74 seconds`** — see `docs/BACKUP_RESTORE_TEST.md`).

## 2. Incident Response & Recovery Playbook (12 Steps)

Applies to: Database corruption, accidental deletion, failed migration, failed deployment, compromise, hosting failure, lost config, or API credential leak.

1. **Identify & Classify**: Confirm scope (App vs DB vs Storage vs Credential Leak) via `/admin/system-health`, `storage/logs/laravel-*.log`, and Hostinger hPanel.
2. **Isolate System**: Put SyncViet into maintenance mode (`php artisan down --secret=<token>`) and trigger **AI Emergency Stop** if automation/credentials are involved.
3. **Halt Risky Automation**: Temporarily disable Hostinger Cron jobs (`schedule:run`, `queue:work`) during active recovery.
4. **Select Verified Backup**: Pick the newest clean `.sql.gz` from `storage/app/private/backups/` (or off-host archive) and verify SHA-256 (`sha256sum <file>` vs `backup_records.checksum`).
5. **Test-Restore to Isolated Staging DB First**: Never blindly overwrite production without confirming the archive imports cleanly into a temporary DB (`syncviet_restore_staging`).
6. **Verify Database Integrity**: Confirm all 117 tables, row counts, zero negative balances, and schema compatibility.
7. **Verify Media & Private Storage**: Confirm `public/storage` symlink and uploaded assets exist and match `post_media` / `banner_campaigns`.
8. **Verify Configuration & `APP_KEY`**: Restore `.env` with the exact original `APP_KEY` (`APP_DEBUG=false`, `APP_ENV=production`) and verify encrypted Gemini/GSC/2FA secrets decrypt cleanly.
9. **Reconcile Financial Ledger**: Compare settled Bank/VietQR/PayPal receipts since backup timestamp against `payments`, `ad_credit_entries`, and `subscriptions`; manually settle verified missing receipts via Admin Finance.
10. **Verify Premium & Referral Entitlements**: Check `users.premium_until`, `subscriptions`, and `referral_rewards` consistency.
11. **Smoke-Test Critical Flows**: Login, 2FA, Feed, Public Post, `/sitemap.xml`, `/payments`, `/admin/system-health`.
12. **Restore Production & Resume**: Swap restored DB / release, run `optimize:clear` + `config:cache`/`route:cache`/`view:cache`, re-enable Cron jobs, exit maintenance (`php artisan up`), and log post-mortem.

## 3. Specific Playbook: Compromised Credentials / API Keys

- **Gemini API / PayPal / GSC / Webhook Secret Leaked**: Rotate key immediately at provider -> update encrypted credential in Admin Center or `.env` -> run `config:cache` & `queue:restart` -> review `audit_logs`, `ai_usage_records`, and `payment_webhook_logs` for unauthorized usage.
- **Compromised Admin Account**: Revoke all sessions in `sessions` table -> reset password -> rotate TOTP 2FA -> inspect `audit_logs` and `login_histories`.
- **Never rotate `APP_KEY` casually**: Only rotate using Laravel's `APP_PREVIOUS_KEYS` rotation workflow so existing encrypted DB values remain decryptable.
