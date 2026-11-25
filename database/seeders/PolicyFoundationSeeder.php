<?php

namespace Database\Seeders;

use App\Services\Policy\PolicyManagementService;
use Illuminate\Database\Seeder;

class PolicyFoundationSeeder extends Seeder
{
    public function __construct(
        private readonly PolicyManagementService $policyManagementService,
    ) {}

    /**
     * Seed the 10 default SyncViet policy page drafts and hidden footer links.
     */
    public function run(): void
    {
        $this->policyManagementService->ensureDefaultPoliciesExist();
    }
}
