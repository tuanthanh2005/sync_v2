# SyncViet — Mobile Compatibility & Device Matrix

## 1. Breakpoints & Kích thước màn hình hỗ trợ

| Nhóm thiết bị         | Độ rộng tiêu chuẩn                          | Quy chuẩn áp dụng                                                                                                                             |
| --------------------- | ------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Compact Mobile        | `320px`, `360px`                            | 1 cột toàn chiều rộng (`px-2.5`), ẩn tagline logo trên Header (`hidden sm:block`), Drawer `w-[85vw]`, nút chạm `>= 40–44px`, không tràn ngang |
| Standard Mobile       | `375px`, `390px`, `393px`, `414px`, `430px` | Bottom Nav 5 mục, Bottom Sheet Dialog (`max-h-[90dvh]`), 2-col Balance Cards, Swipe Image Gallery (`snap-x`)                                  |
| Large Phone / Phablet | `480px` – `767px`                           | Full-screen Composer, Master-Detail Messages, Mobile Card Lists cho Admin                                                                     |
| Tablet                | `768px` – `1023px`                          | Inline Composer, Search bar trên Header, Modal căn giữa, Data Table Admin                                                                     |
| Desktop               | `1024px+` / `1280px+`                       | Bố cục 3 cột `ConnectHubLayout` (`240px` + `760px` + `320px`) & Sidebar Admin thu gọn                                                         |

## 2. Tương thích Safe Area & Bàn phím ảo

- **Notch / Dynamic Island / Camera Cutout**: Sử dụng `viewport-fit=cover` và các class `.safe-pt`, `.safe-pb`, `.safe-px` dựa trên `env(safe-area-inset-*)`.
- **Dynamic Viewport Units (`dvh`)**: Thay thế `100vh` bằng `100dvh` cho Modal, Sheet, Full-Screen Composer, Image Lightbox và Messages nhằm tránh lỗi thanh địa chỉ động trên Safari iOS & Chrome Android.
- **Visual Viewport API**: [useMobileViewport.ts](../resources/js/composables/useMobileViewport.ts) theo dõi `window.visualViewport` (có fallback `window.innerHeight`) để cập nhật `--sv-keyboard-height` và ẩn `MobileBottomNav` khi bàn phím ảo đang mở, ngăn tình trạng thanh điều hướng nổi đè lên ô nhập liệu.

## 3. Tương thích Quốc tế (15 Ngôn ngữ + RTL)

- Kiểm soát độ rộng linh hoạt (`min-w-0`, `truncate`, `whitespace-nowrap` trên tab cuộn ngang) cho các ngôn ngữ có chuỗi dài như tiếng Đức (`de`), Pháp (`fr`), Tây Ban Nha (`es`), Bồ Đào Nha (`pt-BR`), Indonesia (`id`), Filipino (`fil`), Việt (`vi`).
- Hỗ trợ RTL hoàn chỉnh (`dir="rtl"`) cho tiếng Ả Rập (`ar`), đồng bộ vị trí Toast, nút đóng Toast và hướng thanh tiến trình.
- Font chữ `Be Vietnam Pro` tự host kết hợp fallback hệ thống cho chữ Ả Rập, CJK (`ja`, `ko`, `zh-CN`), Thái (`th`), Hindi (`hi`) mà không tải đồng loạt font ngoài gây nặng mạng 3G/4G.

## 4. Tương thích Trình duyệt mục tiêu

- **Safari iOS (16+)**: `viewport-fit=cover`, `16px` input anti-zoom, `100dvh`, `apple-mobile-web-app-capable`, `apple-touch-icon.png` (`180×180`), đồng bộ động `theme-color`.
- **Chrome Android & Samsung Internet**: Web App Manifest (`manifest.webmanifest`), icon `192`/`512`/`maskable`, `beforeinstallprompt` có kiểm soát, Visual Viewport API.
- **Firefox Android & Edge**: `.no-scrollbar` dùng `scrollbar-width: none`, `overscroll-behavior-contain` trên Modal/Sheet, Service Worker chuẩn W3C.
- **Hostinger Shared Hosting**: Không phụ thuộc Node SSR daemon, không cần cấu hình đặc thù ngoài static files trong `public/` (`manifest.webmanifest`, `sw.js`, `offline.html`, `icons/*`).
