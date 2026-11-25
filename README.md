# SyncViet

Nền tảng mạng xã hội cộng đồng song ngữ Việt/Anh kết hợp hệ thống quảng bá bài viết nội bộ, xây dựng theo kiến trúc **Modular Monolith + Service Layer** trên **Laravel 13**, **Vue 3**, **Inertia.js 3**, **TypeScript**, **Tailwind CSS 4**, **shadcn-vue**, **vue-i18n** và **MySQL**, tuân thủ **OWASP Top 10:2025 / ASVS Level 2** và có khả năng di chuyển linh hoạt từ **Hostinger Shared Hosting** sang **VPS / Cloud Server** không cần viết lại mã nguồn.

## Công nghệ cốt lõi

- **Backend**: PHP 8.4, Laravel 13, Eloquent ORM, Laravel Fortify, Laravel Wayfinder, Argon2id
- **Frontend**: Vue 3 (`<script setup lang="ts">`), TypeScript, Inertia.js 3 (CSR), Tailwind CSS 4, shadcn-vue (`reka-ui`), `@lucide/vue`, `vue-i18n`, Vite (`vite-plus`)
- **Database & Portability**: MySQL 8.4 / MariaDB (`utf8mb4_unicode_ci`, InnoDB), Database/Redis Session-Cache-Queue qua `.env`, Storage Local & S3/Cloudflare R2
- **Security & Quality**: Pest PHP 5, Larastan (PHPStan level 7), Laravel Pint, `vue-tsc`, `composer audit`, `npm audit`, Laravel Boost (dev only)

## Cài đặt môi trường local (Windows / Laragon)

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Cấu hình `.env` kết nối MySQL local (tạo database `connecthub` hoặc `syncviet` theo `.env` của bạn):

```env
APP_NAME=SyncViet
APP_LOCALE=vi
HASH_DRIVER=argon2id
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=connecthub
DB_USERNAME=root
DB_PASSWORD=
```

Chạy migration và seed:

```bash
php artisan migrate
php artisan db:seed # Chỉ dùng trong môi trường local; đọc DEV_SEED_PASSWORD hoặc sinh mật khẩu ngẫu nhiên
npm run build
```

_(Không sử dụng mật khẩu Admin cố định trong mã nguồn. Đặt `DEV_SEED_PASSWORD` trong `.env` local nếu muốn dùng mật khẩu riêng cho seed dev.)_

## Kiểm thử & Audit

```bash
composer audit
npm audit
php ./vendor/bin/pint --test
php ./vendor/bin/phpstan analyse
npm run types:check
npm run check
php artisan test
npm run build
```

## Tài liệu dự án

- [AGENTS.md](AGENTS.md) — Quy chuẩn kỹ thuật, bảo mật & portability bắt buộc
- [docs/PROJECT_PLAN.md](docs/PROJECT_PLAN.md) — Kế hoạch tổng thể & lộ trình Phase 0–5
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — Kiến trúc Modular Monolith, i18n, Footer & Server Portability
- [docs/DATABASE.md](docs/DATABASE.md) — Lược đồ cơ sở dữ liệu
- [docs/DESIGN_SYSTEM.md](docs/DESIGN_SYSTEM.md) — Hệ thống giao diện, Light/Dark Mode & Footer Variants
- [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — Quy tắc nghiệp vụ & phân giải xung đột
- [docs/SECURITY.md](docs/SECURITY.md) — Tiêu chuẩn bảo mật OWASP Top 10:2025 / ASVS Level 2
- [docs/SECURITY_CHECKLIST.md](docs/SECURITY_CHECKLIST.md) — Checklist kiểm duyệt bảo mật từng Phase
- [docs/DEPLOYMENT_HOSTINGER.md](docs/DEPLOYMENT_HOSTINGER.md) — Triển khai Hostinger Shared Hosting + Cloudflare
- [docs/DEPLOYMENT_VPS.md](docs/DEPLOYMENT_VPS.md) — Triển khai VPS / Cloud Server (Redis, Supervisor, S3/R2)
- [docs/MIGRATION_GUIDE.md](docs/MIGRATION_GUIDE.md) — Quy trình di chuyển Hostinger -> VPS không viết lại code
- [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md) — Quy trình sao lưu, bảo toàn `APP_KEY` và kiểm thử khôi phục
- [docs/TASKS.md](docs/TASKS.md) — Bảng theo dõi tiến độ
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — Lịch sử thay đổi
