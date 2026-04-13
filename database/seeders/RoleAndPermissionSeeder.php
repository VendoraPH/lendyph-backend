<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Dashboard
            'dashboard:view',

            // User Management
            'users:view', 'users:create', 'users:update', 'users:delete', 'users:reset_password',

            // Borrowers (renamed from customers)
            'borrowers:view', 'borrowers:create', 'borrowers:update', 'borrowers:delete',

            // Loans
            'loans:view', 'loans:create', 'loans:update', 'loans:delete',
            'loans:approve', 'loans:reject', 'loans:release', 'loans:void',

            // Payments (renamed from repayments)
            'payments:view', 'payments:create', 'payments:update', 'payments:void',

            // Loan Adjustments
            'loan_adjustments:view', 'loan_adjustments:create', 'loan_adjustments:approve',

            // Reports
            'reports:view', 'reports:export',

            // Audit Logs
            'audit_logs:view', 'audit_logs:export',

            // Fees
            'fees:view', 'fees:create', 'fees:update', 'fees:delete',

            // Share Capital
            'share_capital:view', 'share_capital:create', 'share_capital:update',
            'auto_credit:process',

            // Collections
            'collections:view', 'collections:mark_collected',

            // Settings
            'settings:view', 'settings:update',
        ];

        $guard = 'web';

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        // Default metadata for seeded system roles — flags them so the admin UI
        // knows they cannot be deleted or renamed.
        $systemRoleAttrs = [
            'admin' => ['description' => 'Full system access — all permissions granted via Gate::before.'],
            'loan_officer' => ['description' => 'Creates and processes loan applications; manages borrowers and share capital.'],
            'cashier' => ['description' => 'Records payments, releases approved loans, and reconciles cash.'],
            'collector' => ['description' => 'Collects payments on the field and marks collection status.'],
            'viewer' => ['description' => 'Read-only access to operational data and audit logs.'],
            'general_bookkeeper' => ['description' => 'Releases loans after BOD approval in the normal workflow.'],
        ];

        // Admin — gets all permissions via Gate::before bypass
        Role::updateOrCreate(
            ['name' => 'admin', 'guard_name' => $guard],
            ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs['admin']['description']],
        );

        Role::updateOrCreate(
            ['name' => 'loan_officer', 'guard_name' => $guard],
            ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs['loan_officer']['description']],
        )->syncPermissions([
            'dashboard:view',
            'borrowers:view', 'borrowers:create', 'borrowers:update',
            'loans:view', 'loans:create', 'loans:update',
            'loans:approve', 'loans:reject', 'loans:release',
            'loan_adjustments:view', 'loan_adjustments:create',
            'payments:view',
            'collections:view',
            'reports:view', 'reports:export',
            'share_capital:view', 'share_capital:create', 'share_capital:update',
            'auto_credit:process',
            'fees:view', 'fees:create', 'fees:update', 'fees:delete',
        ]);

        Role::updateOrCreate(
            ['name' => 'cashier', 'guard_name' => $guard],
            ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs['cashier']['description']],
        )->syncPermissions([
            'dashboard:view',
            'borrowers:view',
            'loans:view', 'loans:release',
            'payments:view', 'payments:create', 'payments:update', 'payments:void',
            'reports:view',
            'fees:view',
            'share_capital:view',
        ]);

        Role::updateOrCreate(
            ['name' => 'collector', 'guard_name' => $guard],
            ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs['collector']['description']],
        )->syncPermissions([
            'dashboard:view',
            'borrowers:view',
            'loans:view',
            'collections:view', 'collections:mark_collected',
            'payments:view', 'payments:create',
            'reports:view',
            'fees:view',
            'share_capital:view',
        ]);

        Role::updateOrCreate(
            ['name' => 'viewer', 'guard_name' => $guard],
            ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs['viewer']['description']],
        )->syncPermissions([
            'dashboard:view',
            'borrowers:view',
            'loans:view',
            'loan_adjustments:view',
            'payments:view',
            'reports:view',
            'fees:view',
            'share_capital:view',
            'audit_logs:view',
        ]);

        Role::updateOrCreate(
            ['name' => 'general_bookkeeper', 'guard_name' => $guard],
            ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs['general_bookkeeper']['description']],
        )->syncPermissions([
            'dashboard:view',
            'borrowers:view',
            'loans:view', 'loans:release',
            'payments:view',
            'reports:view',
            'fees:view',
            'share_capital:view',
        ]);
    }
}
