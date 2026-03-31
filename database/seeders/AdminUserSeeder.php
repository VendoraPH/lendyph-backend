<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'username' => 'admin',
            'email' => 'admin@lendyph.com',
            'password' => 'password',
            'branch_id' => 1,
            'status' => 'active',
        ]);

        $admin->assignRole('admin');
    }
}
