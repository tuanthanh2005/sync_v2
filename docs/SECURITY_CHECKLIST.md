# SyncViet — Security Checklist (OWASP Top 10:2025 / ASVS Level 2)

Checklist này phải được kiểm tra trước khi đánh dấu **COMPLETED** cho bất kỳ module hoặc Phase nào.

## A. Phase 0 — Baseline Security Gate

- [x] `HASH_DRIVER=argon2id` được cấu hình và kiểm chứng qua test tự động.
- [x] Laravel Fortify bật đăng ký, xác minh email, đặt lại mật khẩu, xác nhận mật khẩu và TOTP 2FA.
- [x] Tài khoản `admin` bị chặn truy cập khu vực quản trị nếu chưa bật 2FA (`EnsureAdminHasTwoFactor`).
- [x] Rate limit áp dụng cho `login`, `register`, `two-factor` và `verification`.
- [x] Hỗ trợ xem phiên đăng nhập và đăng xuất khỏi tất cả thiết bị khác (`BrowserSessionService`).
- [x] Cookie session cấu hình `http_only=true`, `same_site=lax`, `secure` tự động bật với `https://`.
- [x] Mọi model nhạy cảm loại trừ `role`, `status`, số dư khỏi `#[Fillable]`.
- [x] Policy & Gate kiểm tra quyền ở backend (`AccountPolicy`, `PolicyPagePolicy`, `EnsureUserHasRole`, `EnsureUserIsActive`).
- [x] Nội dung trang chính sách được sanitize ở backend bằng `PolicySanitizer` trước khi lưu.
- [x] URL Footer link từ Admin chặn `javascript:`, `data:`, protocol-relative `//`.
- [x] HTTP Security Headers (`CSP`, `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, `HSTS`) được áp dụng và kiểm thử.
- [x] `audit_logs` và `login_histories` hoạt động và redact hoàn toàn password/token/secret.
- [x] Không có mật khẩu Admin mặc định cố định trong `DatabaseSeeder.php` hoặc code.
- [x] `composer audit` và `npm audit` = `0` lỗ hổng bảo mật.

## B. Gate cho các Phase tiếp theo

### Phase 1 — Social Core

- [ ] Chính sách Post/Comment/Profile chặn IDOR (user A không sửa/xóa bài user B, tôn trọng `user_blocks`).
- [ ] Upload ảnh kiểm tra MIME thật, kích thước, chặn PHP/executable/SVG, re-encode và xóa EXIF, tên file ngẫu nhiên.
- [ ] Link preview chống SSRF (cấm private IP, loopback, link-local metadata).
- [ ] Escape/sanitize triệt để bài viết và bình luận, chống Stored/Reflected/DOM XSS.
- [ ] Kiểm thử tự động cho toàn bộ ca IDOR, XSS và upload độc hại.

### Phase 2 — Community & Safety

- [ ] Kiểm tra quyền Moderator theo cộng đồng tại backend.
- [ ] Tích hợp Cloudflare Turnstile và rate limit chống spam.
- [ ] Ghi `audit_logs` cho mọi thao tác kiểm duyệt, khóa tài khoản, ẩn bài.

### Phase 3 — Advertising & Monetization

- [ ] Mọi tính toán VND dùng integer, backend-only.
- [ ] `DB::transaction` + `lockForUpdate()` cho mọi giao dịch số dư và ngân sách.
- [ ] Xác minh chữ ký webhook + `idempotency_key` + unique constraint chống replay & double spending.
- [ ] Kiểm thử concurrent requests không thể vượt số dư hoặc ngân sách chiến dịch.

### Phase 4 — Production Release Gate

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, HTTPS + Cloudflare Full Strict.
- [ ] Kiểm tra không lộ `.env`, `.git`, `storage/`, `vendor/`.
- [ ] Backup mã hóa ngoài hosting + kiểm thử restore thành công.
