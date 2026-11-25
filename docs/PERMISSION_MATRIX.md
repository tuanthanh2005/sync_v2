# SyncViet RBAC Permission Matrix

SyncViet implements native Laravel RBAC via `roles`, `permissions`, `role_permission`, `users.rbac_role_id`, `App\Services\Security\RbacService`, `EnsureUserHasPermission` middleware, and Laravel Policies/Gates.

## 1. Role Hierarchy

| Role Slug             | Name                | Hierarchy Level | Description                                                                                         |
| :-------------------- | :------------------ | :-------------: | :-------------------------------------------------------------------------------------------------- |
| `super_admin`         | Super Admin         |       100       | Unrestricted governance (`*`); protected from self-demotion and last-Super-Admin removal            |
| `admin`               | Administrator       |       80        | Full operational administration across users, content, ads, finance, settings, and security         |
| `moderator`           | Moderator           |       50        | Community safety, moderation queue, appeals, user suspensions/restrictions, and content enforcement |
| `content_manager`     | Content Manager     |       45        | Posts, comments, communities, polls, hashtags, media, policies, and translations                    |
| `finance_manager`     | Finance Manager     |       45        | Ad credit wallets, ledger adjustments, payments, refunds, promo credits, and revenue reports        |
| `advertising_manager` | Advertising Manager |       45        | Campaign review, delivery controls, ad performance, and anomaly monitoring                          |
| `support_staff`       | Support Staff       |       30        | Read-only diagnostics, verification triage, appeals viewing, and support broadcasts                 |
| `user`                | Member              |        0        | Standard community member (zero admin access)                                                       |

## 2. Anti-Privilege-Escalation Rules

1. Users cannot modify their own role or apply sanctions to themselves.
2. Staff cannot moderate or assign roles to users with equal or higher `hierarchy_level` (except `super_admin`).
3. Staff cannot assign any role with `hierarchy_level >= actor.hierarchyLevel()` (except `super_admin`).
4. The system prevents banning, deactivating, soft-deleting, or demoting the final remaining `super_admin`.
5. `super_admin` permissions cannot be restricted via the matrix UI.
6. Mandatory TOTP 2FA (`EnsureAdminHasTwoFactor`) is enforced on all Administrator accounts.

## 3. Module Permission Catalog

- `dashboard`: `view`
- `analytics`: `view`, `export`
- `system_health`: `view`, `manage`
- `users`: `view`, `update`, `suspend`, `ban`, `restore`, `delete`, `manage`
- `roles`: `view`, `update`, `manage`
- `verification`: `view`, `approve`, `reject`
- `posts`: `view`, `update`, `delete`, `restore`, `manage`
- `comments`: `view`, `update`, `delete`, `restore`
- `communities`: `view`, `update`, `delete`, `restore`, `manage`
- `polls`: `view`, `update`, `delete`
- `hashtags`: `view`, `update`, `manage`
- `media`: `view`, `update`, `delete`
- `moderation`: `view`, `approve`, `reject`, `manage`
- `appeals`: `view`, `approve`, `reject`
- `keywords`: `view`, `create`, `update`, `delete`
- `ads`: `view`, `update`, `approve`, `reject`, `manage`
- `finance`: `view`, `update`, `approve`, `reject`, `export`, `manage`
- `settings`: `view`, `update`, `manage`
- `features`: `view`, `update`
- `languages`: `view`, `update`, `export`, `manage`
- `notifications`: `view`, `create`, `update`, `delete`, `manage`
- `policies`: `view`, `create`, `update`, `delete`
- `security`: `view`, `export`, `manage`
- `backups`: `view`, `create`, `export`

Sync command: `php artisan app:rbac:sync` (also runs via `DatabaseSeeder`).
