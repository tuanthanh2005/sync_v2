# SyncViet — Changelog

## [0.2.0] - 2026-11-25 (Phase 0 Upgrade — Footer System, i18n, Security by Design & Server Portability)

### Added

- **Hệ thống Footer đa biến thể & Admin Policy CMS**:
    - Bảng `policy_pages` và `footer_links`, `PolicyFoundationSeeder` khởi tạo sẵn 10 trang chính sách tiêu chuẩn ở trạng thái `draft` (ẩn khỏi công khai cho tới khi Admin duyệt).
    - Các component tái sử dụng không trùng lặp: `FooterLinkList.vue`, `MiniFooter.vue` (dưới Right Sidebar), `FullFooter.vue` (Landing & Public Policy pages), `SimpleFooter.vue` (Auth pages), và liên kết Footer trong Mobile Drawer + Settings layout.
    - Admin CMS tại `/admin/policies` cho phép Admin (bắt buộc bật 2FA) chỉnh sửa nội dung VI/EN, đổi trạng thái `draft`/`published`, bật tắt hiển thị, sắp xếp thứ tự và quản lý liên kết Footer.
    - `PolicySanitizer` lọc HTML XSS ở backend bằng allowlist `DOMDocument`.
- **Đa ngôn ngữ (`vi` / `en`)**:
    - Cài đặt `vue-i18n`, tích hợp `SetLocale` middleware, `LocaleController`, `lang/vi.json`, `lang/en.json`, `LocaleSwitcher.vue`.
- **Security by Design (OWASP Top 10:2025 / ASVS Level 2)**:
    - Chuyển `HASH_DRIVER=argon2id` mặc định trong `config/hashing.php` và `.env`.
    - Middleware `EnsureAdminHasTwoFactor` bắt buộc 2FA cho tài khoản Admin.
    - `BrowserSessionService` + `DELETE /settings/other-browser-sessions` cho phép xem phiên hiện tại và đăng xuất mọi thiết bị khác.
    - Middleware `SecurityHeaders` (`CSP`, `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, `HSTS`).
    - Bảng `audit_logs` (`AuditLogger` tự redact trường nhạy cảm) và `login_histories` (`RecordAuthSecurityEvents`), cùng Rate Limiting cho endpoint đăng ký (`register`).
- **Tài liệu bảo mật & Server Portability**:
    - Cập nhật `AGENTS.md`, `README.md`, `DESIGN_SYSTEM.md`, `PROJECT_PLAN.md`, `ARCHITECTURE.md`, `DATABASE.md`, `SECURITY.md`, `TASKS.md`.
    - Tạo mới `docs/SECURITY_CHECKLIST.md`, `docs/DEPLOYMENT_HOSTINGER.md`, `docs/DEPLOYMENT_VPS.md`, `docs/MIGRATION_GUIDE.md`, `docs/BACKUP_RESTORE.md`.

### Changed

- Chuẩn hóa thương hiệu động qua `APP_NAME=SyncViet` (`page.props.name`), loại bỏ toàn bộ brand name/URL hardcode trên frontend.
- Loại bỏ mật khẩu dev cố định trong `DatabaseSeeder.php` (dùng `config('syncviet.dev_seed_password')` hoặc sinh ngẫu nhiên).

## [0.1.0] - 2026-11-25 (Phase 0 – Initial Foundation)

### Added

- Khởi tạo dự án trên PHP 8.4.28, Laravel 13, Vue 3, TypeScript, Inertia.js 3, Tailwind CSS 4, shadcn-vue, @lucide/vue, Laravel Fortify, Laravel Wayfinder, Pest PHP 5 và Laravel Boost với MySQL 8.4.3.
