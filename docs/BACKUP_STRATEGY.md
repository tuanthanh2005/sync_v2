# SyncViet Backup Strategy (PHẦN 35)

## 1. Backup Scope

1. **MySQL Database (Automated Daily Logical SQL.gz)**:
    - Command: `php artisan app:backup:database` (scheduled daily at `02:00 UTC` via `routes/console.php`).
    - Engine: `App\Services\Admin\DatabaseBackupService` — pure PDO streaming dump + gzip compression (`level 6`), working on Hostinger Shared Hosting even when shell `mysqldump` is restricted.
    - Storage Location: Private `storage/app/private/backups/syncviet-db-YYYYMMDD-HHMMSS-*.sql.gz` (strictly outside `public/`).
    - Integrity: Every archive stores its **SHA-256 checksum** and byte size in `backup_records` and writes an immutable `AuditLogger` entry.
2. **Governance Snapshot (On-Demand Admin Center -> Backups)**:
    - `AdminSecurityService::createSnapshot()` creates a lightweight JSON snapshot of roles, languages, settings, policies, and counts.
3. **Uploaded Media & Private Storage**:
    - Stored in `storage/app/public/` (or Cloudflare R2 when `MEDIA_DISK=s3`). Backed up via Hostinger daily/weekly panel backup + periodic owner off-host archive sync.
4. **Source Code & Release Artifacts**:
    - Versioned in Git (`origin/main`) with `composer.lock` and `package-lock.json` pinned; compiled assets archived per release (`build.zip`).
5. **Encryption Key (`APP_KEY`) Recovery Material**:
    - **CRITICAL**: Store `APP_KEY` and `.env` backup in an **offline encrypted password manager / vault strictly separate from database SQL backups**! Losing `APP_KEY` permanently prevents decrypting stored Gemini API keys, Google Search Console credentials, and 2FA secrets.

## 2. Retention Policy & Disk Hygiene

- **Local Automated Retention**: Default `SYNCVIET_BACKUP_RETENTION_DAYS=7` (pruned automatically after each backup while always preserving at least the **3 newest completed archives**).
- **Recommended Multi-Tier Retention (Owner Off-Host Sync)**:
    - **7 daily** local private `.sql.gz` archives + Hostinger automated backups
    - **4 weekly** off-host copies downloaded by Owner / synced to external encrypted storage
    - **3 monthly** long-term off-host archives
- **Security Controls**: Never place `.sql` or `.sql.gz` inside `public/` or `public_html/`; never commit backups or `.env` to Git; do not activate paid backup add-ons without Owner approval.
