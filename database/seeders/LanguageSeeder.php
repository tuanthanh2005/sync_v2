<?php

namespace Database\Seeders;

use App\Services\System\LanguageService;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        app(LanguageService::class)->ensureSeeded();
    }
}
