# SyncViet — Tasks & Progress Tracker

Quy ước trạng thái:

- `TODO`: Chưa bắt đầu
- `IN PROGRESS`: Đang thực hiện
- `COMPLETED`: Đã hoàn thành và kiểm thử thực tế
- `BLOCKED`: Đang bị chặn bởi yếu tố bên ngoài hoặc cần xác nhận

---

## Phase 0 – Project Foundation

| Hạng mục                                                                                                                                       | Trạng thái | Ghi chú                                                               |
| ---------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | --------------------------------------------------------------------- |
| Kiểm tra môi trường local & cài PHP 8.4.28 portable + MySQL 8.4.3                                                                              | COMPLETED  | PHP 8.4.28 + MySQL 8.4.3 + Node v22.23.2                              |
| Khởi tạo Laravel 13 Vue Starter Kit (Inertia 3, Tailwind 4, shadcn-vue, Fortify, Wayfinder)                                                    | COMPLETED  | Tắt Inertia SSR, bỏ Passkeys ở MVP                                    |
| Chuẩn hóa thương hiệu động (`APP_NAME=SyncViet`) & không hardcode brand/URL                                                                    | COMPLETED  | Đọc từ `config('app.name')` và `page.props.name`                      |
| Thiết kế & cài đặt hệ thống Footer tái sử dụng (`MiniFooter`, `FullFooter`, `SimpleFooter`, Mobile links)                                      | COMPLETED  | Không đặt Footer lớn cuối Feed Infinite Scroll                        |
| Hỗ trợ đa ngôn ngữ `vi` / `en` qua `vue-i18n` và Laravel Localization                                                                          | COMPLETED  | `SetLocale`, `LocaleController`, `LocaleSwitcher.vue`                 |
| Trang Chính sách & Admin Policy/Footer CMS (10 trang mặc định trạng thái `draft`, XSS sanitize)                                                | COMPLETED  | `policy_pages`, `footer_links`, `PolicyPagePolicy`, `PolicySanitizer` |
| Security by Design (Argon2id, Admin Mandatory 2FA, Logout All Sessions, Security Headers, Audit Log, Login History, Register Throttle)         | COMPLETED  | Kiểm thử tự động + `composer audit` + `npm audit` = 0 lỗi             |
| Tài liệu Server Portability & Deployment (`DEPLOYMENT_HOSTINGER`, `DEPLOYMENT_VPS`, `MIGRATION_GUIDE`, `BACKUP_RESTORE`, `SECURITY_CHECKLIST`) | COMPLETED  | Hỗ trợ Hostinger Shared Hosting và di chuyển VPS không sửa code       |
| Kiểm thử tổng thể (Pint, PHPStan level 7, `vp check`, `vue-tsc`, Pest 5, `vite build`)                                                         | COMPLETED  | Tất cả check và build xanh                                            |
| Kiểm tra thông số Hostinger thực tế qua hPanel/SSH                                                                                             | BLOCKED    | Chờ thông tin hPanel/SSH ở Phase 4 (không chặn Phase 0–1 local)       |

## Phase 1 – Social Core

| Hạng mục                                                                                                                | Trạng thái | Ghi chú                                                        |
| ----------------------------------------------------------------------------------------------------------------------- | ---------- | -------------------------------------------------------------- |
| Schema & Models: `profiles`, `posts`, `post_media`, `comments`, `post_reactions`, `bookmarks`, `follows`, `user_blocks` | COMPLETED  | InnoDB `utf8mb4_unicode_ci`, anti-IDOR Policies                |
| News Feed (Infinite Scroll + Cursor Pagination, không Footer lớn cuối feed, MiniFooter dưới Right Sidebar)              | COMPLETED  | `HomeController`, `FeedService`, `ConnectHubLayout`            |
| Đăng bài (text, link preview anti-SSRF) & Upload ảnh an toàn (MIME, random name, re-encode, xóa EXIF)                   | COMPLETED  | `SafeImageUploadService`, `SafeLinkPreviewService`, `PostPoll` |
| Like/Reaction, Comment, Share URL, Bookmark, Follow/Block, User Profile & Public Blade Permalink SEO                    | COMPLETED  | `PostController`, `CommentController`, `ProfileViewController` |
| Search cơ bản (debounce, index tối ưu) & Automated Security Tests (IDOR, XSS, Upload)                                   | COMPLETED  | `SearchService`, `SocialCoreTest.php`                          |

## Phase 2 – Community & Safety

| Hạng mục                                                         | Trạng thái | Ghi chú                                                                                                           |
| ---------------------------------------------------------------- | ---------- | ----------------------------------------------------------------------------------------------------------------- |
| Community, Membership & Moderator role                           | COMPLETED  | `communities`, `community_members`, `CommunityService`, `CommunityPolicy`                                         |
| Hashtag, Trending từ dữ liệu thật & Database Notifications       | COMPLETED  | `hashtags`, `post_hashtags`, `HashtagService`, `NotificationService`                                              |
| Report, Moderation Queue, Audit Logs mở rộng & Rate Limits       | COMPLETED  | `reports`, `moderation_logs`, `ModerationService`, `ModerationController` (Turnstile cấu hình tùy chọn ở Phase 4) |
| Admin Dashboard quản lý người dùng, bài viết, cộng đồng, báo cáo | COMPLETED  | `Admin/OverviewController`, `Admin/ModerationController`, `Wave2CommunityMessagingModerationTest.php`             |

## Phase 3 – Advertising & Monetization

| Hạng mục                                                                              | Trạng thái | Ghi chú                                                                                     |
| ------------------------------------------------------------------------------------- | ---------- | ------------------------------------------------------------------------------------------- |
| Advertising Manager & Quảng bá bài viết (theo ngày / tổng ngân sách + Live Preview)   | COMPLETED  | `Ads.vue`, `AdCampaignController`, `AdCampaignService`                                      |
| Thanh toán, Webhook signature, Idempotency, Row Lock & Auditable Ledger (integer VND) | COMPLETED  | `Wallet.vue`, `WalletService`, `HmacPaymentWebhookService`, `ad_credit_entries`             |
| Xét duyệt quảng cáo, phân phối xen feed, thống kê impression/click chống gian lận     | COMPLETED  | `AdReviewController`, `AdDeliveryService`, `ad_events` unique dedup, `WalletAndAdsTest.php` |

## Phase 4 – Production

| Hạng mục                                                                             | Trạng thái | Ghi chú                  |
| ------------------------------------------------------------------------------------ | ---------- | ------------------------ |
| SEO Sitemap, Full Security Audit (`SECURITY_CHECKLIST.md`), Performance Optimization | TODO       |                          |
| External Backup & Restore Test + Deploy Hostinger + Cloudflare Full Strict           | TODO       | Chỉ deploy khi được phép |

## Phase 5 – Expansion (Partial Early Delivery)

| Hạng mục                                                         | Trạng thái | Ghi chú                                                       |
| ---------------------------------------------------------------- | ---------- | ------------------------------------------------------------- |
| Direct Messages 1-1 & Interactive Polls (Shared Hosting polling) | COMPLETED  | `conversations`, `messages`, `MessagingService`, `post_polls` |
| PWA, Mobile API (Sanctum), Realtime WebSockets, Banner Ads       | TODO       | Tách riêng cho lộ trình mở rộng                               |
