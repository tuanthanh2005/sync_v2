# SyncViet — Mobile & Cross-Browser Testing Guide

## 1. Ma trận Kiểm thử Đa kích thước & Trình duyệt

### Kích thước Viewport mục tiêu

- Compact Phones: `320×568`, `360×800`
- iOS Modern Phones: `375×812`, `390×844`, `393×852`, `414×896`, `430×932`
- Large Android / Phablet: `480×854`
- Tablet Portrait & Landscape: `768×1024`, `1024×768`
- Desktop: `1280×800`, `1440×900`

### Trình duyệt & Chế độ cần kiểm thử thực tế

- Safari iOS (iPhone có Notch/Dynamic Island — Portrait & Landscape)
- Chrome Android & Samsung Internet (bàn phím ảo + thanh điều hướng cử chỉ)
- Firefox Android & Microsoft Edge Mobile
- Desktop Chrome / Safari / Edge
- PWA Standalone Mode (sau khi Add to Home Screen)

## 2. Phân biệt rõ Kiểm thử Tự động / Mô phỏng vs Thiết bị thật

| Hạng mục kiểm thử                                      | Tự động (Pest / Build / Static Check)                                              | Mô phỏng Viewport                          | Thiết bị thật (iOS / Android)                |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------- | ------------------------------------------ | -------------------------------------------- |
| Viewport meta (`viewport-fit=cover`, không chặn zoom)  | Đã kiểm chứng (`MobileAndPwaReadinessTest`)                                        | Có thể xác nhận                            | Cần xác nhận thủ công                        |
| CSP cho Service Worker, Manifest & Blob preview        | Đã kiểm chứng (`MobileAndPwaReadinessTest`)                                        | N/A                                        | N/A                                          |
| Service Worker không cache route nhạy cảm / thanh toán | Đã kiểm chứng (`MobileAndPwaReadinessTest`)                                        | Có thể kiểm tra DevTools Application       | Cần kiểm tra chế độ máy bay                  |
| 15 ngôn ngữ đồng bộ 658 key + RTL `dir="rtl"`          | Đã kiểm chứng (`MobileAndPwaReadinessTest` & `FinanceSettingsAndLocalizationTest`) | Kiểm tra tràn chữ 320px/375px              | Cần kiểm tra trên iPhone/Android             |
| Không tự zoom iOS khi focus input (`16px`)             | Đã kiểm chứng quy tắc CSS                                                          | **Không phản ánh hành vi iOS Safari thật** | **Bắt buộc test trên iPhone thật**           |
| Bàn phím ảo + Visual Viewport + Bottom Nav ẩn          | Đã triển khai & type-check                                                         | Hạn chế (không có bàn phím gốc OS)         | **Bắt buộc test trên iOS & Android thật**    |
| Pinch-to-zoom & Swipe Image Lightbox                   | Đã kiểm chứng logic & type-check                                                   | Mô phỏng touch hạn chế                     | **Bắt buộc test đa chạm trên thiết bị thật** |
| Long-press chọn văn bản & Copy/Paste OS                | Đã kiểm chứng không có `user-select: none` toàn cục                                | Mô phỏng hạn chế                           | **Bắt buộc test trên iOS & Android thật**    |

## 3. Checklist Kiểm thử Thủ công trên Thiết bị thật (Release Sign-off)

1. Mở `https://syncviet.com` trên iPhone (Safari) & Android (Chrome): kiểm tra không có thanh cuộn ngang ở trang chủ, bài viết, hồ sơ, cộng đồng, ví, quảng cáo, tin nhắn, đăng nhập và `/admin`.
2. Bấm vào ô tìm kiếm, bình luận, đăng bài, nạp ví trên iPhone: xác nhận màn hình **không tự phóng to**; thử chụm hai ngón phóng to trang web xác nhận **pinch-to-zoom vẫn hoạt động**.
3. Nhấn giữ đoạn văn bản bài viết, bình luận, `@username` và mã giao dịch: xác nhận menu Copy/Paste hệ điều hành hoạt động bình thường.
4. Bấm nút `+` trên Bottom Nav: xác nhận mở Full-Screen Composer, nhập nháp rồi bấm `X` → hiện hộp thoại xác nhận hủy bản nháp; nút `Publish` không bị bàn phím che.
5. Bấm vào ảnh bài viết nhiều ảnh: vuốt ngang trên Feed (Scroll Snap), mở Full-Screen Lightbox, pinch-zoom, vuốt chuyển ảnh, bấm nút Back điện thoại để đóng và xác nhận trang vẫn cuộn bình thường.
6. Chuyển sang **العربية (RTL)** và **Deutsch / Tiếng Việt**: xác nhận không cắt chữ nút bấm hay vỡ bố cục.
7. Bật chế độ Máy bay (Offline): xác nhận banner ngoại tuyến xuất hiện, tải lại trang hiện `offline.html`, bật mạng lại hiển thị thông báo kết nối lại.
8. Cài đặt PWA (_Add to Home Screen_ / _Install App_): mở ở chế độ Standalone và kiểm tra Safe Area trên đỉnh và đáy màn hình.
