# ConnectHub — Business Rules & Conflict Resolution

## 1. Quy tắc nghiệp vụ cốt lõi (Phase 0)

1. **Quyền đọc công khai vs Tương tác có tài khoản**:
    - Khách chưa đăng nhập có thể truy cập trang chủ `/` và đọc thống kê/nội dung công khai (Phase 1 sẽ mở bài viết công khai).
    - Các thao tác cá nhân hóa, cài đặt tài khoản, bảo mật 2FA yêu cầu xác thực (`auth`). Khu vực quản trị và thao tác nhạy cảm yêu cầu thêm `verified` + `active` + `role` + `AccountPolicy`.
2. **Định danh & Bảo vệ vai trò**:
    - Mọi đăng ký công khai qua Fortify luôn bị ép `role = UserRole::User` và `status = UserStatus::Active` ở server (`CreateNewUser`), bỏ qua mọi `role` hoặc `status` gửi từ client.
    - `username` chỉ chấp nhận `a-z`, `0-9`, `_` (3–40 ký tự, lowercase, unique). Nếu người dùng bỏ trống khi đăng ký, `UsernameGenerator` tự chuyển tên tiếng Việt thành handle hợp lệ duy nhất.
3. **Trạng thái tài khoản**:
    - Tài khoản `suspended` hoặc `banned` không thể đăng nhập (`Fortify::authenticateUsing`) và bị đăng xuất ngay lập tức bởi `EnsureUserIsActive` middleware nếu trạng thái thay đổi khi đang giữ session.
4. **Nguyên tắc chống dữ liệu giả & nút bấm chết**:
    - Tuyệt đối không tạo dữ liệu người dùng, lượt xem, thích, bình luận hay giao dịch giả trên production.
    - Dữ liệu `DatabaseSeeder` chỉ chạy khi `!app()->isProduction()`.
    - Không hiển thị nút bấm hoặc mục menu của Phase sau khi chức năng chưa hoạt động.

## 2. Xử lý các điểm xung đột trong tài liệu & mockup

| Nguồn                                                                                               | Điểm xung đột                                                                                                                                     | Quyết định kiến trúc áp dụng                                                                                                                                                                 |
| --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Mockup 3 (Project Plan)** vs **Master Plan & Stack bắt buộc**                                     | Ảnh 3 ghi `Next.js, React, NestJS, PostgreSQL, Redis, AWS S3, Docker, Kubernetes` và cam kết KPI `>= 100.000 users / >= 1 tỷ VNĐ`                 | Ảnh chỉ là tham chiếu thẩm mỹ. Stack chính thức là **PHP 8.4 + Laravel 13 + Vue 3 + Inertia 3 + Tailwind 4 + shadcn-vue + MySQL** trên Hostinger Shared Hosting; không đặt KPI doanh thu ảo. |
| **Mockup 1/2/4** (`Ví tiền`, `Bình chọn`, `Tin nhắn`) vs **Lộ trình Phase & Quy tắc không nút giả** | Ảnh hiển thị icon Chat (Phase 5), nút Poll (Phase 5), Ví tiền `2.450.000đ` (Phase 3/pháp lý)                                                      | Ẩn khỏi UI Phase 0 để tránh nút bấm giả; chỉ mở ở đúng Phase tương ứng sau khi nghiệp vụ và backend hoàn chỉnh.                                                                              |
| **Starter Kit mặc định** vs **Hostinger Shared Hosting**                                            | Starter kit mặc định bật `inertia.ssr.enabled = true`, dùng `SQLite`, `Instrument Sans`, và bật `Passkeys`                                        | Tắt Inertia SSR (`false`), chuyển sang MySQL (`utf8mb4_unicode_ci`), dùng font `Be Vietnam Pro`, bỏ Passkeys ở MVP (giữ Email Verification, Password Reset, 2FA, Password Confirmation).     |
| **Mockup 4 (Ads CPC/Estimates)** vs **Master Plan P3**                                              | Ảnh 4 hiển thị ước tính lượt tiếp cận/CPC và ngân sách ngày; Master Plan khuyến nghị gói thời gian cố định (24h/7 ngày) không cam kết impressions | Giai đoạn MVP (Phase 3) sẽ ưu tiên gói thời gian cố định minh bạch và chỉ bổ sung ví/credit sau khi rà soát điều kiện pháp lý & cổng thanh toán.                                             |
