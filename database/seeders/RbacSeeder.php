<?php

namespace Database\Seeders;

use App\Services\Security\RbacService;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        app(RbacService::class)->syncCatalog();
    }
}
