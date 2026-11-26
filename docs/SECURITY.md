# SyncViet — Security Architecture (OWASP Top 10:2025 & ASVS Level 2)

SyncViet áp dụng **Security by Design** và **Defense in Depth** ngay từ Phase 0, tuân thủ **OWASP Top 10:2025** và hướng tới **OWASP ASVS Level 2**.

---

## 1. Authentication Security

- **Framework**: Laravel Fortify (`config/fortify.php`).
- **Password Hashing**: `Argon2id` mặc định (`HASH_DRIVER=argon2id` trong `config/hashing.php`, `rehash_on_login = true`).
- **Email Verification**: `MustVerifyEmail` bắt buộc cho trang cá nhân, bảo mật, cài đặt và quản trị.
- **Two-Factor Authentication (TOTP 2FA)**: Hỗ trợ QR code + recovery codes + xác nhận mật khẩu (`RequirePassword`).
- **Mandatory Admin 2FA**: Middleware `EnsureAdminHasTwoFactor` (`admin.2fa`) bắt buộc mọi tài khoản có quyền quản trị kích hoạt TOTP 2FA trước khi truy cập `/admin/*`.
- **Rate Limiting**: Giới hạn tốc độ đăng nhập (`login`: 5/phút theo `email|IP`), 2FA (`two-factor`: 5/phút), đăng ký (`register`: 5/phút theo IP) và gửi email xác minh.
- **Session Management & Revocation**: Session regeneration mặc định của Fortify, hỗ trợ **Đăng xuất tất cả thiết bị khác** (`BrowserSessionService::logoutOtherSessions`), cookie `HttpOnly=true`, `SameSite=Lax`, `Secure=true` khi chạy HTTPS; khi Admin tạm khóa (`suspend`) hoặc cấm (`ban`), hệ thống lập tức xóa toàn bộ bản ghi `sessions` và xoay vòng `remember_token` của người dùng đó.

## 2. Authorization Security (RBAC & Governance)

- **Granular RBAC**: 8 vai trò có thứ bậc (`super_admin`, `admin`, `moderator`, `content_manager`, `ad_manager`, `finance_manager`, `support_agent`, `analyst`) và 120+ quyền hạn `module.action` kiểm tra tại backend qua `RbacService` và middleware `EnsureUserHasPermission` (`permission:*`). Chi tiết xem [docs/PERMISSION_MATRIX.md](PERMISSION_MATRIX.md).
- **Policies & Gates**: `AccountPolicy`, `PolicyPagePolicy`, `PostPolicy`, `CommentPolicy`, `CommunityPolicy`, `AdCampaignPolicy`, `Gate::define('access-admin')`, `Gate::define('access-moderation')`.
- **Deny by Default & Least Privilege**: Mọi endpoint không công khai đều yêu cầu middleware xác thực + phân quyền backend (`auth`, `verified`, `active`, `permission:*` / `role:...`).
- **Anti-IDOR & Anti-Privilege Escalation**:
    - Kiểm tra quyền sở hữu tài nguyên trong Policy; loại trừ `role`, `rbac_role_id`, `status` và số dư khỏi `#[Fillable]`.
    - Nhân sự không thể tự thay đổi vai trò của chính mình, không thể gán vai trò có thứ bậc cao hơn hoặc ngang bằng cấp quyền không được phép, và không thể hạ cấp hoặc cấm tài khoản `super_admin` cuối cùng còn hoạt động.
    - Mọi hạn chế tính năng (`can_post`, `can_comment`, `can_message`, `can_advertise`) đều được cưỡng chế trực tiếp tại Service backend, không dựa vào ẩn/hiện ở frontend.

## 3. Input & Output Security

- **Validation**: Form Request cho mọi mutation (`ProfileUpdateRequest`, `PolicyPageUpdateRequest`, `FooterLinkStoreRequest`, `LocaleUpdateRequest`, `LogoutOtherSessionsRequest`, `SanctionUserRequest`, `UpdateUserRoleRequest`, v.v.).
- **SQL Injection Defense**: Eloquent ORM và Parameterized Query; không nối chuỗi SQL thô với đầu vào người dùng.
- **XSS, CSV Injection & CSRF Defense**: CSRF token mặc định của Laravel; Vue auto-escape; `PolicySanitizer` dùng `DOMDocument` allowlist lọc bỏ `<script>`, `<iframe>`, `on*` và scheme `javascript:`/`data:` trước khi lưu; cấm tuyệt đối `v-html` với dữ liệu chưa sanitize; các tệp xuất CSV tài chính/kiểm toán trung hòa tiền tố công thức (`=`, `+`, `-`, `@`) để chống CSV Formula Injection; Global Toast tự động lọc không hiển thị exception hoặc stack trace.
- **Mass Assignment & SSRF**: Bảo vệ `#[Fillable]` chặt chẽ; `SafeLinkPreviewService` chặn dải IP nội bộ/private/loopback/metadata (`127.0.0.0/8`, `10.0.0.0/8`, `169.254.169.254`, v.v.).

## 4. File Upload Security (Áp dụng đầy đủ từ Phase 1+)

- Chỉ cho phép định dạng ảnh trong whitelist (JPEG, PNG, WebP).
- Kiểm tra MIME Type thật (`finfo`) và kích thước/độ phân giải ảnh.
- Tạo tên file ngẫu nhiên (`Str::uuid()`), không dùng tên file gốc từ client.
- Re-encode ảnh qua thư viện xử lý ảnh trước khi lưu và loại bỏ metadata EXIF không cần thiết.
- Chặn tuyệt đối PHP, file thực thi hoặc SVG chưa sanitize.
- Lưu trữ ngoài vùng thực thi PHP (`storage/app/private` hoặc S3/R2), dùng Signed URL cho tài nguyên riêng tư.

## 5. Payment & Advertising Security

- Mọi phép tính tài chính thực hiện hoàn toàn ở backend bằng **integer** theo đơn vị nhỏ nhất (VND), tuyệt đối không dùng `float`.
- Bao bọc mọi cập nhật số dư trong `DB::transaction` kết hợp pessimistic locking (`lockForUpdate()`).
- Chống Race Condition, Double Spending, Replay Attack và Duplicate Transaction bằng `idempotency_key` + unique constraint trên `provider_reference`.
- Xác minh chữ ký HMAC/RSA webhook cổng thanh toán (`payment_webhook_logs`); tuyệt đối không cho phép số dư âm.
- **Tách bạch nguồn tiền & Xác nhận mật khẩu**: Tách riêng `balance` (tiền nạp thực) và `promo_balance` (tín dụng khuyến mãi — không hoàn tiền mặt). Mọi thao tác điều chỉnh số dư, cấp tín dụng khuyến mãi hoặc hoàn tiền của Admin đều bắt buộc xác nhận lại mật khẩu (`current_password`), lý do cụ thể và ghi `audit_logs`.
- **Sổ cái kiểm toán & Đối soát quảng cáo**: `ad_credit_entries` chỉ ghi thêm (append-only); ẩn/xóa bài viết đang quảng cáo sẽ tự động dừng chiến dịch và hoàn ngân sách dư một cách nguyên tử; phát hiện bất thường đột biến click/impression trong Admin Ads.

## 6. Server, Backup & Deployment Security

- `APP_DEBUG=false` trên production; chỉ expose `public/` (không lộ `.env`, `.git`, `storage/`, `vendor/`, log hay file backup).
- Bắt buộc HTTPS với Cloudflare **Full (strict)**.
- Middleware `SecurityHeaders` áp dụng: `Content-Security-Policy` (`frame-ancestors 'self'`, `object-src 'none'`, `worker-src 'self'`, `manifest-src 'self'`, `img-src 'self' data: blob: https:`), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy`, và `HSTS` trên HTTPS production.
- **PWA Service Worker Cache Isolation (`public/sw.js`)**: Chỉ áp dụng allowlist cache cho tài nguyên tĩnh `/build/*`, `/icons/*`, `/favicon.*`, `/manifest.webmanifest`, `/offline.html`. Tuyệt đối không cache trang HTML động, request `X-Inertia`/`XMLHttpRequest`, các phương thức ghi (`POST`/`PUT`/`PATCH`/`DELETE`), hay bất kỳ route nhạy cảm nào (`/admin`, `/messages`, `/wallet`, `/ads`, `/payments`, `/webhooks`, `/settings`, `/notifications`, auth, `/api`). Không tự xếp hàng hay thực thi lại giao dịch tài chính khi thiết bị offline.
- **Safe Maintenance Mode (`CheckMaintenanceMode`)**: Trả HTTP 503 cho công chúng khi bảo trì nhưng luôn giữ luồng xác thực, đường dẫn `/admin/*` và webhook thanh toán hoạt động an toàn để tránh tự khóa quản trị viên.
- **Hostinger-safe Snapshot Backups (`AdminSecurityService`)**: Tạo bản sao lưu JSON nén gzip lưu tại disk riêng tư (không dùng `shell_exec`/`mysqldump`), tính checksum SHA-256 kiểm tra toàn vẹn, loại bỏ cột mật khẩu/2FA khỏi bản xuất và ghi nhận đầy đủ trong `backup_records` + `audit_logs`.
- Tách riêng database credentials theo nguyên tắc Least Privilege; không cache trang cá nhân hóa trên Cloudflare; sao lưu ngoài hosting và kiểm thử khôi phục định kỳ.

## 7. Dependency Security

- Chạy `composer audit` và `npm audit` định kỳ và trước mỗi release.
- Duy trì `composer.lock` và `package-lock.json`; chỉ sử dụng package chính thức/uy tín còn duy trì.

## 8. Security Testing

- Duy trì bộ automated test trong `tests/Feature/` kiểm tra: phân quyền chéo user (anti-IDOR), chặn truy cập Admin/Policy API, chống tự nâng `role`/`status`/số dư, bắt buộc Admin 2FA, XSS sanitization, URL validation, đăng xuất mọi thiết bị, security headers, rate limiting, và từ Phase 1–3 bổ sung test upload độc hại, webhook replay và race condition chi tiêu.

## 9. Security Monitoring

- `AuditLogger` (`audit_logs`): Ghi nhận đăng nhập, bật/tắt 2FA, đăng xuất thiết bị khác, đổi mật khẩu, thay đổi chính sách/footer và mọi tác vụ quản trị/tài chính nhạy cảm (tự động redact `password`, `token`, `secret`, `code`).
- `RecordAuthSecurityEvents` (`login_histories`): Ghi nhận đăng nhập thành công, thất bại và đăng xuất kèm IP và User-Agent.
- Không bao giờ ghi mật khẩu, token hoặc secret vào application logs.

## 10. Quy chuẩn phát triển bắt buộc

- Không tắt cơ chế bảo mật để xử lý lỗi tạm thời.
- Không bypass Authorization hoặc Form Request Validation.
- Không tạo backdoor hay tài khoản Admin mặc định có mật khẩu cố định trong mã nguồn.
- Mọi module chỉ được đánh dấu hoàn thành sau khi vượt qua `docs/SECURITY_CHECKLIST.md` và test tự động.

- **Monetization Security Controls (`docs/FINANCIAL_SECURITY.md`)**: Server-only integer pricing (VND & USD cents, zero floats), manual Yes/No confirmation (`confirmed: accepted`), locked max-5 pending orders per user (`User::lockForUpdate()`), HMAC-SHA256 webhook verification with `payment_webhook_logs` audit trail, anti-IDOR payment receipts (`403`), exclusive banner row locking, SSRF-safe banner image/URL validation, controlled regex-only AdSense config (zero raw JS), password-confirmed Admin reconciliation/refunds, and platform compliance enforcement (`PlatformCapabilityService`).
- **AI Content Center Security Controls (`docs/AI_SECURITY.md`)**: Encrypted Gemini API keys (`Crypt::encryptString`, `#[Hidden]`, masked `••••••••last4`), strict `x-goog-api-key` header auth (zero query-param or plaintext leaks), honest shared Project+Model quota tracking without rate-limit bypass rotation, paid-only model opt-in lock, prompt-injection sanitization with allowlisted `{{variable}}` interpolation (zero code eval), SSRF-checked research sources, mandatory human review before publish with edit-resets-approval & DB-locked idempotency, AI-to-AI reply prevention, advisory-only moderation screening, formula-safe CSV export, granular RBAC `ai.*` + Admin 2FA, and backend-enforced Emergency Stop.
