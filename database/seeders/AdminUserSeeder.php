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

        $admin->assignRole('admin');
    }
}
