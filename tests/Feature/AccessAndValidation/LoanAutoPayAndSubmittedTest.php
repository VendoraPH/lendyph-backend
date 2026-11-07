<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\User;
use App\Services\LoanApprovalChainService;
use App\Services\LoanService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The loan resource's Auto-Pay fields and `submitted_at`, and how an auto-pay
 * payment reaches the books.
 */
class LoanAutoPayAndSubmittedTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enableAutoPay(Loan $loan): void
    {
        $this->patchJson("/api/loans/{$loan->id}/auto-pay", ['enabled' => true, 'cbs_reference' => 'CBS-2026-00001'])
            ->assertOk();
    }

    public function test_the_loan_sends_its_auto_pay_state_so_a_reload_stays_right(): void
    {
        $loan = $this->createReleasedLoan();

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.auto_pay_enabled', false)
            ->assertJsonPath('data.auto_pay_enabled_at', null)
            ->assertJsonPath('data.auto_pay_enabled_by', null)
            ->assertJsonPath('data.auto_pay_cbs_reference', null);

        $this->enableAutoPay($loan);

        $data = $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.auto_pay_enabled', true)
            ->assertJsonPath('data.auto_pay_enabled_by', $this->admin->id)
            ->assertJsonPath('data.auto_pay_cbs_reference', 'CBS-2026-00001')
            ->json('data');

        $this->assertNotNull($data['auto_pay_enabled_at']);

        $row = collect($this->getJson('/api/loans')->assertOk()->json('data'))->firstWhere('id', $loan->id);
        $this->assertTrue($row['auto_pay_enabled']);
    }

    public function test_the_cbs_reference_is_null_without_an_auto_pay_permission(): void
    {
        $loan = $this->createReleasedLoan();
        $this->enableAutoPay($loan);

        foreach (['manager' => null, 'cashier' => 'CBS-2026-00001', 'loan_officer' => 'CBS-2026-00001'] as $role => $expected) {
            $user = User::factory()->inBranches($this->branch)->create();
            $user->assignRole($role);
            $this->actingAs($user);

            $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

            $this->assertTrue($data['auto_pay_enabled'], $role);
            $this->assertArrayHasKey('auto_pay_cbs_reference', $data, $role);
            $this->assertSame($expected, $data['auto_pay_cbs_reference'], $role);
        }
    }

    public function test_submitted_at_is_when_the_loan_was_submitted_not_when_it_last_changed(): void
    {
        Carbon::setTestNow('2026-11-02 09:00:00');
        $loan = $this->draftLoan();

        $this->getJson("/api/loans/{$loan->id}")->assertOk()->assertJsonPath('data.submitted_at', null);

        Carbon::setTestNow('2026-11-03 10:30:00');
        app(LoanService::class)->submitForReview($loan, $this->admin);

        Carbon::setTestNow('2026-11-05 16:00:00');
        $loan->refresh()->update(['purpose' => 'Changed later']);

        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');
        $row = collect($this->getJson('/api/loans')->assertOk()->json('data'))->firstWhere('id', $loan->id);

        $this->assertTrue(Carbon::parse('2026-11-03 10:30:00')->equalTo(Carbon::parse($data['submitted_at'])));
        $this->assertTrue(Carbon::parse('2026-11-03 10:30:00')->equalTo(Carbon::parse($row['submitted_at'])));
        $this->assertFalse(Carbon::parse($data['updated_at'])->equalTo(Carbon::parse($data['submitted_at'])));
    }

    public function test_a_send_back_to_the_submit_step_keeps_the_first_submission(): void
    {
        Carbon::setTestNow('2026-11-03 10:30:00');
        $loan = $this->draftLoan();
        app(LoanService::class)->submitForReview($loan, $this->admin);

        Carbon::setTestNow('2026-11-04 11:00:00');
        $pending = $loan->approvalSteps()->where('status', LoanApprovalStep::STATUS_PENDING)->firstOrFail();
        app(LoanApprovalChainService::class)->sendBack($loan->refresh(), $pending, 0, $this->admin, 'Fix the purpose');

        Carbon::setTestNow('2026-11-05 12:00:00');
        $resubmit = $loan->approvalSteps()->where('status', LoanApprovalStep::STATUS_PENDING)->firstOrFail();
        app(LoanApprovalChainService::class)->approve($loan->refresh(), $resubmit, $this->admin);

        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertTrue(Carbon::parse('2026-11-03 10:30:00')->equalTo(Carbon::parse($data['submitted_at'])));
    }

    /**
     * Loans submitted before the approval chain existed have no steps. The
     * audit row their submit wrote is the record, read on the single-loan view.
     */
    public function test_a_loan_from_before_the_chain_reads_its_submission_from_the_audit_trail(): void
    {
        Carbon::setTestNow('2026-08-01 08:00:00');
        $loan = $this->draftLoan();

        Carbon::setTestNow('2026-08-02 09:15:00');
        $loan->update(['status' => 'for_review']);

        Carbon::setTestNow('2026-08-03 10:00:00');
        $loan->update(['purpose' => 'Changed later']);

        $this->assertSame(0, $loan->approvalSteps()->count());
        $this->assertSame(1, AuditLog::where('auditable_id', $loan->id)->where('auditable_type', Loan::class)->where('new_values->status', 'for_review')->count());

        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertTrue(Carbon::parse('2026-08-02 09:15:00')->equalTo(Carbon::parse($data['submitted_at'])));
    }

    public function test_a_loan_with_no_record_of_a_submission_answers_null(): void
    {
        $loan = Loan::factory()->create([
            'status' => 'for_review',
            'branch_id' => $this->branch->id,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id]),
            'loan_product_id' => LoanProduct::factory(),
            'created_by' => $this->admin->id,
        ]);

        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('submitted_at', $data);
        $this->assertNull($data['submitted_at']);
    }

    /**
     * What was checked for round fz: auto-pay is money arriving in the bank, so
     * its journal debits the mapped bank account (1040) through SettlementMethod,
     * never cash on hand (1010).
     */
    public function test_an_auto_pay_payment_debits_the_mapped_bank_account_not_cash(): void
    {
        $this->seedChartOfAccounts();

        $loan = $this->createReleasedLoan();
        $this->enableAutoPay($loan);
        $due = $loan->amortizationSchedules()->orderBy('period_number')->firstOrFail();

        $this->postJson('/api/auto-pay/process', [
            'date_from' => $due->due_date->toDateString(),
            'date_to' => $due->due_date->toDateString(),
        ])->assertCreated()->assertJsonPath('data.processed', 1);

        $repayment = Repayment::where('loan_id', $loan->id)->firstOrFail();
        $this->assertSame('auto_pay', $repayment->method);

        $journal = AccountingJournal::query()
            ->where('postable_type', $repayment->getMorphClass())
            ->where('postable_id', $repayment->id)
            ->where('source', 'loan_collection')
            ->firstOrFail();

        $received = (int) round((float) $repayment->amount_paid * 100);

        $this->assertGreaterThan(0, $received);
        $this->assertSame($received, (int) $journal->lines()->where('accounting_account_id', $this->account('1040'))->value('debit'));
        $this->assertSame(0, (int) $journal->lines()->where('accounting_account_id', $this->account('1010'))->value('debit'));
    }

    private function draftLoan(): Loan
    {
        $product = LoanProduct::factory()->create(['term' => 6, 'frequency' => 'monthly']);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return app(LoanService::class)->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
    }
}
