# SyncViet Release Rollback Plan (PHẦN 43)

## 1. Rollback Principles

- **Never run `migrate:rollback` blindly on production** if new financial transactions (`payments`, `ad_credit_entries`, `subscriptions`) or user content were written after deployment.
- SyncViet's production audit migration (`2026_11_30_950001_add_production_audit_composite_indexes`) is **100% additive** (indexes only), so rolling back application code to commit `c0fbc4c` remains fully schema-compatible without touching database data!

## 2. Application & Frontend Asset Rollback Procedure

1. Enable maintenance mode:
    ```bash
    cd ~/domains/syncviet.com/syncviet && $PHP_BIN artisan down --secret=emergency-rollback
    ```
2. Record current commit hash for post-mortem (`git rev-parse HEAD`).
3. Revert application source to the last verified release tag/commit (e.g., `git checkout <previous-good-commit>`).
4. Restore the previous release's `public/build/` archive (keep the previous `build.zip` archived off-host before every deploy).
5. Rebuild Laravel caches and restart queue workers:
    ```bash
    $PHP_BIN artisan optimize:clear && \
    $PHP_BIN artisan config:cache && \
    $PHP_BIN artisan route:cache && \
    $PHP_BIN artisan view:cache && \
    $PHP_BIN artisan queue:restart
    ```
6. Verify `/up`, Homepage, Login, and `/admin/system-health`, then run `$PHP_BIN artisan up`.

## 3. Database Recovery When a Destructive Schema/Data Defect Occurs

1. Do NOT overwrite production directly; follow `docs/DISASTER_RECOVERY.md` (restore the pre-deploy `.sql.gz` archive created by `app:backup:database` into an isolated staging DB first).
2. Reconcile any payments settled between the backup timestamp and incident time using Bank/VietQR/PayPal provider statements before restoring live traffic.
