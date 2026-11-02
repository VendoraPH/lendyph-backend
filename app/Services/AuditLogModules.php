<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Audit Trail module an audit row belongs to, in one place, so the
 * `module` each row carries (AuditLogResource) and the list's `module` filter
 * (AuditLogController) can never disagree.
 *
 * - Self-service auth events (login, logout, profile and password changes)
 *   are `auth`, whatever their auditable type: a login row carries the User.
 * - Otherwise the auditable class decides (MODULE_MAP). Every class that
 *   writes audit rows is listed there, `system` ones included, and
 *   AuditLogModuleMapTest keeps it that way.
 * - A row with no auditable class is `system`.
 */
class AuditLogModules
{
    public const AUTH = 'auth';

    public const UNMAPPED = 'system';

    /**
     * The actions AuthController writes for the signed-in user's own session
     * and profile.
     */
    public const AUTH_ACTIONS = ['login', 'logout', 'password_changed', 'profile_updated'];

    /**
     * Auditable class basename => module. Stored types are full class names
     * under App\Models (there is no morph map).
     */
    private const MODULE_MAP = [
        'Borrower' => 'borrowers',
        'CoMaker' => 'borrowers',
        'Document' => 'borrowers',
        'Loan' => 'loans',
        'LoanProduct' => 'loans',
        'LoanAdjustment' => 'loans',
        'LoanApprovalStep' => 'loans',
        'ApprovalWorkflowSetting' => 'loans',
        'AmortizationSchedule' => 'loans',
        'LoanLedgerEntry' => 'loans',
        'Collateral' => 'loans',
        'CollateralType' => 'loans',
        'Fee' => 'loans',
        'Repayment' => 'payments',
        'RepaymentAllocation' => 'payments',
        'User' => 'users',
        'Branch' => 'users',
        'Role' => 'roles',
        'ShareCapitalPledge' => 'collections',
        'ShareCapitalLedger' => 'collections',
        'AccountingAccount' => 'accounting',
        'AccountingAccountMapping' => 'accounting',
        'AccountingExpense' => 'accounting',
        'AccountingExpensePayment' => 'accounting',
        'AccountingJournal' => 'accounting',
        'AccountingJournalLine' => 'accounting',
        'AccountingPeriod' => 'accounting',
        'AccountingReconciliation' => 'accounting',
        'AccountingReconciliationLine' => 'accounting',
        'GCashTransaction' => 'gcash',
        'GCashNonMember' => 'gcash',
        'GCashTier' => 'gcash',
        // Deliberately system: an import run is housekeeping, not a record
        // any module's screen shows.
        'CsvImportRun' => self::UNMAPPED,
    ];

    /**
     * Every module a row can carry, which is also every value the `module`
     * filter accepts.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique([...array_values(self::MODULE_MAP), self::AUTH, self::UNMAPPED]));
    }

    public static function for(?string $action, ?string $auditableType): string
    {
        if (in_array($action, self::AUTH_ACTIONS, true)) {
            return self::AUTH;
        }

        $basename = $auditableType ? class_basename($auditableType) : null;

        return $basename !== null && isset(self::MODULE_MAP[$basename])
            ? self::MODULE_MAP[$basename]
            : self::UNMAPPED;
    }

    /**
     * Whether the class is filed under a module on purpose (`system`
     * included), rather than falling through to `system` by omission.
     */
    public static function isMapped(string $auditableType): bool
    {
        return isset(self::MODULE_MAP[class_basename($auditableType)]);
    }

    public static function isAuthAction(?string $action): bool
    {
        return in_array($action, self::AUTH_ACTIONS, true);
    }

    /**
     * Narrow an audit_logs query to one module, by the same rules as for().
     *
     * @param  Builder<AuditLog>  $query
     */
    public static function constrain(Builder $query, string $module): void
    {
        if ($module === self::AUTH) {
            $query->whereIn('action', self::AUTH_ACTIONS);

            return;
        }

        $query->whereNotIn('action', self::AUTH_ACTIONS);

        $types = self::types(array_keys(array_filter(self::MODULE_MAP, fn (string $mapped) => $mapped === $module)));

        if ($module === self::UNMAPPED) {
            $query->where(fn (Builder $q) => $q
                ->whereNull('auditable_type')
                ->orWhereIn('auditable_type', $types)
                ->orWhereNotIn('auditable_type', self::types(array_keys(self::MODULE_MAP))));

            return;
        }

        $query->whereIn('auditable_type', $types);
    }

    /**
     * @param  list<string>  $basenames
     * @return list<string>
     */
    private static function types(array $basenames): array
    {
        return array_map(fn (string $basename) => 'App\\Models\\'.$basename, $basenames);
    }
}
