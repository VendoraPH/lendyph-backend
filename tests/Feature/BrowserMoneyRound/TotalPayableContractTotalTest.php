<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 15, decision 7(a). "Total Payable" is the amount still owed and never
 * includes the Share Capital Build-Up, which is a build-up of the member's
 * share capital, not debt.
 *
 * Before release there is nothing owed yet: GET /loans/{id} sends
 * `total_payable: null` and `contract_total`, the principal plus interest of
 * the schedule the pre-release amortization preview builds. After release
 * `total_payable` keeps its meaning and `contract_total` is the stored
 * schedule's principal plus interest.
 *
 * Today a draft loan's `total_payable` reads 0 (no periods yet), so the loan
 * page falls back to the preview's `total_payment`, which carries one SCB per
 * period: ₱71,400.00 before release and ₱70,800.00 after, on one loan.
 *
 * The loan: ₱60,000 over 6 monthly periods at 3% straight, ₱100 SCB a period.
 * Principal plus interest is ₱70,800.00; with SCB it would be ₱71,400.00.
 */
class TotalPayableContractTotalTest extends TestCase
{
    use SetupLendyPH;

    private const CONTRACT_TOTAL = 70800.00;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function draftLoanWithBuildUp(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $loan = app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'scb_amount' => 100,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $this->assertEqualsWithDelta(100.0, (float) $loan->scb_amount, 0.001, 'setup: the loan carries a build-up');

        return $loan;
    }

    private function release(Loan $loan): Loan
    {
        $loans = app(LoanService::class);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin);

        $loan = $loan->fresh();
        $this->assertContains($loan->status, Loan::ACTIVE_STATUSES, 'setup: the loan is released');

        return $loan;
    }

    /** @return array<string, mixed> */
    private function show(Loan $loan): array
    {
        return $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');
    }

    public function test_before_release_total_payable_is_null_and_the_contract_total_leaves_out_the_build_up(): void
    {
        $loan = $this->draftLoanWithBuildUp();

        $data = $this->show($loan);

        $this->assertArrayHasKey('total_payable', $data);
        $this->assertNull($data['total_payable'], 'nothing is owed before release');
        $this->assertEqualsWithDelta(self::CONTRACT_TOTAL, (float) ($data['contract_total'] ?? 0), 0.001, 'contract_total');

        // The same engine as the pre-release amortization preview.
        $totals = $this->getJson("/api/loans/{$loan->id}/amortization-preview")->assertOk()->json('totals');
        $this->assertEqualsWithDelta(71400.00, (float) $totals['total_payment'], 0.001, 'setup: the preview total carries SCB');
        $this->assertEqualsWithDelta(
            (float) $totals['principal_due'] + (float) $totals['interest_due'],
            (float) $data['contract_total'],
            0.001,
        );
    }

    public function test_an_approved_loan_still_has_no_total_payable(): void
    {
        $loan = $this->draftLoanWithBuildUp();
        $loans = app(LoanService::class);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        $data = $this->show($loan->fresh());

        $this->assertSame('approved', $data['status'], 'setup: approved, not released');
        $this->assertNull($data['total_payable']);
        $this->assertEqualsWithDelta(self::CONTRACT_TOTAL, (float) ($data['contract_total'] ?? 0), 0.001);
    }

    public function test_after_release_total_payable_is_what_is_owed_and_the_contract_total_is_the_stored_schedule(): void
    {
        $loan = $this->release($this->draftLoanWithBuildUp());

        $data = $this->show($loan);

        $this->assertEqualsWithDelta(self::CONTRACT_TOTAL, (float) $data['total_payable'], 0.001, 'total_payable leaves out SCB');
        $this->assertEqualsWithDelta(self::CONTRACT_TOTAL, (float) ($data['contract_total'] ?? 0), 0.001, 'contract_total');

        // A payment lowers what is owed, never the contract.
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => 11800,
            'payment_date' => now()->toDateString(),
        ])->assertSuccessful();

        $after = $this->show($loan);
        $this->assertEqualsWithDelta(self::CONTRACT_TOTAL - 11800.00, (float) $after['total_payable'], 0.001);
        $this->assertEqualsWithDelta(self::CONTRACT_TOTAL, (float) $after['contract_total'], 0.001);
    }

    public function test_a_list_row_before_release_has_no_total_payable(): void
    {
        $loan = $this->draftLoanWithBuildUp();

        $row = collect(
            $this->getJson("/api/loans?borrower_id={$loan->borrower_id}&per_page=100")->assertOk()->json('data'),
        )->firstWhere('id', $loan->id);

        $this->assertNotNull($row, 'setup: the loan is listed');
        $this->assertArrayHasKey('total_payable', $row);
        $this->assertNull($row['total_payable']);
        // Lists build no schedule per row: the pre-release contract total is
        // on GET /loans/{id} only.
        $this->assertArrayHasKey('contract_total', $row);
        $this->assertNull($row['contract_total']);
    }
}
