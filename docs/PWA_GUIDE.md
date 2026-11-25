# SyncViet — PWA Readiness & Security Guide

## 1. Thành phần PWA đã triển khai

- **Web App Manifest**: [public/manifest.webmanifest](../public/manifest.webmanifest) (`name`, `short_name`, `display: "standalone"`, `theme_color: "#4F46E5"`, `background_color: "#F3F5F9"`, `start_url: "/?source=pwa"`).
- **App Icons cục bộ (zero CDN)**:
    - `public/icons/icon-192.png` (`192×192`, `purpose: any`)
    - `public/icons/icon-512.png` (`512×512`, `purpose: any`)
    - `public/icons/icon-maskable-512.png` (`512×512`, `purpose: maskable`, vùng đệm an toàn 24%)
    - `public/apple-touch-icon.png` (`180×180`)
- **Service Worker an toàn**: [public/sw.js](../public/sw.js) đăng ký tự động qua [usePwa.ts](../resources/js/composables/usePwa.ts) chỉ trên HTTPS hoặc `localhost`.
- **Offline Fallback Page**: [public/offline.html](../public/offline.html) giao diện sáng/tối tự động kèm nút thử lại.
- **Install & Update UX không gây phiền**:
    - Tuyệt đối không bật popup cài đặt liên tục. Nút _"Install SyncViet App"_ chỉ xuất hiện tinh gọn bên trong Mobile Drawer khi trình duyệt phát `beforeinstallprompt` và người dùng chưa bỏ qua (`syncviet_pwa_dismissed`).
    - Khi phát hiện bản Service Worker mới, hiển thị 1 Toast có nút **Reload** gọi `SKIP_WAITING` an toàn.
    - Nếu trình duyệt không hỗ trợ `beforeinstallprompt` (ví dụ Safari iOS), nút cài đặt tự ẩn sạch, không vẽ UI giả.

## 2. Ranh giới Bảo mật Cache (Bắt buộc)

Service Worker (`public/sw.js`) áp dụng cơ chế **allowlist nghiêm ngặt**:

- **CHỈ cache**: Tài nguyên tĩnh bất biến có hash trong `/build/*`, `/icons/*`, `/favicon.*`, `/apple-touch-icon.png`, `/manifest.webmanifest`, và `/offline.html`.
- **TUYỆT ĐỐI KHÔNG cache**:
    - Trang HTML động và mọi request có header `X-Inertia` hoặc `X-Requested-With: XMLHttpRequest`.
    - Mọi request không phải `GET` (`POST`, `PUT`, `PATCH`, `DELETE`).
    - Toàn bộ tiền tố nhạy cảm: `/admin`, `/messages`, `/wallet`, `/ads`, `/payments`, `/webhooks`, `/settings`, `/notifications`, `/login`, `/register`, `/logout`, `/two-factor`, `/forgot-password`, `/reset-password`, `/email`, `/api`.
- **Giao dịch tài chính khi Offline**: Không lưu hàng đợi và không tự động thực thi lại giao dịch thanh toán khi thiết bị offline; [Wallet.vue](../resources/js/pages/Wallet.vue) kiểm tra `navigator.onLine` trước khi mở xác nhận nạp tiền và báo trạng thái ngoại tuyến rõ ràng.
- **CSP Headers**: [SecurityHeaders.php](../app/Http/Middleware/SecurityHeaders.php) chỉ cho phép `worker-src 'self'` và `manifest-src 'self'`.
