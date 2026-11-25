# SyncViet Administration Center Guide

## 1. Architecture & Layout

- **Dedicated Layout**: `resources/js/layouts/AdminLayout.vue` (auto-applied to all Inertia components under `resources/js/pages/admin/*`).
- **Navigation Structure**: Collapsible, responsive left sidebar grouped into 7 operational domains:
    - **Overview**: Dashboard (`/admin`), Analytics (`/admin/analytics`), System Health (`/admin/system-health`).
    - **User Management**: All / Active / Suspended / Banned / Unverified / Deleted Users (`/admin/users`), User Detail (`/admin/users/{userId}`), Roles & Permissions (`/admin/roles`), Verification Requests (`/admin/verifications`).
    - **Content Management**: Posts (`/admin/content/posts`), Comments (`/admin/content/comments`), Communities (`/admin/content/communities`), Polls (`/admin/content/polls`), Hashtags (`/admin/content/hashtags`), Media (`/admin/content/media`).
    - **Moderation**: Report Center (`/admin/moderation`), Appeals (`/admin/appeals`), Blocked Keywords & Domains (`/admin/content/keywords`).
    - **Advertising**: Campaign Management & Review Queue (`/admin/ads`) with anomaly click-burst signals and status controls (`approve`, `reject`, `pause`, `resume`, `cancel`).
    - **Finance**: Ad Credit Balances, Ledger Transactions, Payments & Refunds, Promotional Credits, Revenue Charts & Formula-Safe CSV Export (`/admin/finance`).
    - **System**: System Settings & Feature Flags (`/admin/settings`), Global Language Management (`/admin/languages`), Notification Broadcast Center (`/admin/notifications`), Policies & Footer (`/admin/policies`), Security & Immutable Audit Logs (`/admin/security`), Portable Snapshot Backups (`/admin/backups`).

## 2. Security Controls

- **Backend Route Enforcement**: Every admin route requires `auth`, `verified`, `active`, `admin.2fa` (`EnsureAdminHasTwoFactor`), `can:access-admin`, AND granular `permission:<slug>` middleware (`EnsureUserHasPermission`).
- **Visibility Parity**: Sidebar menu groups render ONLY modules matching the authenticated staff user's backend RBAC permissions (`auth.user.can.*`).
- **Sensitive Mutations**: Financial ledger adjustments and payment refunds require admin password re-verification, `DB::transaction`, `lockForUpdate()`, `idempotency_key`, non-negative invariants, and immutable `AuditLog` entries.
- **No Fake Modules**: Only implemented user-facing modules have admin controls. Unbuilt future modules (e.g. Referral payouts, user-to-user transfers) are intentionally excluded from admin UI.

## 3. Hostinger Shared Hosting Compatibility

- Zero VPS/Docker/Redis/Node-daemon requirements.
- Cron-driven scheduled tasks in `routes/console.php` (`withoutOverlapping()`):
    - `app:sanctions:expire` (every 5 minutes) — lifts elapsed suspensions & capability restrictions.
    - `app:notifications:dispatch` (every minute) — dispatches due scheduled admin broadcasts.
- Snapshot backups generate portable SHA-256 verified JSON files via Laravel `Storage::disk('local')` without shell `exec()`.
