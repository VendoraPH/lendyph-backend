<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Renames role/permission strings to colon-format with frontend module names,
     * and changes user status enum from 'deactivated' to 'inactive'.
     */
    public function up(): void
    {
        // 1. Update existing user status data first (before altering enum)
        DB::table('users')->where('status', 'deactivated')->update(['status' => 'inactive']);

        // 2. Alter users.status enum
        DB::statement("ALTER TABLE users MODIFY status ENUM('active', 'inactive') NOT NULL DEFAULT 'active'");

        // 3. Rename role
        DB::table('roles')->where('name', 'loan-officer')->update(['name' => 'loan_officer']);

        // 4. Rename permissions — old → new
        $permissionMap = [
            // Dashboard
            'dashboard.view' => 'dashboard:view',

            // Users
            'users.view' => 'users:view',
            'users.create' => 'users:create',
            'users.update' => 'users:update',
            'users.deactivate' => 'users:delete',
            'users.reset-password' => 'users:reset_password',

            // Customers → Borrowers
            'customers.view' => 'borrowers:view',
            'customers.create' => 'borrowers:create',
            'customers.update' => 'borrowers:update',
            'customers.delete' => 'borrowers:delete',

            // Loans
            'loans.view' => 'loans:view',
            'loans.create' => 'loans:create',
            'loans.process' => 'loans:update',
            'loans.approve' => 'loans:approve',
            'loans.release' => 'loans:release',
            'loans.void' => 'loans:void',

            // Repayments → Payments
            'repayments.view' => 'payments:view',
            'repayments.create' => 'payments:create',
            'repayments.void' => 'payments:void',

            // Loan Adjustments
            'loan-adjustments.view' => 'loan_adjustments:view',
            'loan-adjustments.create' => 'loan_adjustments:create',
            'loan-adjustments.approve' => 'loan_adjustments:approve',

            // Reports
            'reports.view' => 'reports:view',
            'reports.export' => 'reports:export',

            // Audit Logs
            'audit-logs.view' => 'audit_logs:view',

            // Fees
            'fees.view' => 'fees:view',
            'fees.create' => 'fees:create',
            'fees.update' => 'fees:update',
            'fees.delete' => 'fees:delete',

            // Share Capital
            'share-capital.view' => 'share_capital:view',
            'share-capital.create' => 'share_capital:create',
            'share-capital.update' => 'share_capital:update',
            'auto-credit.process' => 'auto_credit:process',
        ];

        foreach ($permissionMap as $old => $new) {
            DB::table('permissions')->where('name', $old)->update(['name' => $new]);
        }

        // 5. Insert new permissions that didn't exist before (from frontend)
        $newPermissions = [
            'loans:delete', 'loans:reject',
            'audit_logs:export',
            'collections:view', 'collections:mark_collected',
            'settings:view', 'settings:update',
        ];

        foreach ($newPermissions as $permission) {
            $exists = DB::table('permissions')->where('name', $permission)->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'name' => $permission,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 6. Clear Spatie permission cache
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse status enum
        DB::table('users')->where('status', 'inactive')->update(['status' => 'deactivated']);
        DB::statement("ALTER TABLE users MODIFY status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active'");

        // Reverse role rename
        DB::table('roles')->where('name', 'loan_officer')->update(['name' => 'loan-officer']);

        // Reverse permission renames
        $permissionMap = [
            'dashboard:view' => 'dashboard.view',
            'users:view' => 'users.view',
            'users:create' => 'users.create',
            'users:update' => 'users.update',
            'users:delete' => 'users.deactivate',
            'users:reset_password' => 'users.reset-password',
            'borrowers:view' => 'customers.view',
            'borrowers:create' => 'customers.create',
            'borrowers:update' => 'customers.update',
            'borrowers:delete' => 'customers.delete',
            'loans:view' => 'loans.view',
            'loans:create' => 'loans.create',
            'loans:update' => 'loans.process',
            'loans:approve' => 'loans.approve',
            'loans:release' => 'loans.release',
            'loans:void' => 'loans.void',
            'payments:view' => 'repayments.view',
            'payments:create' => 'repayments.create',
            'payments:void' => 'repayments.void',
            'loan_adjustments:view' => 'loan-adjustments.view',
            'loan_adjustments:create' => 'loan-adjustments.create',
            'loan_adjustments:approve' => 'loan-adjustments.approve',
            'reports:view' => 'reports.view',
            'reports:export' => 'reports.export',
            'audit_logs:view' => 'audit-logs.view',
            'fees:view' => 'fees.view',
            'fees:create' => 'fees.create',
            'fees:update' => 'fees.update',
            'fees:delete' => 'fees.delete',
            'share_capital:view' => 'share-capital.view',
            'share_capital:create' => 'share-capital.create',
            'share_capital:update' => 'share-capital.update',
            'auto_credit:process' => 'auto-credit.process',
        ];

        foreach ($permissionMap as $new => $old) {
            DB::table('permissions')->where('name', $new)->update(['name' => $old]);
        }

        // Remove new permissions added in up()
        DB::table('permissions')->whereIn('name', [
            'loans:delete', 'loans:reject',
            'audit_logs:export',
            'collections:view', 'collections:mark_collected',
            'settings:view', 'settings:update',
        ])->delete();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
