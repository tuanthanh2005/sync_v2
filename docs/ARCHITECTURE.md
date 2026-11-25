# SyncViet — System Architecture

## 1. Mô hình kiến trúc: Modular Monolith + Service Layer

SyncViet xây dựng theo kiến trúc **Modular Monolith** trên **Laravel 13**, **Vue 3** và **Inertia.js 3 (CSR)** với ranh giới tách bạch:

```text
Browser (Vue 3 + vue-i18n + Inertia 3)
   │
   ▼  Wayfinder typed route/action
Middleware Pipeline (SetLocale -> HandleAppearance -> HandleInertiaRequests -> SecurityHeaders)
   │  + Auth/Governance guards (auth, verified, active, role, admin.2fa, throttle)
   ▼
Form Request (Validation + Input Normalization + Safe URL rules)
   │
   ▼
Controller (Điều phối HTTP, Gate/Policy authorize, Inertia::render / Redirect)
   │
   ▼
Service / Action Layer (DB::transaction, Sanitizer, AuditLogger, Cache Invalidator)
   │
   ├──► Eloquent Models + Policies (Deny by default, Ownership & Role checks)
   ├──► Event Listeners / Jobs (LoginHistory, AuditLog, Queue worker)
   └──► Storage Abstraction (Local / Public / S3-R2 via config/filesystems.php)
```

## 2. Các phân hệ nền tảng (Phase 0)

### 2.1 Localization & Đa ngôn ngữ (`vi` / `en`)

- Backend: `SetLocale` middleware (session + `syncviet_locale` cookie), `LocaleController` (`POST /locale`), `lang/vi.json`, `lang/en.json`.
- Frontend: `vue-i18n` (`resources/js/i18n/index.ts`), `LocaleSwitcher.vue`, đồng bộ reactive với `page.props.locale` qua Inertia `navigate` / `success` hooks.
- Không hardcode brand name (`page.props.name`) hay domain/URL (`config('app.url')`, Wayfinder routes).

### 2.2 Hệ thống Footer & Quản trị Chính sách

- Models & Tables: `PolicyPage` (`policy_pages`), `FooterLink` (`footer_links`).
- Services:
    - `FooterMenuService`: Cache danh sách liên kết công khai (`syncviet.footer.links.v1`), tự động loại bỏ các trang `draft` hoặc `is_visible = false`, tự động làm mới cache khi Admin lưu.
    - `PolicyManagementService`: Quản lý 10 trang chính sách chuẩn và liên kết Footer trong `DB::transaction`, đồng bộ `AuditLogger`.
    - `PolicySanitizer`: Lọc HTML phía backend bằng `DOMDocument` allowlist, loại bỏ `<script>`, `<iframe>`, sự kiện `on*` và scheme `javascript:`/`data:`.
- Frontend Components: `FooterLinkList.vue`, `MiniFooter.vue` (dưới Right Sidebar), `FullFooter.vue` (Landing/Public Policy pages), `SimpleFooter.vue` (Auth layout), và liên kết trong Mobile Drawer + Settings layout.

### 2.3 Security Monitoring & Access Control

- `SecurityHeaders` middleware: `CSP`, `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, `HSTS` (production HTTPS).
- `EnsureAdminHasTwoFactor` (`admin.2fa`): Bắt buộc Admin bật TOTP 2FA mới truy cập được `/admin` và `/admin/policies`.
- `BrowserSessionService`: Liệt kê phiên trình duyệt an toàn và thu hồi mọi phiên/thiết bị khác (`Auth::logoutOtherDevices` + xóa `sessions` DB).
- `AuditLogger` & `RecordAuthSecurityEvents`: Ghi `audit_logs` (ẩn trường nhạy cảm) và `login_histories` cho `Login`, `Failed`, `Logout`, `2FA`, thay đổi mật khẩu, quản trị chính sách/footer.

### 2.4 Server Portability Abstraction

- **Storage**: Sử dụng `config('syncviet.media_disk')` ánh xạ qua `config/filesystems.php` (`local`, `public`, hoặc `s3` cho Cloudflare R2).
- **Cache & Queue & Session**: Chạy `database` trên Hostinger Shared Hosting; chuyển sang `redis` trên VPS chỉ bằng `.env` (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`).
- **Config & Env**: Mọi thông số môi trường, secrets, brand name, URL và seed credentials quản lý ngoài source code qua `.env` + `config/*.php`.
