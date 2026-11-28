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
- **15 Ngôn ngữ & Cờ SVG nội bộ (Single Source of Truth `config/localization.php`)**: Hỗ trợ `en` (mặc định/fallback), `vi`, `es`, `pt-BR`, `id`, `hi`, `th`, `fil`, `fr`, `de`, `ja`, `ko`, `zh-CN`, `ar` (RTL), `tr`. Lá cờ sử dụng `FlagIcon.vue` (inline SVG nội bộ, không phụ thuộc CDN, không dùng cờ làm tín hiệu ép ngôn ngữ theo IP). Mọi UI text, modal, tooltip, `placeholder`, `aria-label` và `sr-only` bắt buộc dùng `vue-i18n` + CSS logical properties (`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`) và đạt `php artisan localization:audit --strict` = 0 lỗi. Xem thêm `docs/LOCALIZATION_ARCHITECTURE.md`, `docs/INTERNATIONALIZATION_GUIDELINES.md` và `docs/TRANSLATION_GLOSSARY.md`.
- **Language Switcher tìm kiếm**: `LocaleSwitcher.vue` hiển thị cờ SVG + tên bản địa + ô tìm kiếm nhanh, đồng bộ reactive với `Laravel Localization` (`SetLocale`, `LocaleController`, `lang/*.json`), `vue-i18n` (`resources/js/i18n/`) và `document.documentElement.dir` (`rtl`/`ltr`). Tên thương hiệu lấy từ `page.props.name` (`config('app.name')`).

## 4. Bố cục Người dùng, Administration Center & Hệ thống Footer

### Bố cục Người dùng (`ConnectHubLayout.vue`)

- **Top Header (`56px` Mobile / `64px` Desktop + Safe Area)**: Sticky top kèm `env(safe-area-inset-top)`, thu gọn tinh tế khi cuộn xuống trên Mobile (`useCompactScrollHeader`), nút mở Drawer mobile (`44px`), Logo thương hiệu động (`AppLogo.vue` ẩn tagline trên `< sm`), nút tìm kiếm nhanh trên Mobile, Thông báo, Tin nhắn, `LocaleSwitcher`, `ThemeToggle`, User Dropdown hoặc Đăng nhập/Đăng ký.
- **Desktop (`lg+`, `max-w-[1360px]`)**: Left Sidebar tinh gọn (`248px`, nút `Tạo bài viết` dùng chung `PostComposer` + tối đa 8 mục mạng xã hội gồm Bảng tin, Khám phá, Cộng đồng, Hồ sơ, Không gian cá nhân | phân cách nhẹ | SyncViet Premium, Mời bạn & Nhận thưởng, `Xem thêm` qua `SidebarMoreMenu.vue`) + Main Content (`max-w-[760px]` hoặc `max-w-[1060px]` ở chế độ Service/Advertising Center kèm `AdvertisingCenterNav.vue`) + Right Sidebar (`xl+`, `320px`, chỉ hiển thị dữ liệu thực gồm Hashtag thịnh hành, Cộng đồng nổi bật, Tài khoản đáng chú ý, Banner hoạt động và `MiniFooter`, tự ẩn card rỗng và không lặp lại card bán quảng cáo).
- **Tablet (`md` – `< lg`, 768px–1023px)**: Thu gọn Left Sidebar thành **Icon Navigation Rail (`72px`)** kèm `Tooltip` đầy đủ cho mọi mục điều hướng và popover `Xem thêm`, không nhân đôi `MobileBottomNav`.
- **Mobile (`< md`, < 768px)**: 1 cột tận dụng chiều rộng (`px-2.5 sm:px-4`) + Left Drawer (`85vw`, `100dvh`, nhóm thu gọn Advertising Center / Cài đặt & Quyền riêng tư / Quản trị) + `MobileBottomNav` (`52px` + `env(safe-area-inset-bottom)`, 5 mục một tay gồm Bảng tin, Khám phá, Tạo bài viết, Cộng đồng, Hồ sơ; tự ẩn khi bàn phím ảo mở nhờ Visual Viewport API).
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

- **Monetization UI & Sidebar (`docs/MONETIZATION.md`)**: `PremiumBadge.vue` (`#1D9BF0`, 15px feed/comments, 20px profile, 48px hero, accessible identity-distinction tooltip), auto-collapsing `BannerSlot.vue` with reserved aspect-ratio space (zero CLS when active), Sponsored label & 3-dot hide/report menu, controlled `AdSenseSlot.vue`, manual Yes/No `PaymentConfirmModal.vue`, `PendingLimitModal.vue`, `BoostPostModal.vue`, and grouped Website Sidebar (`Discover`, `Services`, `Account`, `Administration`) in `ConnectHubLayout.vue` plus `Monetization` module in `AdminLayout.vue`.

## 6. Global Layout Stability & Interaction Isolation Guidelines

Mọi tương tác UI trên SyncViet bắt buộc tuân thủ nguyên tắc **Component Isolation** và **Zero Unintended Horizontal Layout Shift**:

1. **Global Scrollbar Reservation (`resources/css/app.css`)**:
    - Đặt `html { scrollbar-gutter: stable; }` trên root scroll container duy nhất (`<html>`).
    - Tuyệt đối **không** đặt `overflow-x: hidden` hoặc `overflow-x: clip` lên `html`/`body` (gây chặn lan truyền `body { overflow: hidden }` lên viewport khi Modal khóa cuộn và làm vỡ sticky Header/Sidebar).
    - Không hardcode `padding-right: 15px/17px` và không dùng `w-screen` / `100vw` cho container thông thường hoặc overlay `fixed inset-0`.
2. **Single Scroll-Lock & No Double Compensation (`AppProviders.vue`)**:
    - Bọc toàn bộ Inertia layouts trong persistent `AppProviders.vue` với Reka UI `<ConfigProvider :scroll-body="false" :dir="direction">`.
    - Modal (`Dialog`, `Sheet`) vẫn giữ đầy đủ `overflow: hidden` scroll-lock, Focus Trap, Escape & khôi phục vị trí cuộn khi đóng, nhưng **không** tự tiêm `padding-right` trùng lặp lên `<body>` vì `scrollbar-gutter: stable` đã giữ chỗ ổn định.
3. **Non-Modal Floating Controls (`DropdownMenu`, `Select`, `Tooltip`)**:
    - `DropdownMenu.vue` mặc định `modal: false`; `SelectContent.vue` mặc định `bodyLock: false`; mọi floating overlay dùng Portal + `collisionPadding: 8` + `overscroll-contain` + animation cục bộ `150ms` (`opacity`/`transform`), không bao giờ khóa cuộn trang hay làm dịch chuyển Header/Sidebar/Main Content.
    - Danh sách dài (ví dụ `LocaleSwitcher` 15 ngôn ngữ) cuộn hoàn toàn nội bộ (`max-h-64 overflow-y-auto overscroll-contain`).
4. **Persistent Inertia Shell (`app.ts` & `ConnectHubLayout.vue`)**:
    - `AppProviders` và `ConnectHubLayout` giữ nguyên instance xuyên suốt các lần chuyển trang Inertia (kể cả giữa News Feed 3 cột và chế độ Service 2 cột rộng `max-w-[1060px]` cho `/premium`, `/services`, `/advertising/*`, `/wallet`, `/payments`) — không unmount/remount Header và Sidebar.
5. **Scoped Transitions & Space Reservation (CLS ≈ 0)**:
    - Không dùng `transition-all`; chỉ animate thuộc tính cụ thể (`opacity`, `transform`, `colors`, `width` có chủ đích như thu gọn Admin Sidebar), tôn trọng `prefers-reduced-motion`.
    - Mọi flex/grid column dùng `min-w-0`, số tiền/thống kê dùng `tabular-nums`, card đang chọn dùng `border` cố định độ dày + `ring` (không đổi box dimensions), và banner đang hoạt động đặt trước `aspect-ratio` theo `placement.aspect_ratio`.

## 7. Global Modern Auto-Hide Scrollbar System

Toàn bộ scroll container của SyncViet tuân thủ một hệ thống scrollbar tập trung theo phong cách macOS tối giản (không hardcode màu scrollbar riêng lẻ từng trang):

- **Design Tokens (`resources/css/app.css`)**:
    - `--scrollbar-size: 6px` (độ rộng rãnh cố định; thumb dùng `border: 1px solid transparent; background-clip: content-box` tạo độ dày thị giác **`4px`** ở trạng thái bình thường và mở rộng lên **`6px`** khi hover mà **không đổi 0.0px kích thước layout**).
    - `--scrollbar-radius: 9999px`, `--scrollbar-track: transparent`, tắt hoàn toàn `::-webkit-scrollbar-button` (không có nút mũi tên).
    - **Light Mode**: `--scrollbar-thumb: rgba(100, 116, 139, 0.35)`, `--scrollbar-thumb-hover: rgba(100, 116, 139, 0.65)`.
    - **Dark Mode**: `--scrollbar-thumb: rgba(148, 163, 184, 0.30)`, `--scrollbar-thumb-hover: rgba(148, 163, 184, 0.60)`.
- **Cơ chế tự ẩn sau 3 giây & Cô lập từng vùng cuộn**:
    - Dùng selector trạng thái trực tiếp trên chính container (`[data-scrolling='true']::-webkit-scrollbar-thumb`, `[data-scrollbar-hover='true']`, `[data-scrollbar-dragging='true']`, `[data-scrollbar-mode='always']`) thay cho `@property` có `inherits: true`, tránh invalidation/RecalcStyle lan xuống cây DOM con trong khi không đổi `scrollbar-width` giữa `none` ↔ `thin`.
    - `initializeAutoHideScrollbars(3000)` ([useAutoHideScrollbar.ts](file:///d:/du_an_multi_IT/resources/js/composables/useAutoHideScrollbar.ts)) gắn **1 passive capturing listener duy nhất** kết hợp `WeakMap` + cache `MediaQueryList`/`getElementStyleMeta` + throttle `pointermove` (không `MutationObserver`, không `setInterval`, không `getComputedStyle` lặp lại khi di chuột), hỗ trợ chuột, touchpad, bàn phím, hover vùng mép scrollbar (hỗ trợ RTL) và giữ hiển thị khi đang kéo.
    - Mỗi scroll container độc lập hoàn toàn: cuộn News Feed không làm scrollbar của Modal, Sidebar hay Dropdown xuất hiện theo.
    - Component tái sử dụng `AutoHideScrollArea.vue` hỗ trợ cấu hình `hideDelay` (mặc định `3000ms`), `size`, `orientation`, `autoHide`, `alwaysVisible`, `nativeMode`, `overscrollContain`.
- **Payment / Checkout / Confirmation Modals (`DialogContent structured`)**:
    - Mọi modal thanh toán & xác nhận (`BoostPostModal`, `PaymentConfirmModal`, `ConfirmActionDialog`, `ReportDialog`, `Ads` modals) dùng cấu trúc **Header cố định + Body `<AutoHideScrollArea>` cuộn độc lập + Footer cố định** để scrollbar không chạm nút `X`, không tràn bo góc và các nút xác nhận/tổng tiền luôn sẵn sàng.
- **Cross-Browser, Mobile & Accessibility**:
    - Desktop fine-pointer (`@media (hover: hover) and (pointer: fine)`): Chrome/Edge/Safari/Opera dùng `::-webkit-scrollbar`, Firefox dùng `@supports not selector(::-webkit-scrollbar)` (`scrollbar-width: thin`).
    - Mobile iOS/Android: giữ nguyên native OS overlay scrollbar, không chặn touch/momentum scrolling hay pull-to-refresh.
    - Tôn trọng `prefers-reduced-motion`, `forced-colors: active` và `prefers-contrast: more`.
- **Official AI Disclosure Badge (`AiBadge.vue`)**:
    - Thiết kế bằng icon `Bot` (`@lucide/vue`) + viên thuốc Indigo mềm (`border-indigo-500/30 bg-indigo-500/10 text-indigo-700 dark:text-indigo-300`), tách biệt hoàn toàn về hình dạng, màu sắc và ngữ nghĩa so với `PremiumBadge.vue` (`#1D9BF0`) và tick xác minh danh tính người thật.
    - Hiển thị nhất quán trên `PostCard.vue`, bình luận/phản hồi tại `posts/Show.vue` và banner công khai tài khoản AI tại `users/Show.vue`.
- **Member Growth Recognition Badges (`MemberBadges.vue`)**:
    - Tách biệt hoàn toàn về màu sắc và ý nghĩa với `#1D9BF0` `PremiumBadge.vue` (quyền lợi Premium) và `AiBadge.vue` (công khai AI Agent):
        - `EarlyMember` (Amber Award `#1–#1000`), `FoundingMember` (Emerald Crown), `ReferralAmbassador` (Indigo Gift), `CommunityBuilder` (Sky Users), `OfficialAccount` (Indigo Shield Check).
    - Nạp theo lô (`activeBadgesForUsers`, 0 N+1) trên `PostCard.vue` và `users/Show.vue`; trang thành viên `/referral` & `/onboarding` tuân thủ hoàn toàn chuẩn 90% neutral / 10% Indigo, Dark Mode, RTL và không có `alert()`/`confirm()`.

## 13. Social Core, Image Upload, Post Composer, Reaction, Search & Footer CMS V1.0

- **Shared Upload Suite (`resources/js/components/upload/*`)**: Thống nhất 100% tải ảnh trực tiếp từ thiết bị (`ImageUploader.vue`, `ImageUploadPreview.vue`, `AvatarUploader.vue` cắt 1:1 Canvas 2D, `CoverImageUploader.vue` cắt 3:1 Canvas 2D); loại bỏ hoàn toàn native `<input type="file">` thô và mọi ô nhập URL ảnh ngoài; hiển thị ghi chú quyền sở hữu hình ảnh và xử lý thân thiện HTTP 413.
- **Post Composer Modal (`PostComposer.vue`)**: Trình kích hoạt gọn trên Feed mở Modal soạn bài hiện đại (Desktop ~640px, Mobile `100dvh` safe-area), hỗ trợ tiêu đề tùy chọn, emoji, hashtag, multi-image + alt-text/reorder, bình chọn, chia sẻ liên kết, nút **Video · Sắp ra mắt** bị vô hiệu hóa có tooltip/toast rõ ràng và bảo vệ bản nháp chưa lưu qua `ConfirmActionDialog`.
- **Reaction Picker & Action Bar (`ReactionPicker.vue`, `PostCard.vue`)**: Bỏ hoàn toàn mũi tên rời cạnh nút cảm xúc; sử dụng popover teleported tự căn biên viewport (Desktop hover 350ms + hover bridge + click nhanh bật/tắt; Mobile long-press 450ms tự hủy khi cuộn; đầy đủ bàn phím/ARIA) với biểu tượng mang bản sắc SyncViet; thanh tương tác thống nhất `Reaction · Comment · Share · Views (Eye + compact number + tooltip chính xác) ... Bookmark`.
- **Feed Navigation & Search/Explore (`Welcome.vue`, `Search.vue`)**: Tab chủ đề gắn biểu tượng `@lucide/vue` có thanh cuộn ngang mobile tự căn giữa tab đang chọn; `/search` hỗ trợ đủ 6 trạng thái (Explore mặc định, gợi ý realtime debounce 300ms + `AbortController`, skeleton, kết quả phân loại có phân trang, không có kết quả kèm gợi ý tách biệt, lỗi rõ ràng).
- **Compact Social Footer & Admin CMS (`MiniFooter.vue`, `FullFooter.vue`, `admin/Policies.vue`)**: Footer gọn dạng liên kết cách bởi dấu `·` + dropdown `More ▾` (`modal: false`) + bản quyền + email hỗ trợ thật (chặn triệt để tên miền mẫu `example.com`); trang quản trị CMS đầy đủ tạo/sửa trang, dịch 15 ngôn ngữ, tải ảnh nội bộ, SEO/Open Graph, lịch sử phiên bản & khôi phục, xem trước footer trực tiếp.

## 14. Global Modal Performance, Smooth Scrolling & Interaction Optimization V3.0

1. **Zero Fullscreen Blur & Lightweight Composites**:
    - `DialogOverlay.vue`, `DialogScrollContent.vue`, `SheetOverlay.vue` dùng nền tối đơn giản `bg-slate-950/50` (tuyệt đối không dùng `backdrop-blur-*` hay `filter: blur()` lên giao diện nền); header/bottom-nav sticky dùng nền semantic đặc `bg-card/98` để cuộn và mở modal luôn đạt GPU fast path.
    - Bỏ hoàn toàn `transition-all` và `shadow-xl` nặng trên modal/button; chỉ animate `opacity` và `transform` ở thời lượng gọn (`150ms` mở / `100ms` đóng Dialog; `150ms` mở / `120ms` đóng Sheet; `100ms` mở / `75ms` đóng Dropdown/Select/Tooltip) kèm `motion-reduce:animate-none`.
2. **Standardized Modal Sizing & Native Smooth Scrolling**:
    - `DialogContent.vue` hỗ trợ `size` (`sm | md | lg | xl | full`) và chế độ `structured` (`flex min-h-0 flex-col overflow-hidden p-0`) để Header/Footer `shrink-0` cố định và Body (`<AutoHideScrollArea class="flex-1">`) là vùng cuộn native duy nhất (`overscroll-contain`), không lồng nhiều vùng cuộn và giữ nguyên momentum/wheel scrolling gốc của trình duyệt/OS.
3. **Background Activity Pause (`useModalRuntimeLock.ts`)**:
    - `Dialog.vue`, `Sheet.vue`, `ImageLightbox.vue` tự động đồng bộ qua `useModalRuntimeLock()` khi mở/đóng; trong thời gian modal đang mở, `usePostViewTracker.ts` tạm dừng hẹn giờ dwell + flush batch và `Welcome.vue`/`Messages.vue` tạm bỏ qua tick polling nền, khôi phục sạch khi đóng modal.
4. **Input, Media & Card DOM Hygiene**:
    - `PostComposer.vue`: rAF-batched `scheduleTextareaHeight` bỏ forced reflow kép trên mỗi phím gõ (chỉ reset `height = 'auto'` khi thu gọn/xóa chữ hoặc chương trình chèn nội dung), debounce lưu nháp (`350ms` + flush khi đóng/beforeunload), lazy-mount `ConfirmActionDialog`.
    - `PostCard.vue`: lazy-mount `ImageLightbox`, `Dialog` báo cáo, `ConfirmActionDialog` xóa bài, `BoostPostModal`, `ReactionUsersModal` (`v-if="hasOpened..."`) để không nhân bản cây modal nhàn rỗi theo từng card trên Feed.
    - `ImageUploadPreview.vue` & `lib/imageUpload.ts`: bật `decoding="async"` và `img.decode()` bất đồng bộ trước khi vẽ Canvas Cropper, giải phóng Object URL đầy đủ khi xóa/unmount.
