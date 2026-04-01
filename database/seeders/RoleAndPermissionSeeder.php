<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // User Management
            'users.view', 'users.create', 'users.update', 'users.deactivate', 'users.reset-password',

            // Customer Profiling
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',

            // Loan Management
            'loans.view', 'loans.create', 'loans.process', 'loans.approve', 'loans.release', 'loans.void',

            // Repayments
            'repayments.view', 'repayments.create', 'repayments.void',

            // Loan Adjustments
            'loan-adjustments.view', 'loan-adjustments.create', 'loan-adjustments.approve',

            // Reports
            'reports.view', 'reports.export',

            // Audit
            'audit-logs.view',
        ];

        $guard = 'web';

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission, 'guard_name' => $guard]);
        }

        // Admin — gets all permissions via Gate::before bypass
        Role::create(['name' => 'admin', 'guard_name' => $guard]);

        Role::create(['name' => 'loan-officer', 'guard_name' => $guard])->givePermissionTo([
            'customers.view', 'customers.create', 'customers.update',
            'loans.view', 'loans.create', 'loans.process',
            'loan-adjustments.view', 'loan-adjustments.create',
            'repayments.view',
            'reports.view', 'reports.export',
        ]);

        Role::create(['name' => 'cashier', 'guard_name' => $guard])->givePermissionTo([
            'customers.view',
            'loans.view', 'loans.release',
            'repayments.view', 'repayments.create',
            'reports.view',
        ]);

        Role::create(['name' => 'collector', 'guard_name' => $guard])->givePermissionTo([
            'customers.view',
            'loans.view',
            'repayments.view', 'repayments.create',
            'reports.view',
        ]);

        Role::create(['name' => 'viewer', 'guard_name' => $guard])->givePermissionTo([
            'customers.view',
            'loans.view',
            'loan-adjustments.view',
            'repayments.view',
            'reports.view',
        ]);
    }
}
