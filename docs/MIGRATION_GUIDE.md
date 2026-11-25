# SyncViet — Server Migration Guide (Hostinger Shared Hosting -> VPS / Cloud)

Mục tiêu: Di chuyển toàn bộ hệ thống SyncViet từ Hostinger Shared Hosting sang VPS / Cloud Server **không viết lại mã nguồn**, **không mất dữ liệu mã hóa**, và **bảo toàn tuyệt đối tính nhất quán tài chính (số dư, sổ cái giao dịch, chiến dịch quảng cáo)**.

---

## 1. Nguyên tắc bất biến khi di chuyển

1. **Bảo toàn `APP_KEY`**: Sao chép nguyên vẹn `APP_KEY` từ `.env` cũ sang `.env` mới (hoặc thêm khóa cũ vào `APP_PREVIOUS_KEYS` nếu xoay vòng khóa). Nếu mất `APP_KEY`, dữ liệu 2FA secret (`two_factor_secret`, `two_factor_recovery_codes`) và cookie/payload mã hóa sẽ hỏng.
2. **Đóng băng ghi dữ liệu (Maintenance Cutover)**: Trước khi dump database cuối cùng, bật Maintenance Mode để tránh chênh lệch số dư, giao dịch thanh toán hoặc trạng thái chiến dịch quảng cáo giữa 2 server.
3. **DNS TTL thấp**: Giảm TTL bản ghi DNS trên Cloudflare xuống ngắn nhất (hoặc Proxied switch) ít nhất 24 giờ trước giờ chuyển đổi.

---

## 2. Quy trình di chuyển từng bước

### Bước 1: Chuẩn bị VPS đích

- Cài đặt PHP 8.4, Composer, Nginx, MySQL 8.4/MariaDB, Redis, Supervisor theo `docs/DEPLOYMENT_VPS.md`.
- Triển khai đúng phiên bản mã nguồn (`git tag` / release artifact + `composer.lock` + `package-lock.json` + `public/build/`) lên VPS.
- Chuẩn bị `.env` trên VPS với **`APP_KEY` giống hệt Hostinger** và DB credentials riêng của VPS.

### Bước 2: Đóng băng hệ thống cũ & đồng bộ dữ liệu cuối

Trên Hostinger (qua SSH):

```bash
php artisan down --retry=60
# Chờ queue tồn đọng hoàn tất
php artisan queue:work database --stop-when-empty --max-time=40 --timeout=25
```

Dump toàn bộ cơ sở dữ liệu nhất quán transaction (InnoDB):

```bash
mysqldump --single-transaction --routines --triggers --hex-blob -u <user> -p <db_name> > syncviet_migration.sql
```

Đồng bộ thư mục `storage/app/` (bao gồm `storage/app/public/` và `storage/app/private/`) sang VPS bằng `rsync` / `scp` hoặc đẩy lên Cloudflare R2 nếu chuyển sang `MEDIA_DISK=s3`.

### Bước 3: Nạp dữ liệu & kiểm tra tính toàn vẹn trên VPS

Trên VPS:

```bash
mysql -u <vps_user> -p <vps_db> < syncviet_migration.sql
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Kiểm tra nhanh tính nhất quán dữ liệu:

- Đối chiếu số lượng bản ghi `users`, `policy_pages`, `footer_links`, `audit_logs` (và từ Phase 3+: tổng số dư ví, tổng ledger, trạng thái `ad_campaigns` & `payments`).
- Xác minh đăng nhập thử tài khoản có bật 2FA qua host/header test nội bộ (kiểm chứng `APP_KEY` giải mã TOTP thành công).

### Bước 4: Mở dịch vụ & chuyển DNS

```bash
php artisan up
```

- Cập nhật Origin IP trên Cloudflare sang IP VPS, giữ SSL **Full (strict)**.
- Theo dõi log (`storage/logs/laravel.log`, Nginx access/error log, queue worker log).

---

## 3. Quy trình Rollback khi di chuyển thất bại

Nếu phát hiện lỗi nghiêm trọng sau khi trỏ DNS sang VPS (trước khi có giao dịch mới phát sinh trên VPS):

1. Đưa VPS vào chế độ bảo trì (`php artisan down`).
2. Trỏ lại Origin IP trên Cloudflare về Hostinger.
3. Gỡ bảo trì trên Hostinger (`php artisan up`) vì dữ liệu gốc trên Hostinger được giữ nguyên trạng thái đóng băng sạch.
4. Phân tích log trên VPS để khắc phục trước khi thử lại cutover.
