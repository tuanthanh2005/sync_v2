# SyncViet — Business Rules & Governance Invariants

## 1. Quy tắc nghiệp vụ cốt lõi

1. **Quyền đọc công khai vs Tương tác có tài khoản**:
    - Khách chưa đăng nhập có thể truy cập trang chủ `/`, đọc bài viết công khai, xem permalink SEO và đọc các trang chính sách đã xuất bản.
    - Các thao tác đăng bài, tương tác, nhắn tin, quản trị quảng cáo và cài đặt bảo mật yêu cầu xác thực (`auth`). Khu vực quản trị yêu cầu thêm `verified` + `active` + `admin.2fa` + quyền RBAC tương ứng (`permission:*`).
2. **Định danh & Bảo vệ vai trò**:
    - Mọi đăng ký công khai qua Fortify luôn bị ép `role = UserRole::User` và `status = UserStatus::Active` ở server (`CreateNewUser`), bỏ qua mọi `role` hoặc `status` gửi từ client.
    - `username` chỉ chấp nhận `a-z`, `0-9`, `_` (3–40 ký tự, lowercase, unique). Nếu người dùng bỏ trống khi đăng ký, `UsernameGenerator` tự chuyển tên thành handle hợp lệ duy nhất.
3. **Trạng thái tài khoản, Chế tài & Tự động hết hạn**:
    - Tài khoản `suspended` hoặc `banned` không thể đăng nhập (`Fortify::authenticateUsing`), lập tức bị thu hồi toàn bộ `sessions` đang hoạt động và bị chặn bởi `EnsureUserIsActive`.
    - Chế tài hỗ trợ thời hạn linh hoạt (`1h`, `24h`, `3d`, `7d`, `30d`, `custom`, `permanent`) áp dụng cho tạm khóa toàn bộ hoặc giới hạn từng năng lực (`can_post`, `can_comment`, `can_message`, `can_advertise`).
    - Khi hết `expires_at`, cả lệnh định kỳ `app:sanctions:expire` lẫn middleware `EnsureUserIsActive` tự động mở khóa và khôi phục quyền cho người dùng.
4. **Tính nhất quán giữa Kiểm duyệt Nội dung, Quảng cáo & Tài chính**:
    - Khi Admin/Moderator ẩn hoặc xóa một bài viết đang có chiến dịch quảng cáo (`pending`, `active`, `paused`), hệ thống bắt buộc tự động dừng/hủy các chiến dịch gắn với bài viết đó và hoàn 100% phần ngân sách chưa chi tiêu về lại số dư quảng cáo của chủ bài viết trong cùng `DB::transaction` (`lockForUpdate`), ghi sổ cái `ad_credit_entries` và `audit_logs`.
    - Ẩn, khôi phục hoặc xóa bình luận luôn đồng bộ chính xác `comments_count` của bài viết gốc.
    - Từ khóa bị chặn (`blocked_keywords`) ở chế độ `block` sẽ chặn tạo bài viết/bình luận ở backend; ở chế độ `flag` sẽ cho đăng nhưng tự động đánh dấu cần kiểm duyệt.
    - Số dư nạp thực (`balance`) và tín dụng khuyến mãi (`promo_balance`) tách bạch hoàn toàn; hoàn tiền (`refund`) chỉ áp dụng trên giao dịch nạp thực hợp lệ, không được vượt quá phần chưa hoàn và không bao giờ tạo số dư âm.
5. **Feature Flags & Safe Maintenance Mode**:
    - Mọi công tắc tính năng trong `system_settings` (đăng ký mới, đăng bài, bình luận, cộng đồng, nhắn tin, quảng cáo, xác minh tài khoản) đều được kiểm tra ở tầng Service/Controller backend.
    - Khi bật chế độ bảo trì, khách và người dùng thường nhận HTTP 503 thân thiện; luồng đăng nhập, `/admin/*` và webhook thanh toán vẫn hoạt động để không khóa quản trị viên.
6. **Nguyên tắc chống dữ liệu giả & nút bấm chết**:
    - Tuyệt đối không tạo dữ liệu người dùng, lượt xem, thích, bình luận hay giao dịch giả trên production.
    - Dữ liệu `DatabaseSeeder` chỉ chạy khi `!app()->isProduction()`.
    - Các ngôn ngữ chưa hoàn thiện/chưa bật (`is_enabled = false`) không hiển thị công khai.
    - Các phân hệ thuộc lộ trình tương lai chưa xây dựng backend (rút tiền thưởng giới thiệu, chuyển tiền giữa người dùng, cấu hình SMTP trực tiếp trên UI) không được vẽ nút bấm giả; chỉ hiển thị khi nghiệp vụ và bảo mật backend đã hoàn tất.

## 2. Xử lý các điểm xung đột trong tài liệu & mockup

| Nguồn                                                                                               | Điểm xung đột                                                                                                                     | Quyết định kiến trúc áp dụng                                                                                                                                                                 |
| --------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Mockup 3 (Project Plan)** vs **Master Plan & Stack bắt buộc**                                     | Ảnh 3 ghi `Next.js, React, NestJS, PostgreSQL, Redis, AWS S3, Docker, Kubernetes` và cam kết KPI `>= 100.000 users / >= 1 tỷ VNĐ` | Ảnh chỉ là tham chiếu thẩm mỹ. Stack chính thức là **PHP 8.4 + Laravel 13 + Vue 3 + Inertia 3 + Tailwind 4 + shadcn-vue + MySQL** trên Hostinger Shared Hosting; không đặt KPI doanh thu ảo. |
| **Mockup 1/2/4** (`Ví tiền`, `Bình chọn`, `Tin nhắn`) vs **Lộ trình Phase & Quy tắc không nút giả** | Ảnh hiển thị icon Chat, nút Poll, Ví tiền `2.450.000đ`                                                                            | Chỉ mở dần sau khi backend, bảo mật và kiểm thử hoàn tất (hiện đã hoàn chỉnh cho Phase 1–3 + DMs/Polls; không tạo số liệu giả).                                                              |
| **Starter Kit mặc định** vs **Hostinger Shared Hosting**                                            | Starter kit mặc định bật `inertia.ssr.enabled = true`, dùng `SQLite`, `Instrument Sans`, và bật `Passkeys`                        | Tắt Inertia SSR (`false`), chuyển sang MySQL (`utf8mb4_unicode_ci`), dùng font `Be Vietnam Pro`, bỏ Passkeys ở MVP (giữ Email Verification, Password Reset, 2FA, Password Confirmation).     |
| **Mockup 4 (Ads CPC/Estimates)** vs **Master Plan P3**                                              | Ảnh 4 hiển thị ước tính lượt tiếp cận/CPC và ngân sách ngày; Master Plan khuyến nghị minh bạch ngân sách & kiểm toán chặt chẽ     | Mọi ngân sách quảng cáo tính bằng số nguyên VND ở backend, kiểm soát qua sổ cái append-only `ad_credit_entries` và phát hiện bất thường click/impression trong Admin Ads.                    |

| **Monetization & Financial Integrity (Phase 6)** | Tách bạch Premium, Banner Ads, Boost Post và Số dư quảng cáo | Chức năng mạng xã hội cốt lõi miễn phí 100%; Premium tách biệt Xác minh danh tính và không tự trừ Số dư quảng cáo; tối đa 5 đơn chờ thanh toán/người dùng; xác nhận thủ công Có/Không; Banner Exclusive khóa chống đặt trùng trong `DB::transaction`; Boost không bao giờ thao túng Thịnh hành tự nhiên; Crypto tắt mặc định đến khi duyệt pháp lý. |
