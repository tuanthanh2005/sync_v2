# SyncViet — Mobile UI/UX & Native App Architecture

## 1. Triết lý Mobile First & Trải nghiệm như Ứng dụng Native

SyncViet tối ưu Mobile Web cho mọi kích thước từ **320px** đến **480px**, Tablet và Desktop mà không thu nhỏ nguyên bản Desktop xuống màn hình nhỏ:

- **Mobile Header nhỏ gọn (`56px` + Safe Area)**: [ConnectHubLayout.vue](../resources/js/layouts/app/ConnectHubLayout.vue) sử dụng `useCompactScrollHeader()` thu gọn header mượt khi cuộn xuống và hiện lại tức thì khi cuộn lên (tự vô hiệu khi `prefers-reduced-motion: reduce`, bàn phím mở hoặc đang mở Drawer).
- **Mobile Bottom Navigation Bar**: [MobileBottomNav.vue](../resources/js/components/common/MobileBottomNav.vue) cố định ở cạnh dưới kèm `env(safe-area-inset-bottom)` với 5 nút một tay cho thành viên (**Home**, **Explore**, **Create Post**, **Communities**, **Profile**) và 4 nút cho khách (**Home**, **Explore**, **Communities**, **Login**). Tự ẩn khi bàn phím ảo mở nhờ `useMobileViewport()`.
- **Full-Screen Mobile Post Composer**: [PostComposer.vue](../resources/js/components/social/PostComposer.vue) mở dạng màn hình soạn thảo toàn màn hình (`h-[100dvh]`) trên Mobile (từ feed hoặc từ nút `+` trên Bottom Nav), có thanh tiêu đề cố định chứa `Cancel` / `Publish`, vùng nhập cuộn độc lập, thanh công cụ cố định phía dưới, thanh tiến độ upload (`form.progress`), lưu nháp tự động vào `sessionStorage`, xác nhận trước khi hủy bản nháp và cảnh báo `beforeunload`.
- **Full-Screen Image Lightbox**: [ImageLightbox.vue](../resources/js/components/common/ImageLightbox.vue) hỗ trợ pinch-to-zoom, double-tap zoom, vuốt ngang chuyển ảnh, vuốt xuống đóng, nút `X` `44×44px`, phím `Escape`, nút **Back** trình duyệt (`history.pushState` / `popstate`) và khôi phục `body.style.overflow` sau khi đóng.
- **Adaptive Bottom Sheet / Dialog**: [DialogContent.vue](../resources/js/components/ui/dialog/DialogContent.vue) hiển thị dạng Bottom Sheet neo cạnh dưới (`rounded-t-2xl`, `max-h-[90dvh]`, `overflow-y-auto`, tay cầm kéo trực quan, nút `X` `40×40px`, đệm Safe Area) trên `< 640px` và chuyển sang Modal căn giữa truyền thống trên `sm+`.

## 2. Chống tự động Zoom iOS & Giữ nguyên Accessibility

- Viewport meta tại [app.blade.php](../resources/views/app.blade.php): `width=device-width, initial-scale=1, viewport-fit=cover` — **tuyệt đối không** dùng `user-scalable=no` hay `maximum-scale=1`.
- Quy tắc tại [app.css](../resources/css/app.css): trên `< 768px`, toàn bộ `input`, `textarea`, `select` sử dụng `font-size: 16px` để ngăn iOS Safari tự zoom khi focus mà vẫn giữ nguyên khả năng pinch-to-zoom hỗ trợ thị lực.
- `touch-action: manipulation` chỉ áp dụng cho `button`, `a`, `[role="button"]`, `[role="tab"]` nhằm loại bỏ độ trễ/double-tap zoom ngoài ý muốn.

## 3. Text Selection, Copy/Paste & Scrollbars

- Giữ nguyên quyền bôi đen, nhấn giữ, Copy, Paste, Cut trên toàn bộ bài viết, bình luận, username, URL, mã giao dịch thanh toán; chỉ đặt `select-none` trên thanh điều hướng và icon chrome.
- Cung cấp nút sao chép nhanh một chạm cho bài viết (`PostCard.vue`), mã giao dịch (`Wallet.vue`) và link chia sẻ.
- Ẩn thanh scrollbar trang trí qua `.no-scrollbar` (tương thích Safari, Chrome, Firefox) cho tab ngang và gallery ảnh mà không khóa cử chỉ cuộn.
- Dùng `overflow-x: clip` ở `html`/`body` (không phá `position: sticky`) kết hợp `break-words [overflow-wrap:anywhere]` cho tiêu đề, nội dung bài đăng, bình luận và tin nhắn.

## 4. Mobile Feed, Messages, Wallet, Ads & Admin Center

- **News Feed ([Welcome.vue](../resources/js/pages/Welcome.vue))**: Tab danh mục cuộn ngang `.no-scrollbar`, IntersectionObserver Infinite Scroll có giới hạn DOM (`MAX_RENDERED_POSTS = 120`) + nút `Load More` dự phòng, Optimistic UI kèm rollback cho Reaction/Bookmark, và thanh thông báo _"New posts available"_ không tự nhảy vị trí đọc.
- **Messages ([Messages.vue](../resources/js/pages/Messages.vue))**: Mô hình Master-Detail tự nhiên trên Mobile (chỉ hiện danh sách hội thoại hoặc khung chat toàn màn hình kèm nút quay lại), tạm dừng polling khi tab ẩn hoặc ngoại tuyến.
- **Wallet & Ads ([Wallet.vue](../resources/js/pages/Wallet.vue), [Ads.vue](../resources/js/pages/Ads.vue))**: Lưới số dư 2 cột gọn gàng, hộp thoại xác nhận trước khi nạp tiền/hủy chiến dịch, nút sao chép mã giao dịch, `inputmode="numeric"`, khóa double-submit và chặn gửi giao dịch khi offline.
- **Mobile Admin Center**: Sidebar chuyển sang Drawer `85vw` (`AdminLayout.vue`); các bảng trọng yếu ([Users/Index.vue](../resources/js/pages/admin/Users/Index.vue), [Finance/Index.vue](../resources/js/pages/admin/Finance/Index.vue), [Content/Posts.vue](../resources/js/pages/admin/Content/Posts.vue), [Ads.vue](../resources/js/pages/admin/Ads.vue)) chuyển sang **Mobile Card List** trên `< md` trong khi giữ nguyên bảng dữ liệu Desktop.
