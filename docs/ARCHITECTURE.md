# SyncViet — System Architecture

## 1. Mô hình kiến trúc: Modular Monolith + Service Layer

SyncViet xây dựng theo kiến trúc **Modular Monolith** trên **Laravel 13**, **Vue 3** và **Inertia.js 3 (CSR)** với ranh giới tách bạch:

```text
Browser (Vue 3 + vue-i18n + Inertia 3)
   │
   ▼  Wayfinder typed route/action
Middleware Pipeline (SetLocale -> HandleAppearance -> CheckMaintenanceMode -> HandleInertiaRequests -> SecurityHeaders)
   │  + Auth/Governance guards (auth, verified, active, role, permission:*, admin.2fa, throttle)
   ▼
Form Request (Validation + Input Normalization + Safe URL rules)
   │
   ▼
Controller (Điều phối HTTP, Gate/Policy authorize, Inertia::render / Redirect)
   │
   ▼
Service / Action Layer (DB::transaction, Sanitizer, AuditLogger, Cache Invalidator, Admin Services)
   │
   ├──► Eloquent Models + Policies + RBAC (Deny by default, Ownership & Permission checks)
   ├──► Event Listeners / Scheduled Commands (LoginHistory, AuditLog, Sanctions Expiry, Notification Dispatch)
   └──► Storage Abstraction (Local / Public / S3-R2 via config/filesystems.php)
```

## 2. Các phân hệ nền tảng & mở rộng

### 2.1 Global Localization & RTL (15 Ngôn ngữ)

- Hỗ trợ 15 ngôn ngữ hoàn chỉnh: `en` (mặc định & fallback), `vi`, `es`, `pt-BR`, `id`, `hi`, `th`, `fil`, `fr`, `de`, `ja`, `ko`, `zh-CN`, `ar` (full RTL), `tr`.
- Backend: `SetLocale` middleware (ưu tiên DB `users.preferred_locale` -> session -> `syncviet_locale` cookie -> `Accept-Language` header khi truy cập lần đầu -> default `en`), `LocaleController` (`POST /locale`), `lang/{code}.json`, bảng `languages` & `translation_overrides`.
- Frontend: `vue-i18n` (`resources/js/i18n/index.ts`), `resources/js/i18n/locales/{code}.json`, lá cờ SVG nội bộ `FlagIcon.vue` (không phụ thuộc CDN), dropdown tìm kiếm `LocaleSwitcher.vue`, định dạng ngày/số/tiền tệ theo locale (`resources/js/lib/formatters.ts`), đồng bộ thuộc tính `document.documentElement.dir` (`rtl` / `ltr`) và `lang`.
- Quản trị: Admin có thể bật/tắt ngôn ngữ đã đạt kiểm thử, chọn ngôn ngữ mặc định, ghi đè bản dịch theo key và import/export JSON (`LanguageManagementController`). Chi tiết xem [docs/LOCALIZATION.md](LOCALIZATION.md).

### 2.2 Global Toast Notification & Dialog System

- API thống nhất `appToast` (`resources/js/lib/toast.ts`) hỗ trợ `success`, `error`, `warning`, `info`, `loading`, `action`, khử trùng lặp thông báo cùng nội dung trong cửa sổ thời gian và làm sạch thông điệp (không bao giờ lộ exception/stack trace).
- Component `Sonner.vue` chuẩn hóa: nút `X` đóng rõ ràng trên mọi toast, đóng bằng phím `Escape`, thanh tiến trình tự động đếm ngược và tạm dừng khi hover/focus, hỗ trợ Light/Dark Mode và tự đổi vị trí cho RTL.
- Đồng bộ tự động với Inertia flash messages, validation errors, `httpException` và `networkError` qua `resources/js/lib/flashToast.ts`.
- Thay thế hoàn toàn `window.alert()` / `window.confirm()` bằng `ConfirmActionDialog.vue` và `ReasonDialog.vue`.

### 2.3 Administration Center & RBAC

- **Layout riêng biệt**: `AdminLayout.vue` (tự động ánh xạ trong `resources/js/app.ts` cho mọi trang `admin/*`), sidebar thu gọn và responsive chia 7 phân hệ (`Overview`, `User Management`, `Content Management`, `Moderation`, `Advertising`, `Finance`, `System`).
- **RBAC chi tiết**: 8 vai trò có thứ bậc (`super_admin`, `admin`, `moderator`, `content_manager`, `ad_manager`, `finance_manager`, `support_agent`, `analyst`) và 120+ quyền hạn dạng `module.action` kiểm soát qua `RbacService` và middleware `EnsureUserHasPermission` (`permission:*`). Chi tiết xem [docs/ADMINISTRATION.md](ADMINISTRATION.md) và [docs/PERMISSION_MATRIX.md](PERMISSION_MATRIX.md).
- **Admin Service Layer (`app/Services/Admin/`)**: `AdminUserService`, `AdminContentService`, `AdminAnalyticsService`, `AdminFinanceService`, `AdminSecurityService`, `AdminNotificationService`.
- **Feature Flags & Safe Maintenance Mode**: `SystemSettingService` + `CheckMaintenanceMode` cho phép bật/tắt tính năng trực tiếp ở backend (đăng ký, bình luận, đăng bài, cộng đồng, quảng cáo, nhắn tin, xác minh) và bật chế độ bảo trì trả HTTP 503 cho khách nhưng giữ quyền truy cập an toàn cho Admin/Auth/Webhooks. Chi tiết xem [docs/FEATURE_MANAGEMENT.md](FEATURE_MANAGEMENT.md).
- **Scheduled Commands (Hostinger Cron-safe, `routes/console.php`)**:
    - `app:rbac:sync`: Đồng bộ vai trò, quyền hạn và ngôn ngữ mặc định mà không xóa dữ liệu người dùng.
    - `app:sanctions:expire` (mỗi 5 phút): Tự động gỡ tạm khóa và hạn chế chức năng hết hạn.
    - `app:notifications:dispatch` (mỗi 1 phút): Phát thông báo quản trị đã lên lịch.

### 2.4 Hệ thống Footer & Quản trị Chính sách

- Models & Tables: `PolicyPage` (`policy_pages`), `FooterLink` (`footer_links`).
- Services:
    - `FooterMenuService`: Cache danh sách liên kết công khai (`syncviet.footer.links.v1`), tự động loại bỏ các trang `draft` hoặc `is_visible = false`, tự động làm mới cache khi Admin lưu.
    - `PolicyManagementService`: Quản lý 10 trang chính sách chuẩn và liên kết Footer trong `DB::transaction`, đồng bộ `AuditLogger`.
    - `PolicySanitizer`: Lọc HTML phía backend bằng `DOMDocument` allowlist, loại bỏ `<script>`, `<iframe>`, sự kiện `on*` và scheme `javascript:`/`data:`.
- Frontend Components: `FooterLinkList.vue`, `MiniFooter.vue` (dưới Right Sidebar), `FullFooter.vue` (Landing/Public Policy pages), `SimpleFooter.vue` (Auth layout), và liên kết trong Mobile Drawer + Settings layout.

### 2.5 Security Monitoring & Access Control

- `SecurityHeaders` middleware: `CSP`, `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, `HSTS` (production HTTPS).
- `EnsureAdminHasTwoFactor` (`admin.2fa`): Bắt buộc mọi tài khoản có quyền truy cập `/admin/*` kích hoạt TOTP 2FA.
- `EnsureUserIsActive`: Từ chối truy cập ngay lập tức với tài khoản `suspended`/`banned`, đồng thời tự mở khóa on-the-fly nếu chế tài đã quá `expires_at` và cập nhật `last_activity_at` theo nhịp tiết lưu.
- `BrowserSessionService`: Liệt kê phiên trình duyệt an toàn và thu hồi mọi phiên/thiết bị khác (`Auth::logoutOtherDevices` + xóa `sessions` DB).
- `AuditLogger` & `RecordAuthSecurityEvents`: Ghi `audit_logs` bất biến (ẩn trường nhạy cảm) và `login_histories` cho `Login`, `Failed`, `Logout`, `2FA`, thay đổi mật khẩu, quản trị nội dung/tài chính/hệ thống.

### 2.6 Server Portability Abstraction

- **Storage**: Sử dụng `config('syncviet.media_disk')` ánh xạ qua `config/filesystems.php` (`local`, `public`, hoặc `s3` cho Cloudflare R2).
- **Cache & Queue & Session**: Chạy `database` trên Hostinger Shared Hosting; chuyển sang `redis` trên VPS chỉ bằng `.env` (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`).
- **Config & Env**: Mọi thông số môi trường, secrets, brand name, URL và seed credentials quản lý ngoài source code qua `.env` + `config/*.php`.
