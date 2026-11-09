<?php

use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * Follow-up round 3, audit wording (display and new rows only; stored rows
 * never change):
 * - a new upload row names its parent in words: "Member #7", "Co-maker #3",
 *   or the loan by its number;
 * - a statement line target is named by its own date and amount, so the
 *   detail never reads "Accounting reconciliation line #1" twice.
 */

beforeEach(function () {
    $this->seedAndLogin();
    Storage::fake('private');
});

function latestUploadRow(): AuditLog
{
    return AuditLog::query()->where('action', 'uploaded')->latest('id')->firstOrFail();
}

it('names the member, co-maker or loan in words on a new upload row', function () {
    $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $this->postJson("/api/borrowers/{$member->id}/documents", ['file' => UploadedFile::fake()->create('payslip.pdf', 10, 'application/pdf'), 'type' => 'payslip'])->assertCreated();
    expect(latestUploadRow()->description)->toBe("Document 'payslip' uploaded for Member #{$member->id}");

    $coMaker = CoMaker::factory()->create(['borrower_id' => $member->id]);
    $this->postJson("/api/co-makers/{$coMaker->id}/documents", ['file' => UploadedFile::fake()->create('payslip.pdf', 10, 'application/pdf'), 'type' => 'payslip'])->assertCreated();
    expect(latestUploadRow()->description)->toBe("Document 'payslip' uploaded for Co-maker #{$coMaker->id}");

    $loan = $this->createReleasedLoan();
    $this->postJson("/api/loans/{$loan->id}/documents", ['file' => UploadedFile::fake()->create('letter.pdf', 10, 'application/pdf'), 'type' => 'policy_exception_letter'])->assertCreated();
    expect(latestUploadRow()->description)->toBe("Document 'policy_exception_letter' uploaded for loan {$loan->fresh()->loan_account_number}");
});

it('leaves a stored upload row as it was written', function () {
    $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $row = AuditLog::create([
        'action' => 'uploaded',
        'user_id' => $this->admin->id,
        'auditable_type' => Borrower::class,
        'auditable_id' => $member->id,
        'description' => "Document 'payslip' uploaded for borrower #{$member->id}",
    ]);

    $this->getJson("/api/audit-logs/{$row->id}")->assertOk()
        ->assertJsonPath('data.description', "Document 'payslip' uploaded for borrower #{$member->id}");
});

it('names a statement line target by its date and amount, never by its class and id again', function (int $centavos, string $amount) {
    app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
    $reconciliation = AccountingReconciliation::factory()->create();
    $line = $reconciliation->lines()->create([
        'date' => '2026-09-03',
        'description' => 'Deposit',
        'amount' => $centavos,
    ]);
    $row = AuditLog::query()
        ->where('auditable_type', AccountingReconciliationLine::class)
        ->where('auditable_id', $line->id)
        ->where('action', 'created')
        ->sole();

    $target = $this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.target');

    expect($target)->toBe([
        'id' => $line->id,
        'type' => 'AccountingReconciliationLine',
        'label' => "Statement line, Sep 3, 2026, {$amount}",
    ]);
})->with([
    'money in' => [150000, "\u{20B1}1,500.00"],
    'money out' => [-40050, "-\u{20B1}400.50"],
]);

it('still names a statement line that no longer exists by its class in words', function () {
    $row = AuditLog::create([
        'action' => 'deleted',
        'user_id' => $this->admin->id,
        'auditable_type' => AccountingReconciliationLine::class,
        'auditable_id' => 999991,
    ]);

    $this->getJson("/api/audit-logs/{$row->id}")->assertOk()
        ->assertJsonPath('data.target.label', 'Accounting reconciliation line #999991');
});
