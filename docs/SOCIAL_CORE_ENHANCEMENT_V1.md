# SyncViet — Social Core, Image Upload, Post Composer, Reaction, Search & Footer CMS Enhancement V1.0

## Tổng quan 6 Giai đoạn đã triển khai

### Giai đoạn 1 — Chính sách tải hình ảnh toàn cục (Global Device-Only Image Upload Policy)

- **Chỉ chọn tệp từ thiết bị**: Loại bỏ hoàn toàn `SafeImageUploadService::importImageFromUrl()` và toàn bộ trường nhập URL ảnh ngoài (`desktop_image_url`, `mobile_image_url`) trên Banner Advertising (user + admin), Branding Settings và CMS. Giữ nguyên chia sẻ liên kết web (`link_url`, `website_url`) và URL ảnh nội bộ/Cloudflare R2 CDN.
- **Giới hạn `< 40 MB` nghiêm ngặt**: `SafeImageUploadService::MAX_IMAGE_BYTES = 40_000_000` (từ chối tuyệt đối tệp `>= 40_000_000` bytes), giới hạn tổng kích thước request bài viết `< 80 MB`, cấu hình giới hạn nhỏ hơn theo ngữ cảnh (`avatar`, `cover`, `post`, `banner`, `cms`) trong Admin (không bao giờ vượt 40 MB).
- **An toàn & Tối ưu**: `ValidUploadedImage` Rule + kiểm tra magic bytes (512B đầu) + `finfo` + `getimagesize` + chống decompression bomb (`8000 px` / `25 MP`) + chặn video/SVG/PHP giả mạo + tự động xoay JPEG theo EXIF + xóa EXIF/GPS + chuyển đổi WebP và tạo thumbnail.
- **Chẩn đoán hạ tầng PHP trung thực & HTTP 413**: `SafeImageUploadService::runtimeLimits()` hiển thị cảnh báo thực tế tại `/admin/settings` và `/admin/system-health` nếu PHP INI thấp hơn 40 MB; `PostTooLargeException` trả về lỗi HTTP 413 đa ngôn ngữ thân thiện.
- **Bộ Upload dùng chung**: `resources/js/lib/imageUpload.ts`, `ImageUploader.vue`, `ImageUploadPreview.vue`, `AvatarUploader.vue` (cắt 1:1 Canvas 2D), `CoverImageUploader.vue` (cắt 3:1 Canvas 2D) — thay thế toàn bộ `<input type="file">` thô.

### Giai đoạn 2 — Chỉnh sửa hồ sơ & Modal soạn bài viết mới

- **Profile Edit Modal (`users/Show.vue`)**: Thiết kế lại gọn, đẹp với Cover 3:1 + Avatar 1:1 có crop/reposition & Remove, ghi nhận Audit Log (`profile.avatar_updated`, `profile.cover_updated`, `profile.avatar_removed`, `profile.cover_removed`).
- **Post Composer Modal (`PostComposer.vue`)**: Thay khối mở rộng trên Feed bằng Trigger card gọn mở Modal soạn bài (Desktop ~640px, Mobile `100dvh` safe-area), hỗ trợ tiêu đề tùy chọn, emoji, hashtag, multi-image + alt-text/reorder/progress, Poll, Link Sharing, nút **Video · Sắp ra mắt** (vô hiệu hóa, không có picker/upload video/FFmpeg), và bảo vệ bản nháp qua `ConfirmActionDialog`.

### Giai đoạn 3 — Bộ chọn cảm xúc mới & Đếm lượt xem chuẩn xác

- **ReactionPicker (`ReactionPicker.vue`)**: Bỏ hoàn toàn nút mũi tên rời (`▾`) cạnh nút cảm xúc; popover teleported tự căn biên viewport (Desktop hover 350ms + hover bridge + click bật/tắt nhanh; Mobile nhấn giữ 450ms tự hủy khi cuộn; đầy đủ bàn phím/ARIA) với biểu tượng mang bản sắc SyncViet.
- **Action Bar thống nhất (`PostCard.vue`)**: `Reaction · Comment · Share · Views (Eye + formatCompactNumber + tooltip chính xác) ... Bookmark`.
- **Post View Tracking (`PostViewTrackingService.php`, `usePostViewTracker.ts`)**: Migration `2026_12_01_960001_add_post_views_and_view_events_tables.php` thêm counter cache trên `posts` + bảng `post_view_events` chống trùng theo khung 6 giờ (`viewer_hash` HMAC không lưu PII). Chỉ ghi nhận khi bài viết hiển thị `>= 50%` viewport trong 2 giây ở tab đang mở, gộp batch qua `POST /posts/views/batch`, lọc bot/prefetch/AI Agent/System/Test, và tự động dọn dẹp dữ liệu thô quá 30 ngày (`app:views:prune`) mà không mất tổng lượt xem.

### Giai đoạn 4 — Biểu tượng điều hướng Feed & Tìm kiếm / Khám phá thông minh

- **Feed Navigation (`PostCategory.php`, `Welcome.vue`)**: Bổ sung biểu tượng `@lucide/vue` cho toàn bộ tab danh mục, cuộn ngang mượt trên mobile và tự động căn giữa tab đang chọn.
- **Intelligent Search & Explore (`SearchService.php`, `SearchController.php`, `Search.vue`)**: 6 trạng thái hoàn chỉnh (Explore mặc định bằng dữ liệu thật, gợi ý realtime debounce 300ms qua `/search/suggestions` với `AbortController`, skeleton, kết quả phân loại có bộ đếm & phân trang, trạng thái không có kết quả tách biệt phần gợi ý khám phá, trạng thái lỗi rõ ràng), hỗ trợ Unicode & `d`/`đ` tiếng Việt, tôn trọng quyền riêng tư/chặn người dùng, và gắn `noindex, follow` cho trang tìm kiếm nội bộ.

### Giai đoạn 5 — Footer hiện đại & Hệ thống quản trị CMS trang/chân trang

- **Database & Models**: Migration `2026_12_01_960002_upgrade_policy_pages_and_footer_for_cms.php` mở rộng `policy_pages` (`page_type`, `seo_title`, `meta_description`, `canonical_url`, `og_image_*`, `index_eligible`, `effective_at`, `archived_at`), `footer_links` (`placement: main|more`, `translations`), và tạo mới `policy_page_translations` + `policy_page_revisions`.
- **Footer gọn (`MiniFooter.vue`, `FullFooter.vue`, `SimpleFooter.vue`)**: Liên kết gọn phân cách `·` + dropdown `More ▾` (`modal: false`) + bản quyền + email hỗ trợ thật (chặn triệt để `hello@example.com` và tên miền mẫu), loại bỏ hoàn toàn văn bản giữ chỗ.
- **Admin CMS (`admin/Policies.vue`, `PolicyManagementService.php`)**: Tạo/sửa trang, xuất bản/lưu nháp/lưu trữ, dịch 15 ngôn ngữ với route `/{locale}/policies/{slug}` + `hreflang` tương hỗ (chỉ khi có bản dịch xuất bản thật, nếu chưa có tự động 301 về canonical), tải ảnh nội bộ từ thiết bị (`PolicySanitizer` chỉ cho phép ảnh nội bộ SyncViet/CDN), cài đặt SEO/OG Image, lịch sử phiên bản & khôi phục 1 bấm, và xem trước chân trang trực tiếp.

### Giai đoạn 6 — Quốc tế hóa 15 ngôn ngữ, Kiểm thử & Xác minh

- Hoàn thiện 100% **152 khóa frontend + 32 khóa backend** cho toàn bộ **15 ngôn ngữ** (`en`, `vi`, `es`, `pt-BR`, `id`, `hi`, `th`, `fil`, `fr`, `de`, `ja`, `ko`, `zh-CN`, `ar` RTL, `tr`) đạt `php artisan localization:audit --strict` 0 lỗi.
- Bổ sung bộ test tích hợp `tests/Feature/Social/SocialCoreEnhancementTest.php` bao phủ toàn bộ 5 giai đoạn.
