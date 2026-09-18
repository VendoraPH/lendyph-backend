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
            'borrowers:approve',

            // Loans
            'loans:view', 'loans:create', 'loans:update', 'loans:delete',
            'loans:approve', 'loans:reject', 'loans:release', 'loans:void',
            'loans:extend', 'loans:restructure', 'loans:write_off',

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

            // Auto-Pay (CBS bulk loan deductions)
            'auto_pay:view', 'auto_pay:process', 'auto_pay:toggle',

            // GCash Transactions
            'gcash:view', 'gcash:transact', 'gcash:settings',

            // Collections
            'collections:view', 'collections:mark_collected',

            // Settings
            'settings:view', 'settings:update', 'settings:delete',

            // CSV migration importer — bulk-creating borrowers and loans from a
            // legacy system's export. Held by admins only; see
            // 2026_08_29_100005_add_imports_process_permission.
            //
            // Listed HERE as well as in that migration on purpose. The migration
            // is what grants it on staging and production, which are already
            // migrated and will never re-run a seeder; this list is what a fresh
            // database and the whole test suite build from. Either one alone is
            // a half-measure — and the `collaterals:*` permissions below are
            // already living proof, granted by a migration and missing from this
            // list, so `Permission::all()` on a freshly seeded box depends on
            // that migration having run first.
            'imports:process',

            // Accounting. Listed here for the same reason `imports:process` is:
            // 2026_09_16_200002_add_accounting_permissions grants these on
            // staging and production, which are already migrated and will never
            // re-run a seeder, while this list is what a fresh database and the
            // whole test suite build from. Either one alone is a half-measure.
            'accounting:view', 'accounting:reconcile', 'accounting:close', 'accounting:settings',
            'chart_of_accounts:view', 'chart_of_accounts:create',
            'chart_of_accounts:update', 'chart_of_accounts:delete',
            'journals:view', 'journals:create', 'journals:post', 'journals:reverse',
            'expenses:view', 'expenses:create', 'expenses:update',
            // Settling a payable — taking money OUT of a cash account against a
            // liability. Its own permission rather than part of
            // `expenses:update` for the same reason `journals:post` is separate
            // from `journals:create`: recording what is owed and paying it are
            // not the same act, and nobody should do both to their own
            // paperwork. Granted to super_admin and admin only; see
            // 2026_09_18_210002_add_expense_payment_permission, which is what
            // grants it on the already-migrated deployments.
            'expenses:pay',
            'cash_accounts:view', 'cash_accounts:transfer',
        ];

        $guard = 'web';

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        // Default metadata for seeded system roles — flags them so the admin UI
        // knows they cannot be deleted or renamed.
        $systemRoleAttrs = [
            'super_admin' => ['description' => 'Developer-side super administrator — every permission plus Gate::before bypass. Reserved for the platform team, not client staff.'],
            'admin' => ['description' => 'Client-side administrator — every operational permission for the lending organization (borrowers, loans, payments, staff, reports, settings).'],
            'loan_officer' => ['description' => 'Creates and processes loan applications; manages borrowers and share capital.'],
            'cashier' => ['description' => 'Records payments, releases approved loans, and reconciles cash.'],
            'collector' => ['description' => 'Collects payments on the field and marks collection status.'],
            'viewer' => ['description' => 'Read-only access to operational data.'],
            'general_bookkeeper' => ['description' => 'Releases loans after BOD approval in the normal workflow.'],

            // Approval-chain roles. ApprovalWorkflowSetting's default chains
            // have always named these, but until 2026-09-16 none of them
            // existed as a role — nine of the ten policy-exception steps
            // resolved to a role no user could hold.
            //
            // Listed HERE as well as in 2026_09_16_110001_add_approval_chain_roles
            // on purpose, per the convention noted on `imports:process` above:
            // the migration grants them on staging and production, which are
            // already migrated and never re-run a seeder; this list is what a
            // fresh database and the whole test suite build from.
            'loan_processor' => ['description' => 'Prepares loan applications and submits them into the approval chain; receives loans sent back for revision.'],
            'manager' => ['description' => 'Reviews submitted loans before they reach the Board of Directors.'],
            'bod1' => ['description' => 'Board of Directors member 1 (BOD Chairwoman in the normal chain) — signs off on loans in the approval chain.'],
            'bod2' => ['description' => 'Board of Directors member 2 — signs off on loans in the policy-exception approval chain.'],
            'bod3' => ['description' => 'Board of Directors member 3 — signs off on loans in the policy-exception approval chain.'],
            'bod4' => ['description' => 'Board of Directors member 4 — signs off on loans in the policy-exception approval chain.'],
            'bod5' => ['description' => 'Board of Directors member 5 — signs off on loans in the policy-exception approval chain.'],
            'bod6' => ['description' => 'Board of Directors member 6 — signs off on loans in the policy-exception approval chain.'],
            'bod7' => ['description' => 'Board of Directors member 7 — signs off on loans in the policy-exception approval chain.'],
        ];

        /**
         * The approval-chain roles get `loans:view` and nothing else.
         *
         * A chain role's authority to sign a step comes from HOLDING the role
         * named on that step, not from a permission — LoanApprovalChainService
         * checks the role directly. Granting `loans:approve` here would hand
         * every BOD member the single-shot PATCH /loans/{id}/approve and let
         * them bypass the chain entirely.
         *
         * Derived from the descriptions above rather than listed a second time,
         * so adding `bod8` in one place cannot leave it uncreated in the other.
         */
        $approvalChainRoles = array_values(array_filter(
            array_keys($systemRoleAttrs),
            static fn (string $role) => $role === 'loan_processor'
                || $role === 'manager'
                || str_starts_with($role, 'bod'),
        ));

        // Super admin — developer-side role with every permission assigned
        // directly (so frontend permission gating renders the full app) plus
        // the Gate::before bypass in AppServiceProvider as defence-in-depth.
        Role::updateOrCreate(
            ['name' => 'super_admin', 'guard_name' => $guard],
            ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs['super_admin']['description']],
        )->syncPermissions(Permission::all());

        // Admin — client-side full-access role for the lending organization's
        // admin staff. Same permission set as super_admin today, but kept as
        // a distinct role so platform-only future perms can be added to
        // super_admin without auto-granting them to client admins.
        Role::updateOrCreate(
            ['name' => 'admin', 'guard_name' => $guard],
            ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs['admin']['description']],
        )->syncPermissions(Permission::all());

        Role::updateOrCreate(
            ['name' => 'loan_officer', 'guard_name' => $guard],
            ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs['loan_officer']['description']],
        )->syncPermissions([
            'dashboard:view',
            'borrowers:view', 'borrowers:create', 'borrowers:update', 'borrowers:approve',
            'loans:view', 'loans:create', 'loans:update',
            'loans:approve', 'loans:reject', 'loans:release',
            // `loans:restructure` but deliberately NOT `loans:write_off`:
            // initiating a restructure is this role's job, destroying debt is not.
            'loans:extend', 'loans:restructure',
            'loan_adjustments:view', 'loan_adjustments:create',
            'payments:view',
            'collections:view',
            'reports:view', 'reports:export',
            'share_capital:view', 'share_capital:create', 'share_capital:update',
            'auto_credit:process',
            'auto_pay:view', 'auto_pay:toggle',
            'gcash:view',
            'fees:view', 'fees:create', 'fees:update', 'fees:delete',
            'collaterals:view', 'collaterals:create', 'collaterals:update',
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
            'auto_pay:view', 'auto_pay:process',
            'gcash:view', 'gcash:transact',
            'collaterals:view',
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
            'collaterals:view',
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
            // Pointedly WITHOUT `audit_logs:view`. The audit log quotes the
            // values that changed, so reading it reads the data it describes —
            // which is not what the lowest-privilege role is for. Audit access
            // stays with admin and the manager-level roles.
            // See 2026_09_18_200001_revoke_audit_logs_view_from_viewer, which
            // is what removes it on the deployments that are already migrated.
            'auto_pay:view',
            'gcash:view',
            'collaterals:view',
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
            'auto_pay:view',
            'collaterals:view',

            // Accounting, at bookkeeper level: drafts entries, records expenses
            // and reconciles accounts. Pointedly WITHOUT `journals:post`,
            // `journals:reverse` or `accounting:close`. Drafting is reversible
            // and nothing reaches the ledger until someone else posts it;
            // posting makes an entry immutable, reversing writes a second entry
            // against it, and closing locks a period. Preparer and approver
            // being different people is what this split makes real, and
            // granting any of the three here would quietly undo it.
            'accounting:view',
            'accounting:reconcile',
            'chart_of_accounts:view',
            'journals:view',
            'journals:create',
            'expenses:view',
            'expenses:create',
            'cash_accounts:view',
        ]);

        /**
         * The five READ-ONLY accounting permissions `manager` is granted by
         * 2026_09_16_200002 on the accounting branch. Mirrored here because
         * that migration existence-checks each role and `continue`s past a
         * missing one — the seeder is what a fresh database and the test suite
         * build from, so without this line a fresh box's `manager` would end up
         * with fewer permissions than a migrated one.
         *
         * `manager` ONLY. bod1-bod7 and loan_processor get no accounting access
         * at all, and the preparer/approver split verified on that branch —
         * `general_bookkeeper` deliberately holds no `journals:post`,
         * `journals:reverse` or `accounting:close` — must not be widened here.
         *
         * Filtered against the permissions that actually exist: these names are
         * added to the canonical list above by the accounting branch, and until
         * it merges they are absent, where syncPermissions() would throw
         * PermissionDoesNotExist and take the whole seeder down with it.
         */
        $managerAccountingPermissions = Permission::where('guard_name', $guard)
            ->whereIn('name', [
                'accounting:view',
                'chart_of_accounts:view',
                'journals:view',
                'expenses:view',
                'cash_accounts:view',
            ])
            ->pluck('name')
            ->all();

        foreach ($approvalChainRoles as $roleName) {
            Role::updateOrCreate(
                ['name' => $roleName, 'guard_name' => $guard],
                ['is_system' => true, 'is_active' => true, 'description' => $systemRoleAttrs[$roleName]['description']],
            )->syncPermissions(array_merge(
                ['loans:view'],
                // The first step of both default chains is `loan_processor`,
                // and LoanController@submit checks `loans:update` — so without
                // this the role named as the chain's starting point cannot
                // start one. Deliberately not `loans:approve`, and deliberately
                // not extended to the BOD roles: signing authority comes from
                // holding the role named on the step, not from a permission.
                $roleName === 'loan_processor' ? ['loans:update'] : [],
                $roleName === 'manager' ? $managerAccountingPermissions : [],
            ));
        }
    }
}
