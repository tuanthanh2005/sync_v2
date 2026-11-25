# SyncViet — Hostinger Shared Hosting Deployment Guide

## 1. Mục tiêu & Giới hạn hạ tầng

- Môi trường: Hostinger Shared Hosting (2 CPU, 3072 MB RAM, 50 GB Storage, 60 PHP Workers, 120 Max Processes, Singapore) + Cloudflare Free.
- **Cam kết tách biệt**: Không chạm vào bất kỳ file, database hay domain của website bán AI đang hoạt động. Tạo website và database riêng biệt cho SyncViet.

## 2. Yêu cầu trước khi triển khai

1. Xác minh PHP Web & CLI = **8.4** qua hPanel / SSH (`php -v`).
2. Xác minh extensions: `bcmath`, `ctype`, `curl`, `dom`, `exif`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, `tokenizer`, `xml`, `zip`.
3. Tạo database MySQL/MariaDB riêng (`utf8mb4_unicode_ci`) và user riêng chỉ có quyền trên database đó (Least Privilege).

## 3. Cấu trúc thư mục an toàn (Không hardcode đường dẫn)

- Đặt mã nguồn Laravel ở thư mục riêng **bên ngoài** vùng public web (ví dụ thư mục ứng dụng ngang hàng `public_html`), và chỉ ánh xạ thư mục `public/` làm Document Root (hoặc đồng bộ nội dung `public/` vào `public_html/` trỏ `index.php` về thư mục ứng dụng tương đối).
- Tuyệt đối không đưa `.env`, `.git`, `vendor/`, `storage/`, hay file backup vào thư mục truy cập web.

## 4. Build & Cài đặt Production

Build assets tĩnh trên máy local hoặc CI (không chạy Node daemon trên Hostinger):

```bash
npm ci
npm run build
```

Upload code + `public/build/` lên server, sau đó chạy qua SSH:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 5. Cấu hình `.env` Production cho Hostinger

```env
APP_NAME=SyncViet
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_LOCALE=vi
HASH_DRIVER=argon2id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_syncviet_db
DB_USERNAME=your_syncviet_user
DB_PASSWORD=your_strong_password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=public
MEDIA_DISK=public
```

_(Lưu ý: `config/inertia.php` giữ `'ssr.enabled' => false`.)_

## 6. Cron Jobs (Dùng đường dẫn PHP CLI xác minh từ `which php`)

```bash
/path/to/php84 /path/to/syncviet/artisan schedule:run >> /dev/null 2>&1
/path/to/php84 /path/to/syncviet/artisan queue:work database --stop-when-empty --max-time=40 --timeout=25 >> /dev/null 2>&1
```

## 7. Cloudflare Free

- SSL/TLS: **Full (strict)** với Origin Certificate hợp lệ.
- Caching: Chỉ cache tài nguyên tĩnh trong `/build/*`; cấu hình Bypass Cache cho các route động/đăng nhập (`Cache-Control: private, no-store`).
- Tắt Rocket Loader nếu ảnh hưởng tới ES module scripts của Vite.
