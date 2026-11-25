# SyncViet — VPS / Cloud Server Deployment Guide

Tài liệu này mô tả cấu hình triển khai SyncViet lên VPS hoặc Cloud Server (Ubuntu 24.04 LTS, Nginx, PHP 8.4-FPM, MySQL 8.4/MariaDB, Redis, Supervisor, Cloudflare R2) **không cần sửa mã nguồn**.

## 1. Kiến trúc VPS mục tiêu

- **Web Server**: Nginx -> PHP 8.4-FPM (`unix:/run/php/php8.4-fpm.sock`), Document Root trỏ trực tiếp tới `<app>/public`.
- **Database**: MySQL 8.4 hoặc Managed MySQL (InnoDB, `utf8mb4_unicode_ci`).
- **Cache / Session / Queue**: Redis (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`).
- **Media Storage**: S3-Compatible (Cloudflare R2) thông qua `FILESYSTEM_DISK=s3` & `MEDIA_DISK=s3`.

## 2. Chuyển đổi cấu hình qua `.env` (Zero Code Change)

Chỉ thay đổi biến môi trường trong `.env`:

```env
APP_NAME=SyncViet
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_KEY=base64:PRESERVE_EXACT_KEY_FROM_HOSTINGER

SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis

FILESYSTEM_DISK=s3
MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=your_r2_key
AWS_SECRET_ACCESS_KEY=your_r2_secret
AWS_DEFAULT_REGION=auto
AWS_BUCKET=syncviet-media
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
AWS_URL=https://media.your-domain.com
AWS_USE_PATH_STYLE_ENDPOINT=false
```

_(Nếu dùng S3/R2 driver, đảm bảo cài `league/flysystem-aws-s3-v3` qua Composer trước khi bật `FILESYSTEM_DISK=s3`.)_

## 3. Queue Worker với Supervisor

Tạo `/etc/supervisor/conf.d/syncviet-worker.conf`:

```ini
[program:syncviet-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/syncviet/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/syncviet/storage/logs/worker.log
stopwaitsecs=60
```

## 4. Scheduler Cron

```cron
* * * * * www-data php /var/www/syncviet/artisan schedule:run >> /dev/null 2>&1
```

## 5. Bảo mật Server & Nginx

- Chặn truy cập trực tiếp file ẩn (`.env`, `.git`, `.htaccess`), khóa quyền SSH key-only, bật UFW chỉ mở `22`, `80`, `443` (hoặc giới hạn `80/443` chỉ từ Cloudflare IP ranges).
- PHP `expose_php = Off`, upload limits kiểm soát chặt trong Nginx (`client_max_body_size`) và PHP-FPM.
