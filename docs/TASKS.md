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

## Global Localization, Toast System & Full Administration Center Upgrade

| Hạng mục                                                                                                                                                | Trạng thái | Ghi chú                                                                                                                                    |
| ------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Đa ngôn ngữ toàn cầu 15 ngôn ngữ (`en` mặc định/fallback + 14 ngôn ngữ), RTL tiếng Ả Rập, 100% key đồng bộ (658 frontend / 168 backend), 0 placeholder  | COMPLETED  | `resources/js/i18n/locales/*.json`, `lang/*.json`, `SetLocale`, `formatters.ts`                                                            |
| Lá cờ SVG nội bộ (`FlagIcon.vue`, zero CDN) & Dropdown ngôn ngữ có tìm kiếm (`LocaleSwitcher.vue`) + Quản lý ngôn ngữ & bản dịch trong Admin            | COMPLETED  | `languages`, `translation_overrides`, `translation_revisions`, `LanguageManagementController`, `admin/Languages/Index.vue`                 |
| Global Toast Notification System thống nhất (6 loại, nút `X`, phím `Escape`, progress bar tạm dừng hover/focus, dedup, Light/Dark/RTL, lọc stack trace) | COMPLETED  | `resources/js/lib/toast.ts`, `Sonner.vue`, `flashToast.ts`, `ConfirmActionDialog.vue`, `ReasonDialog.vue` (xóa hoàn toàn `window.confirm`) |
| Administration Center Layout riêng biệt (`AdminLayout.vue`, 7 phân hệ, sidebar thu gọn responsive, Chart.js Analytics & System Health)                  | COMPLETED  | `AdminLayout.vue`, `AdminChart.vue`, `Overview.vue`, `Analytics.vue`, `SystemHealth.vue`                                                   |
| RBAC 8 vai trò có thứ bậc / 120+ quyền hạn, chống leo thang quyền, bảo vệ `super_admin` cuối cùng & 2FA Admin bắt buộc                                  | COMPLETED  | `roles`, `permissions`, `role_permission`, `RbacService`, `EnsureUserHasPermission`, `RoleManagementController`, `Roles/Index.vue`         |
| Quản trị Người dùng, Chế tài có thời hạn tự hết hạn, Hạn chế năng lực ở backend, Thu hồi phiên tức thì & Xét duyệt tích xanh                            | COMPLETED  | `user_sanctions`, `verification_requests`, `AdminUserService`, `UserManagementController`, `Users/{Index,Show,Verifications}.vue`          |
| Quản trị Nội dung (Posts + lịch sử sửa + tự hủy/hoàn tiền Ads, Comments đồng bộ counter, Communities, Polls, Hashtags, Media, Blocked Keywords)         | COMPLETED  | `AdminContentService`, `ContentModerationController`, `Content/{Posts,Comments,Communities,Polls,Hashtags,Media,Keywords}.vue`             |
| Trung tâm Báo cáo, Hàng đợi kiểm duyệt, Đơn kháng nghị (Appeals) & Giám sát Quảng cáo chống bất thường click                                            | COMPLETED  | `appeals`, `ModerationController`, `AppealController`, `AdReviewController`, `Moderation.vue`, `Appeals/Index.vue`, `Ads.vue`              |
| Quản trị Tài chính (Tách số dư nạp & khuyến mãi, xác nhận mật khẩu khi điều chỉnh/hoàn tiền, xuất CSV chống formula injection, nhật ký webhook)         | COMPLETED  | `AdminFinanceService`, `FinanceController`, `Finance/Index.vue`, `payment_webhook_logs`                                                    |
| Cài đặt Hệ thống, Feature Flags thực thi tại backend, Safe Maintenance Mode, Trung tâm Thông báo lên lịch, Audit Security & Hostinger Snapshot Backups  | COMPLETED  | `SystemSettingsController`, `NotificationCenterController`, `SecurityAuditController`, `CheckMaintenanceMode`, `AdminSecurityService`      |
| Tài liệu kỹ thuật đầy đủ (5 tài liệu mới + cập nhật 8 tài liệu cốt lõi) & Bộ kiểm thử Pest bảo mật/nghiệp vụ toàn diện                                  | COMPLETED  | `docs/{ADMINISTRATION,PERMISSION_MATRIX,MODERATION_GUIDE,LOCALIZATION,FEATURE_MANAGEMENT}.md`, `tests/Feature/Admin/*`                     |

## Premium Mobile UI/UX, Performance & PWA Readiness Upgrade

| Hạng mục                                                                                                                                      | Trạng thái | Ghi chú                                                                                                                          |
| --------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Viewport (`viewport-fit=cover`, giữ pinch-zoom), chống tự zoom iOS (`16px` input), `touch-action: manipulation`, Safe Area & Visual Viewport  | COMPLETED  | `app.blade.php`, `app.css`, `useAppearance.ts`, `useMobileViewport.ts`                                                           |
| Mobile Header thu gọn theo cuộn + tìm kiếm nhanh, Bottom Navigation 5 nút một tay (kèm nút Tạo bài & ẩn khi mở bàn phím), Offline Banner      | COMPLETED  | `ConnectHubLayout.vue`, `MobileBottomNav.vue`, `NetworkStatusBanner.vue`, `AppLogo.vue`, `PublicLayout`, `AuthSimpleLayout`      |
| Adaptive Mobile Bottom-Sheet Dialogs & Sheets (`100dvh`, nút `X` 40–44px, cuộn nội bộ, đệm Safe Area), thống nhất Mobile Toast (`top-center`) | COMPLETED  | `DialogContent.vue`, `SheetContent.vue`, `Sonner.vue`                                                                            |
| News Feed Mobile (tab cuộn ngang, Infinite Scroll + fallback, Optimistic UI, không nhảy vị trí đọc), Full-Screen Composer & Image Lightbox    | COMPLETED  | `Welcome.vue`, `PostCard.vue`, `PostComposer.vue`, `ImageLightbox.vue`, `posts/Show.vue`                                         |
| Mobile Wallet (xác nhận nạp tiền + sao chép mã GD), Ads, Master-Detail Messages, Search debounce & Admin Mobile Card Lists                    | COMPLETED  | `Wallet.vue`, `Ads.vue`, `Messages.vue`, `Search.vue`, `AdminLayout`, `admin/{Users,Finance,Content/Posts,Ads}`                  |
| Tách động 14 gói ngôn ngữ (dynamic import giảm mạnh `app.js`) & PWA an toàn (`manifest`, icons 192/512/maskable, `sw.js`, `offline.html`)     | COMPLETED  | `i18n/index.ts`, `usePwa.ts`, `SecurityHeaders.php`, `public/{manifest.webmanifest,sw.js,offline.html,icons/*}`                  |
| Bộ test Pest Mobile/PWA/CSP/15-locale parity & 5 tài liệu Mobile/PWA/Performance/Testing                                                      | COMPLETED  | `MobileAndPwaReadinessTest.php`, `docs/{MOBILE_UI_UX,MOBILE_COMPATIBILITY,PWA_GUIDE,PERFORMANCE_OPTIMIZATION,MOBILE_TESTING}.md` |

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

| Complete Website Monetization System (Premium, Banner Ads, Boost Post, Advertising Balance, Payments, AdSense Readiness, Store Compliance, Admin Monetization Center, 15-Lang i18n, Tests & Docs) | COMPLETED | Đã triển khai toàn diện, kiểm thử tự động & đồng bộ 15 ngôn ngữ |
| Advanced International SEO & Automatic Organic Growth Engine V2.0 (Zero-Cloaking SSR, URL Registry, Sharded XML Sitemaps, Hreflang 15 Langs, GSC API, IndexNow, Audit & Funnel) | COMPLETED | 109/109 Pest tests, PHPStan L7 = 0, `vue-tsc` = 0, `vp check` = 0 |
| AI Content Center, Gemini API Management & Multilingual Content Automation V1.0 (Encrypted Multi-Key, Shared Project+Model Quota, 7 Official AI Agents, `AiBadge`, Versioned Prompts, Quality Gate, Human Review, Anti-Flood Scheduler, 15-Lang SEO V2.0 Translation, Comment/Moderation Assist, Budget Gate & Emergency Stop) | COMPLETED | 17 AI tables, 5 Admin AI pages, 15-lang parity, 14 AI docs & full Pest security suite |
