# SyncViet — Project Plan

## 1. Tầm nhìn & Mục tiêu sản phẩm

SyncViet là nền tảng mạng xã hội cộng đồng song ngữ Việt/Anh cho phép:

- Người dùng chưa đăng nhập đọc nội dung và các trang chính sách đã xuất bản (phục vụ SEO và khám phá cộng đồng).
- Thành viên đăng ký, xác thực tài khoản và tham gia đăng bài, chia sẻ kiến thức, hình ảnh, thảo luận, bình luận, tương tác và tham gia các cộng đồng theo chủ đề.
- Nguồn doanh thu chính: hệ thống quảng cáo nội bộ cho phép người dùng/doanh nghiệp trả phí để quảng bá bài viết (Sponsored Post).

## 2. Ràng buộc hạ tầng & Server Portability

- **Hạ tầng khởi điểm**: Hostinger Shared Hosting (2 CPU, 3072 MB RAM, 50 GB Storage, 60 PHP Workers, 120 Max Processes, Singapore) + Cloudflare Free.
- **Tính di động (Portability)**: Không phụ thuộc tính năng độc quyền của Hostinger. Có thể di chuyển sang VPS hoặc Cloud Server (Nginx/PHP-FPM, Redis Cache/Queue/Session, S3/Cloudflare R2) hoàn toàn qua `.env` và `config/` mà không phải viết lại mã nguồn.
- **Bảo mật nền tảng**: Thiết kế theo **OWASP Top 10:2025** và hướng tới **OWASP ASVS Level 2** ngay từ Phase 0.

## 3. Lộ trình phát triển theo Phase

| Phase       | Tên giai đoạn              | Phạm vi chính                                                                                                                                                                                                                                                                                                                                                                                    | Trạng thái                      |
| ----------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------- |
| **Phase 0** | Project Foundation         | Environment PHP 8.4 + MySQL 8.4, Laravel 13 Vue Starter Kit, Modular Monolith, Design System, 3-column Layout, Footer System (Mini/Full/Simple/Mobile) + Admin Policy CMS, Vue I18n + Laravel Localization (VI/EN), Security Foundation (Argon2id, Admin Mandatory 2FA, Logout Other Sessions, Security Headers, Audit Log, Login History, Rate Limiting), Server Portability docs, Pest 5 tests | **COMPLETED**                   |
| **Phase 1** | Social Core                | News Feed (Infinite Scroll + Cursor Pagination, không Footer cuối feed), Post text/image/link (anti-SSRF), Upload & nén/re-encode ảnh + xóa EXIF, Like/Reaction, Comment, Share URL, Bookmark, Follow/Block, User Profile, Search debounce, Public Blade permalink SEO                                                                                                                           | **TODO** (Chờ xác nhận bắt đầu) |
| **Phase 2** | Community & Safety         | Community, Membership, Moderator role, Hashtags, Trending từ dữ liệu thật, Report, Moderation Queue, Audit Logs mở rộng, Rate Limiting, Turnstile, Database Notifications, Admin Dashboard                                                                                                                                                                                                       | **TODO**                        |
| **Phase 3** | Advertising & Monetization | Advertising Manager, Quảng bá bài viết (gói thời gian cố định ở MVP), Thanh toán cổng hợp lệ + Webhook signature + Idempotency + Row Lock + Ledger kiểm toán, Xét duyệt quảng cáo, Phân phối quảng cáo xen feed, Thống kê impression/click chống gian lận                                                                                                                                        | **TODO**                        |
| **Phase 4** | Production Launch          | Sitemap public SEO, Security Audit & Penetration Checklist, Performance Optimization, Full Regression Testing, External Backup & Restore test, Deploy Hostinger + Cloudflare Full (strict)                                                                                                                                                                                                       | **TODO**                        |
| **Phase 5** | Expansion                  | Messenger, Poll, PWA, Mobile API (Sanctum), Realtime, Advanced Ad Targeting & CPC/CPM                                                                                                                                                                                                                                                                                                            | **TODO**                        |
