# SyncViet — Mobile Performance Optimization

## 1. Tối ưu JavaScript Bundle & Dynamic Code-Splitting

- **Tách động 14 gói ngôn ngữ ([resources/js/i18n/index.ts](../resources/js/i18n/index.ts))**:
    - Trước nâng cấp: cả 15 file JSON ngôn ngữ được import tĩnh đồng bộ khiến `app.js` nặng **679.42 kB** (`194.18 kB` gzip).
    - Sau nâng cấp: chỉ giữ `en.json` làm fallback mặc định trong bundle lõi; 14 ngôn ngữ còn lại được nạp động qua `import()` theo đúng ngôn ngữ người dùng chọn, giúp giảm hàng trăm kB JavaScript tải xuống trên mạng 3G/4G.
- **Inertia Page Code-Splitting**: Mỗi trang Inertia Vue được Vite tách thành chunk riêng biệt theo route.

## 2. Tối ưu Media, DOM & API Requests

- **Images**: Tất cả ảnh bài viết và preview dùng `loading="lazy"` và `decoding="async"`, giới hạn khung hiển thị bằng tỷ lệ cố định (`aspect-[4/3]`, `aspect-square`) để giảm CLS.
- **News Feed DOM Guard**: [Welcome.vue](../resources/js/pages/Welcome.vue) giới hạn tối đa `120` bài đăng render cùng lúc (`MAX_RENDERED_POSTS`), hủy `AbortController` khi có request tải thêm mới, chỉ kích hoạt kiểm tra bài mới khi tab hiển thị (`!document.hidden`), đang có mạng (`navigator.onLine`) và người dùng đang cuộn sâu.
- **Search Debounce**: Cả Header Search (`ConnectHubLayout.vue`) và trang Search (`Search.vue`) đều debounce `350ms` và dọn sạch timer tại `onBeforeUnmount`.
- **Messages Polling**: [Messages.vue](../resources/js/pages/Messages.vue) giãn nhịp lên `10s`, dừng hoàn toàn khi tab ẩn hoặc offline, và `clearInterval` khi unmount (không memory leak).
- **Animation nhẹ & Reduced Motion**: Dùng CSS transform (`translateY`, `scale`), tắt hiệu ứng khi `prefers-reduced-motion: reduce`.

## 3. Mục tiêu Core Web Vitals (p75 thực tế)

- **LCP** $\le 2.5\text{s}$
- **INP** $\le 200\text{ms}$
- **CLS** $\le 0.1$

_(Lưu ý trung thực: đây là ngưỡng mục tiêu vận hành tại phân vị 75 của người dùng thực (RUM / CrUX / Lighthouse Mobile 4G throttling). Cần thu thập dữ liệu đo đạc thực tế trên production sau khi deploy thay vì giả định đã đạt mục tiêu chỉ từ môi trường local.)_
