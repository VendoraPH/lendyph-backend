<?php

use App\Http\Resources\AuditLogResource;
use App\Models\AccountingJournal;
use App\Models\AuditLog;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * Phase 3 follow-ups, item B1. A target with nothing to name it by reads its
 * kind in the Audit Trail screen's words ("Journal entry #2"), so the screen's
 * "Created: <record>" description and the target heading say the same thing.
 * Stored rows never change.
 */

beforeEach(function () {
    $this->seedAndLogin();
});

it('names each kind the way the Audit Trail screen does', function (string $class, string $words) {
    expect(AuditLogResource::typeInWords($class))->toBe($words);
})->with([
    ['AccountingJournal', 'Journal entry'],
    ['AccountingJournalLine', 'Journal entry line'],
    ['AccountingAccount', 'Account'],
    ['AccountingAccountMapping', 'Account mapping'],
    ['AccountingAllowancePosting', 'Allowance for credit losses posting'],
    ['AccountingExpense', 'Expense'],
    ['AccountingExpensePayment', 'Expense payment'],
    ['AccountingOpeningBalance', 'Opening balance'],
    ['AccountingYearClose', 'Year-end close'],
    ['GCashNonMember', 'GCash walk-in'],
    ['CsvImportRun', 'Data import'],
    ['SavingsApproval', 'Savings approval request'],
    ['SavingsSetting', 'Savings settings'],
    ['ReminderSetting', 'Reminder settings'],
    ['ReminderBranchSetting', 'Branch reminder settings'],
    ['BorrowerNotificationPreference', 'Member reminder preferences'],
    ['CdaSetting', 'CDA settings'],
    ['CdaReportValue', 'CDA report figure'],
    ['CdaReportSnapshot', 'CDA report version'],
    ['CdaSubmission', 'CDA filing'],
    ['CdaOfficerTraining', 'Officer training'],
    ['CdaSocialProgram', 'Social program'],
    ['CdaMediationCase', 'Mediation case'],
    ['CdaNoticeSent', 'CDA notice'],
    ['PortalUser', 'Portal account'],
    ['PortalCode', 'Portal code'],
    ['PortalSetting', 'Member Portal settings'],
    ['PortalTermsVersion', 'Portal terms'],
    ['PortalRequest', 'Portal request'],
    ['PortalAnnouncement', 'Portal announcement'],
]);

it('keeps the class-in-words rule for every other kind', function () {
    expect(AuditLogResource::typeInWords('GCashTransaction'))->toBe('GCash transaction')
        ->and(AuditLogResource::typeInWords('BorrowerDocument'))->toBe('Member document')
        ->and(AuditLogResource::typeInWords('CoMaker'))->toBe('Co-maker')
        ->and(AuditLogResource::typeInWords('CdaDocument'))->toBe('CDA document')
        ->and(AuditLogResource::typeInWords('AccountingReconciliation'))->toBe('Accounting reconciliation');
});

it('labels a created journal with no number or reference as a journal entry, and keeps the stored row', function () {
    $journal = AccountingJournal::create([
        'date' => now()->toDateString(),
        'source' => 'loan_collection',
        'description' => 'No reference',
    ]);
    $row = AuditLog::query()
        ->where('action', 'created')
        ->where('auditable_type', $journal->getMorphClass())
        ->where('auditable_id', $journal->id)
        ->sole();

    $this->getJson("/api/audit-logs/{$row->id}")->assertOk()
        ->assertJsonPath('data.target.label', "Journal entry #{$journal->id}")
        ->assertJsonPath('data.target.type', 'AccountingJournal')
        ->assertJsonPath('data.description', $row->description);

    expect($row->fresh()->auditable_type)->toBe($journal->getMorphClass());
});

it('reads a walk-in target in the CSV export as GCash walk-in, never non-member', function () {
    $row = AuditLog::create([
        'action' => 'updated',
        'user_id' => $this->admin->id,
        'auditable_type' => 'App\\Models\\GCashNonMember',
        'auditable_id' => 999_001,
    ]);

    $csv = $this->get('/api/audit-logs/export')->assertOk()->streamedContent();

    expect($csv)->toContain('GCash walk-in')
        ->and(strtolower($csv))->not->toContain('non member');

    $this->getJson("/api/audit-logs/{$row->id}")->assertOk()
        ->assertJsonPath('data.target.label', 'GCash walk-in #999001');
});
