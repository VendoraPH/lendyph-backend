<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Services\AuditLogService;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /** Seeding writes no audit rows: a fresh database's branch is the baseline. */
    public function run(): void
    {
        AuditLogService::withoutModelAuditing(fn () => Branch::create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => 'Head Office',
        ]));
    }
}
