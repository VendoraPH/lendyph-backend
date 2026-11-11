<?php

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\CsvImportRun;
use App\Models\GCashNonMember;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogModules;
use App\Traits\Auditable;
use Illuminate\Support\Str;

/*
 * Every class that writes audit rows is filed under a module on purpose, so
 * none drifts into `system` by omission. That is every model using the
 * Auditable trait, plus every class passed as the auditable of an explicit
 * AuditLogService::log() call or a raw audit_logs insert (listed below; add
 * to it when a new writer appears).
 */

/** @return list<class-string> */
function auditableTraitModels(): array
{
    return collect(glob(dirname(__DIR__, 2).'/app/Models/*.php'))
        ->map(fn (string $path) => 'App\\Models\\'.Str::beforeLast(basename($path), '.php'))
        ->filter(fn (string $class) => in_array(Auditable::class, class_uses_recursive($class), true))
        ->values()
        ->all();
}

/** @return list<class-string> */
function explicitAuditWriters(): array
{
    return [
        AccountingAccount::class,        // ReleaseDeductionAccountsBackfill
        AccountingAccountMapping::class, // ReleaseDeductionAccountsBackfill
        AccountingJournal::class,        // RecordOpeningBalances
        Borrower::class,                 // PruneAbandonedRegistrations, DocumentController
        CoMaker::class,                  // DocumentController (a document's owner)
        CsvImportRun::class,             // CsvImportMappingController, CsvImportProcessor
        GCashNonMember::class,           // GCashNonMemberController
        GCashTransaction::class,         // GCashService, GCashCashInPaidAtBackfill
        Loan::class,                     // LoanService, CollateralAttacher, migrations
        Role::class,                     // RolePermissionAudit
        User::class,                     // AuthController, UserController
    ];
}

it('finds the Auditable models', function () {
    expect(auditableTraitModels())->toContain(Loan::class, Role::class, User::class);
});

it('files every class that writes audit rows under a module on purpose', function () {
    $unmapped = collect([...auditableTraitModels(), ...explicitAuditWriters()])
        ->unique()
        ->reject(fn (string $class) => AuditLogModules::isMapped($class))
        ->values()
        ->all();

    expect($unmapped)->toBe([]);
});

it('only files classes under modules the filter accepts', function () {
    collect([...auditableTraitModels(), ...explicitAuditWriters()])
        ->each(fn (string $class) => expect(AuditLogModules::all())->toContain(AuditLogModules::for('updated', $class)));
});

it('files auth events under auth whatever their class', function () {
    foreach (AuditLogModules::AUTH_ACTIONS as $action) {
        expect(AuditLogModules::for($action, User::class))->toBe('auth')
            ->and(AuditLogModules::for($action, null))->toBe('auth');
    }
});

it('lists the module values', function () {
    expect(AuditLogModules::all())->toEqualCanonicalizing([
        'auth', 'borrowers', 'loans', 'payments', 'collections', 'users', 'roles', 'accounting', 'gcash',
        'reminders', 'credit_scoring', 'savings', 'cda', 'member_portal', 'system',
    ]);
});

it('files the planned Phase 2 models under their modules', function (string $class, string $module) {
    expect(AuditLogModules::isMapped($class))->toBeTrue()
        ->and(AuditLogModules::for('updated', $class))->toBe($module);
})->with([
    'ReminderSetting' => ['App\\Models\\ReminderSetting', 'reminders'],
    'ReminderBranchSetting' => ['App\\Models\\ReminderBranchSetting', 'reminders'],
    'ReminderRule' => ['App\\Models\\ReminderRule', 'reminders'],
    'ReminderTemplate' => ['App\\Models\\ReminderTemplate', 'reminders'],
    'ReminderPause' => ['App\\Models\\ReminderPause', 'reminders'],
    'BorrowerNotificationPreference' => ['App\\Models\\BorrowerNotificationPreference', 'reminders'],
    'CreditScoringSetting' => ['App\\Models\\CreditScoringSetting', 'credit_scoring'],
    'CreditScorecardRevision' => ['App\\Models\\CreditScorecardRevision', 'credit_scoring'],
    'CreditApplicationProfile' => ['App\\Models\\CreditApplicationProfile', 'credit_scoring'],
    'CreditDecision' => ['App\\Models\\CreditDecision', 'credit_scoring'],
    'AccountingYearClose' => ['App\\Models\\AccountingYearClose', 'accounting'],
    'AccountingAllowancePosting' => ['App\\Models\\AccountingAllowancePosting', 'accounting'],
    'AccountingOpeningBalance' => ['App\\Models\\AccountingOpeningBalance', 'accounting'],
    'SavingsAccount' => ['App\\Models\\SavingsAccount', 'savings'],
    'SavingsTransaction' => ['App\\Models\\SavingsTransaction', 'savings'],
    'TimeDeposit' => ['App\\Models\\TimeDeposit', 'savings'],
    'SavingsInterestRun' => ['App\\Models\\SavingsInterestRun', 'savings'],
]);

it('compares full class names, so a same-named class elsewhere is not mapped', function () {
    expect(AuditLogModules::for('updated', Role::class))->toBe('roles')
        ->and(AuditLogModules::for('updated', 'Spatie\\Permission\\Models\\Role'))->toBe('system')
        ->and(AuditLogModules::isMapped('Spatie\\Permission\\Models\\Role'))->toBeFalse();
});
