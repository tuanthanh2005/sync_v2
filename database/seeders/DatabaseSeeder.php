<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed development and testing records (never executed in production and never using fixed credentials).
     */
    public function run(): void
    {
        $this->call([
            PolicyFoundationSeeder::class,
            RbacSeeder::class,
            LanguageSeeder::class,
            MonetizationSeeder::class,
            SeoSeeder::class,
            AiContentCenterSeeder::class,
            GrowthSeeder::class,
        ]);

        if (app()->isProduction()) {
            return;
        }

        $configuredPassword = config('syncviet.dev_seed_password');
        $generated = ! is_string($configuredPassword) || $configuredPassword === '';
        $seedPassword = $generated ? Str::password(20) : $configuredPassword;

        User::unguarded(static function () use ($seedPassword): void {
            User::query()->updateOrCreate(
                ['email' => 'admin@syncviet.local'],
                [
                    'name' => 'Quản trị viên SyncViet',
                    'username' => 'syncviet_admin',
                    'password' => $seedPassword,
                    'email_verified_at' => now(),
                    'role' => UserRole::Admin,
                    'status' => UserStatus::Active,
                    'timezone' => 'Asia/Ho_Chi_Minh',
                ],
            );

            User::query()->updateOrCreate(
                ['email' => 'mod@syncviet.local'],
                [
                    'name' => 'Điều hành viên SyncViet',
                    'username' => 'syncviet_mod',
                    'password' => $seedPassword,
                    'email_verified_at' => now(),
                    'role' => UserRole::Moderator,
                    'status' => UserStatus::Active,
                    'timezone' => 'Asia/Ho_Chi_Minh',
                ],
            );

            User::query()->updateOrCreate(
                ['email' => 'member@syncviet.local'],
                [
                    'name' => 'Thành viên SyncViet',
                    'username' => 'syncviet_member',
                    'password' => $seedPassword,
                    'email_verified_at' => now(),
                    'role' => UserRole::User,
                    'status' => UserStatus::Active,
                    'timezone' => 'Asia/Ho_Chi_Minh',
                ],
            );
        });

        if ($generated && $this->command !== null) {
            $this->command->warn("Local dev seed password generated (set DEV_SEED_PASSWORD in .env to customize): {$seedPassword}");
        }
    }
}
