<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'username' => 'super_admin',
            'email' => 'super_admin@lendyph.com',
            'password' => 'password',
            'branch_id' => 1,
            'status' => 'active',
        ]);

        $admin->assignRole('super_admin');

        // `branch_id` is a column; the assignment lives in `branch_user`. Setting
        // only the column leaves the seeded super admin out of every branch —
        // which would then be the baseline ~90 test classes inherit through
        // SetupLendyPH, so the account the whole suite acts as would be the one
        // account with no branches.
        $admin->branches()->sync([1]);
    }
}
