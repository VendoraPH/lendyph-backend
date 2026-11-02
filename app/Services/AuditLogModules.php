<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingPeriod;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Models\AmortizationSchedule;
use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\CoMaker;
use App\Models\CsvImportRun;
use App\Models\Document;
use App\Models\Fee;
use App\Models\GCashNonMember;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanApprovalStep;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\RepaymentAllocation;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Audit Trail module an audit row belongs to, in one place, so the
 * `module` each row carries (AuditLogResource) and the list's `module` filter
 * (AuditLogController) can never disagree.
 *
 * - Self-service auth events (login, logout, profile and password changes)
 *   are `auth`, whatever their auditable type: a login row carries the User.
 * - The GCash "tiers replaced" row, which has no auditable class, is `gcash`.
 * - Otherwise the auditable class decides (MODULE_MAP). Every class that
 *   writes audit rows is listed there, `system` ones included, and
 *   AuditLogModuleMapTest keeps it that way.
 * - Any other row with no auditable class is `system`.
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
     * The description GCashService writes on its "tiers replaced" row. That
     * row has no auditable record (it replaces the whole tier table) and the
     * generic `updated` action, so this fixed text is what files it under
     * `gcash`. The writer uses this constant, so the two cannot drift.
     */
    public const GCASH_TIERS_REPLACED = 'GCash tiers replaced';

    /**
     * Auditable class => module, by full class name: stored types are full
     * class names (there is no morph map), and the label and the filter both
     * compare them exactly.
     */
    private const MODULE_MAP = [
        Borrower::class => 'borrowers',
        CoMaker::class => 'borrowers',
        Document::class => 'borrowers',
        Loan::class => 'loans',
        LoanProduct::class => 'loans',
        LoanAdjustment::class => 'loans',
        LoanApprovalStep::class => 'loans',
        ApprovalWorkflowSetting::class => 'loans',
        AmortizationSchedule::class => 'loans',
        LoanLedgerEntry::class => 'loans',
        Collateral::class => 'loans',
        CollateralType::class => 'loans',
        Fee::class => 'loans',
        Repayment::class => 'payments',
        RepaymentAllocation::class => 'payments',
        User::class => 'users',
        Branch::class => 'users',
        Role::class => 'roles',
        ShareCapitalPledge::class => 'collections',
        ShareCapitalLedger::class => 'collections',
        AccountingAccount::class => 'accounting',
        AccountingAccountMapping::class => 'accounting',
        AccountingExpense::class => 'accounting',
        AccountingExpensePayment::class => 'accounting',
        AccountingJournal::class => 'accounting',
        AccountingJournalLine::class => 'accounting',
        AccountingPeriod::class => 'accounting',
        AccountingReconciliation::class => 'accounting',
        AccountingReconciliationLine::class => 'accounting',
        GCashTransaction::class => 'gcash',
        GCashNonMember::class => 'gcash',
        GCashTier::class => 'gcash',
        // Deliberately system: an import run is housekeeping, not a record
        // any module's screen shows.
        CsvImportRun::class => self::UNMAPPED,
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

    public static function for(?string $action, ?string $auditableType, ?string $description = null): string
    {
        if (in_array($action, self::AUTH_ACTIONS, true)) {
            return self::AUTH;
        }

        if (self::isGCashTiersRow($action, $auditableType, $description)) {
            return 'gcash';
        }

        return $auditableType !== null && isset(self::MODULE_MAP[$auditableType])
            ? self::MODULE_MAP[$auditableType]
            : self::UNMAPPED;
    }

    /**
     * Whether the class is filed under a module on purpose (`system`
     * included), rather than falling through to `system` by omission.
     */
    public static function isMapped(string $auditableType): bool
    {
        return isset(self::MODULE_MAP[$auditableType]);
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

        $types = array_keys(array_filter(self::MODULE_MAP, fn (string $mapped) => $mapped === $module));

        if ($module === 'gcash') {
            $query->where(fn (Builder $q) => $q
                ->whereIn('auditable_type', $types)
                ->orWhere(fn (Builder $q) => self::whereGCashTiersRow($q)));

            return;
        }

        if ($module === self::UNMAPPED) {
            $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q
                    ->whereNull('auditable_type')
                    ->where(fn (Builder $q) => $q
                        ->where('action', '!=', 'updated')
                        ->orWhereNull('description')
                        ->orWhere('description', 'not like', self::GCASH_TIERS_REPLACED.'%')))
                ->orWhereIn('auditable_type', $types)
                ->orWhereNotIn('auditable_type', array_keys(self::MODULE_MAP)));

            return;
        }

        $query->whereIn('auditable_type', $types);
    }

    private static function isGCashTiersRow(?string $action, ?string $auditableType, ?string $description): bool
    {
        return $auditableType === null
            && $action === 'updated'
            && $description !== null
            && str_starts_with($description, self::GCASH_TIERS_REPLACED);
    }

    /**
     * @param  Builder<AuditLog>  $query
     */
    private static function whereGCashTiersRow(Builder $query): void
    {
        $query->whereNull('auditable_type')
            ->where('action', 'updated')
            ->where('description', 'like', self::GCASH_TIERS_REPLACED.'%');
    }
}
