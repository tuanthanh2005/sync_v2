# SyncViet — Backup & Restore Runbook

## 1. Thành phần bắt buộc sao lưu

1. **Cơ sở dữ liệu MySQL / MariaDB**: Toàn bộ bảng bao gồm `users`, `policy_pages`, `footer_links`, `audit_logs`, `login_histories`, và từ Phase 1–3 là bảng bài viết, cộng đồng, thanh toán, sổ cái quảng cáo.
2. **Tài nguyên lưu trữ (`storage/app/`)**: Bao gồm cả `storage/app/public` và `storage/app/private` (hoặc bucket S3/Cloudflare R2 khi dùng object storage).
3. **Bí mật & Khóa mã hóa (`APP_KEY`, `.env`)**: Lưu giữ trong Password Manager / Secret Vault tách biệt ngoài hosting và **tuyệt đối không commit vào Git**.

## 2. Lịch trình & Lưu trữ ngoài Hosting

- **Tần suất**:
    - Database: Hàng ngày (tự động nén & mã hóa trước khi đồng bộ ra ngoài hosting, ví dụ bucket lưu trữ riêng/cloud storage bảo mật).
    - Media files: Đồng bộ tăng dần (`rclone` / S3 sync) hàng ngày.
- **Lưu giữ (Retention)**: Tối thiểu 7 bản gần nhất theo ngày + 4 bản theo tuần.
- **Bảo mật file backup**: Không lưu file `.sql` hoặc `.tar.gz` trong `public/` hoặc `public_html/`. Mã hóa file backup bằng `age` hoặc `gpg` trước khi lưu trữ ngoại vi.

## 3. Lệnh tạo Backup chuẩn (InnoDB Consistent Snapshot)

```bash
mysqldump --single-transaction --routines --triggers --hex-blob -u <db_user> -p <db_name> | gzip > syncviet_db_$(date +%F_%H%M).sql.gz
tar -czf syncviet_storage_$(date +%F_%H%M).tar.gz storage/app
```

## 4. Quy trình Restore & Kiểm thử khôi phục định kỳ

Thực hiện kiểm thử khôi phục ít nhất **1 lần/tháng** trên môi trường staging tách biệt:

```bash
# 1. Tạo database trống utf8mb4_unicode_ci
mysql -u root -p -e "CREATE DATABASE syncviet_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Giải nén và nạp dump
gunzip -c syncviet_db_YYYY-MM-DD.sql.gz | mysql -u root -p syncviet_restore

# 3. Giải nén media & gắn đúng APP_KEY từ vault
tar -xzf syncviet_storage_YYYY-MM-DD.tar.gz -C /path/to/staging
php artisan storage:link
php artisan optimize:clear
```

### Checklist xác minh sau Restore

- [ ] Số lượng bản ghi bảng `users`, `policy_pages`, `audit_logs` khớp với bản sao lưu.
- [ ] Đăng nhập được bằng tài khoản có TOTP 2FA (xác nhận `APP_KEY` khớp 100%).
- [ ] Trang `/up` trả HTTP `200`, trang `/policies/...` và Footer hiển thị đúng.
- [ ] (Phase 3+) Tổng kiểm toán số dư và `ad_credit_entries` không sai lệch.
