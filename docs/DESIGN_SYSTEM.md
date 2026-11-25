# SyncViet — Design System

## 1. Cảm hứng & Quy chuẩn thị giác

Tham chiếu thẩm mỹ từ 4 mockup giao diện cộng đồng kết hợp SaaS, đảm bảo không dùng giao diện mặc định của Starter Kit, không sao chép Facebook, không hardcode tên thương hiệu hay URL.

## 2. Color Tokens & Quy chuẩn màu sắc (Light Mode & Dark Mode)

Định nghĩa tại `resources/css/app.css` với `@custom-variant dark (&:is(.dark *));` theo phong cách Modern, Minimalist, Clean UI (tỉ lệ **~90% trung tính / 10% Indigo thương hiệu**, đạt chuẩn tương phản WCAG 2.2 AA):

| Token                | Light Mode | Dark Mode | Mục đích                                                                   |
| -------------------- | ---------- | --------- | -------------------------------------------------------------------------- |
| `--primary`          | `#4F46E5`  | `#818CF8` | Màu chủ đạo Indigo (`primary-foreground`: `#FFFFFF` / `#0B0F17`)           |
| `--primary-hover`    | `#4338CA`  | `#A5B4FC` | Trạng thái Hover của nút/CTA chính                                         |
| `--primary-active`   | `#3730A3`  | `#6366F1` | Trạng thái Active của nút/CTA chính                                        |
| `--primary-soft`     | `#EEF2FF`  | `#25264A` | Nền menu đang chọn, badge nhẹ (`accent-foreground`: `#3730A3` / `#A5B4FC`) |
| `--background`       | `#F3F5F9`  | `#0B0F17` | Nền tổng thể (canvas)                                                      |
| `--card`             | `#FFFFFF`  | `#161B26` | Bề mặt thẻ nội dung, panel, header                                         |
| `--surface-hover`    | `#F1F5F9`  | `#1E293B` | Trạng thái Hover trên card/menu/button phụ                                 |
| `--foreground`       | `#0F172A`  | `#F1F5F9` | Chữ chính (Text Primary)                                                   |
| `--muted-foreground` | `#64748B`  | `#94A3B8` | Chữ phụ, metadata (Text Secondary)                                         |
| `--border`           | `#E2E8F0`  | `#273244` | Viền thẻ tinh tế và đường phân cách                                        |
| `--success`          | `#16A34A`  | `#4ADE80` | Trạng thái thành công / xác minh                                           |
| `--warning`          | `#D97706`  | `#FBBF24` | Trạng thái cảnh báo / bản nháp                                             |
| `--destructive`      | `#DC2626`  | `#F87171` | Cảnh báo lỗi / hành động xóa                                               |

- **Không lạm dụng gradient tím**: Đã loại bỏ `.bg-connecthub-gradient` và `.bg-connecthub-soft`; toàn bộ Hero Banner, Card quảng bá và Cover mặc định dùng bề mặt trung tính (`--card`, `--muted`), icon `--primary` và nút Indigo đồng nhất.
- **Button & Card**: Primary Button dùng `--primary` đồng nhất (`hover:bg-primary-hover`, `active:bg-primary-active`); Secondary Button dùng nền trung tính với viền `--border`; Card dùng viền tinh tế, hạn chế shadow đậm.
- **Sidebar & Quảng cáo**: Tránh hiển thị hai card quảng bá trùng lặp cùng lúc giữa Left Sidebar và Right Sidebar trên desktop (`xl+`); bài viết tài trợ hòa hợp với Feed nhưng giữ rõ nhãn `"Được tài trợ"` / `"Sponsored"` và CTA Indigo rõ ràng.
- **Bo góc (Radius)**: Card `12px` (`--radius: 0.75rem`, `rounded-xl`), Button/Control `8px` (`rounded-lg`).

## 3. Typography, Icons & Đa ngôn ngữ Toàn cầu (15 Ngôn ngữ + RTL)

- **Font chữ**: `Be Vietnam Pro` (weights 400, 500, 600, 700) cấu hình tự host qua `laravel-vite-plugin/fonts` (`bunny('Be Vietnam Pro')`), kết hợp fallback hệ thống cho chữ Ả Rập, CJK, Thái và Devanagari.
- **Icons**: `@lucide/vue` cho toàn bộ điều hướng, trạng thái và thao tác (không dùng emoji làm icon điều hướng).
- **15 Ngôn ngữ & Cờ SVG nội bộ**: Hỗ trợ `en` (mặc định/fallback), `vi`, `es`, `pt-BR`, `id`, `hi`, `th`, `fil`, `fr`, `de`, `ja`, `ko`, `zh-CN`, `ar` (RTL), `tr`. Lá cờ sử dụng `FlagIcon.vue` (inline SVG nội bộ, không phụ thuộc CDN).
- **Language Switcher tìm kiếm**: `LocaleSwitcher.vue` hiển thị cờ SVG + tên bản địa + ô tìm kiếm nhanh, đồng bộ reactive với `Laravel Localization` (`SetLocale`, `LocaleController`, `lang/*.json`), `vue-i18n` (`resources/js/i18n/`) và `document.documentElement.dir` (`rtl`/`ltr`). Tên thương hiệu lấy từ `page.props.name` (`config('app.name')`).

## 4. Bố cục Người dùng, Administration Center & Hệ thống Footer

### Bố cục Người dùng (`ConnectHubLayout.vue`)

- **Top Header (`56px` Mobile / `64px` Desktop + Safe Area)**: Sticky top kèm `env(safe-area-inset-top)`, thu gọn tinh tế khi cuộn xuống trên Mobile (`useCompactScrollHeader`), nút mở Drawer mobile (`44px`), Logo thương hiệu động (`AppLogo.vue` ẩn tagline trên `< sm`), nút tìm kiếm nhanh trên Mobile, Thông báo, Tin nhắn, `LocaleSwitcher`, `ThemeToggle`, User Dropdown hoặc Đăng nhập/Đăng ký.
- **Desktop (`xl+`, `max-w-[1360px]`)**: Left Sidebar (`240px`) + Main Content (`max-w-[760px]`) + Right Sidebar (`320px`).
- **Mobile (`< lg`, 320px–767px)**: 1 cột tận dụng chiều rộng (`px-2.5 sm:px-4`) + Left Drawer (`85vw`, `100dvh`, Safe Area) + `MobileBottomNav` (`52px` + `env(safe-area-inset-bottom)`, 5 mục một tay gồm Home, Explore, Create Post, Communities, Profile; tự ẩn khi bàn phím ảo mở nhờ Visual Viewport API).
- **Mobile Modals, Composer & Media**: `DialogContent.vue` chuyển sang **Bottom Sheet** trên `< 640px` (`max-h-[90dvh]`, cuộn nội bộ, nút `X` `40px`, đệm Safe Area); `PostComposer.vue` mở dạng toàn màn hình trên Mobile kèm lưu nháp; `ImageLightbox.vue` hỗ trợ pinch-to-zoom, vuốt chuyển ảnh và nút Back; các bảng Admin/Finance chuyển sang **Mobile Card List** trên `< md`. Chi tiết xem [docs/MOBILE_UI_UX.md](MOBILE_UI_UX.md).

### Bố cục Administration Center (`AdminLayout.vue`)

- Tách biệt hoàn toàn với News Feed người dùng, áp dụng tự động cho mọi trang `admin/*`.
- **Collapsible Sidebar**: Chia 7 phân hệ rõ ràng (`Overview`, `User Management`, `Content Management`, `Moderation`, `Advertising`, `Finance`, `System`), hiển thị huy hiệu số mục chờ duyệt thời gian thực (`adminPendingCounts`), ẩn tự động mục không có quyền RBAC, hỗ trợ thu gọn trên Desktop và Drawer trên Mobile.
- **Admin Topbar**: Breadcrumb ngữ cảnh, thanh tìm kiếm nhanh, chuông thông báo chờ xử lý, `LocaleSwitcher`, `ThemeToggle` và Account Menu (kèm nút quay lại giao diện người dùng).
- **Biểu đồ & Dữ liệu**: Component `AdminChart.vue` (`chart.js`), `AdminStatCard.vue` và `AdminPagination.vue` tuân thủ token màu Indigo/Neutral và tự thích ứng Light/Dark Mode.

### Hệ thống Footer (3 biến thể tái sử dụng, không trùng lặp code)

Dùng chung `FooterLinkList.vue` đọc từ Inertia shared prop `footer` (`FooterMenuService`, tự động hiển thị năm bản quyền `footer.year` và chỉ hiển thị liên kết đã duyệt/công khai):

1. **`MiniFooter.vue`**: Đặt **bên dưới Right Sidebar** trong `ConnectHubLayout.vue`. Không đặt Footer lớn ở cuối News Feed vì News Feed dùng Infinite Scroll. Bao gồm liên kết gọn, `LocaleSwitcher`, `ThemeToggle` và bản quyền tự động.
2. **`FullFooter.vue`**: Dùng cho **Landing Page / Trang công khai phù hợp** (`PublicLayout.vue`, trang chi tiết chính sách `/policies/{slug}`, khối Landing trên trang chủ). Chia cột theo nhóm (`Nền tảng`, `Pháp lý & Chính sách`, `Hỗ trợ`), logo động, chuyển ngôn ngữ và sáng/tối.
3. **`SimpleFooter.vue`**: Dùng cho các trang **Đăng nhập, Đăng ký và Xác thực** (`AuthSimpleLayout.vue`), hiển thị bản quyền tự động, các liên kết công khai và `LocaleSwitcher`.
4. **Truy cập trên Mobile**: Hiển thị danh sách liên kết Footer + `LocaleSwitcher` + `ThemeToggle` trong **Mobile Drawer (`ConnectHubLayout.vue`)** và trong **Cài đặt tài khoản (`layouts/settings/Layout.vue`)**.

### Chính sách & Chống liên kết chết

- Hỗ trợ đủ 10 trang tiêu chuẩn: `about-us`, `terms-of-service`, `privacy-policy`, `cookie-policy`, `community-guidelines`, `advertising-policy`, `refund-policy`, `copyright-policy`, `help-center`, `contact-us`.
- Tất cả trang chưa được Admin phê duyệt mặc định ở trạng thái **`draft`** (`is_visible = false`), trả `404` với người dùng công khai và tự động ẩn khỏi Footer.

## 5. Hệ thống Toast Toàn cục & Thành phần UI States tái sử dụng

- **Global Toast (`appToast` + `Sonner.vue` + `flashToast.ts`)**:
    - Hỗ trợ đầy đủ 6 loại: `success`, `error`, `warning`, `info`, `loading`, `action`.
    - Mọi toast đều có nút `X` đóng rõ ràng, hỗ trợ đóng bằng phím `Escape`, có thanh tiến trình tự động đếm ngược và tạm dừng khi hover/focus, chống hiển thị trùng lặp, hỗ trợ ARIA/Light/Dark/RTL và tự động loại bỏ stack trace/dữ liệu nhạy cảm.
- **Dialog xác nhận & nhập lý do**: `ConfirmActionDialog.vue` và `ReasonDialog.vue` (tuyệt đối không sử dụng `window.alert()` hoặc `window.confirm()`).
- **UI States khác**: `LoadingSkeleton.vue` (`feed`, `card`, `profile`), `EmptyState.vue`, `ErrorState.vue`, `RoleBadge.vue`, `FlagIcon.vue`, `ThemeToggle.vue`, `LocaleSwitcher.vue`.

- **Monetization UI & Sidebar (`docs/MONETIZATION.md`)**: `PremiumBadge.vue` (`#1D9BF0`, 15px feed/comments, 20px profile, accessible identity-distinction tooltip), auto-collapsing `BannerSlot.vue` with Sponsored label & 3-dot hide/report menu, controlled `AdSenseSlot.vue`, manual Yes/No `PaymentConfirmModal.vue`, `PendingLimitModal.vue`, `BoostPostModal.vue`, and grouped Website Sidebar (`Discover`, `Services`, `Account`, `Administration`) in `ConnectHubLayout.vue` plus `Monetization` module in `AdminLayout.vue`.
