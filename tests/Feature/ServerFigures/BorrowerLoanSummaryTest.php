<?php

namespace Tests\Feature\ServerFigures;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\User;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /borrowers/{borrower}/loan-summary: the Member Overview's totals, from
 * the server. The browser summed every loan the member has, so a draft, a
 * rejected or a voided application counted as money borrowed.
 */
class BorrowerLoanSummaryTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $member;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->product = LoanProduct::factory()->create([
            'interest_rate' => 3, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly',
            'processing_fee' => 0, 'service_fee' => 0,
        ]);
    }

    private function loan(float $principal, string $status): Loan
    {
        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => $this->member->id,
            'loan_product_id' => $this->product->id,
            'principal_amount' => $principal,
            'start_date' => '2026-06-01',
            'deductions' => [],
        ], $this->admin);

        if (in_array($status, Loan::EVER_RELEASED_STATUSES, true)) {
            $service->submitForReview($loan);
            $service->approve($loan, $this->admin, 'Approved for testing');
            $service->release($loan, $this->admin);
        }

        $loan->update(['status' => $status]);

        return $loan->fresh();
    }

    public function test_totals_count_released_money_only_and_outstanding_counts_collectible_loans_only(): void
    {
        $released = $this->loan(60000, 'released');
        $this->loan(20000, 'completed');
        $restructured = $this->loan(30000, 'restructured');
        $defaulted = $this->loan(10000, 'defaulted');

        foreach (['draft', 'for_review', 'approved', 'rejected', 'void'] as $status) {
            $this->loan(5000, $status);
        }

        app(RepaymentService::class)->processRepayment($released->fresh(), 5000, '2026-07-01', $this->admin);

        $summary = $this->getJson("/api/borrowers/{$this->member->id}/loan-summary")->assertOk()->json('data');

        // Released, ongoing, completed and defaulted principal. Not the
        // restructured loan: its successor carries the rolled-over balance.
        // Never draft, for_review, approved, rejected or void.
        $this->assertEquals(90000, $summary['total_borrowed']);

        $collectible = Loan::whereIn('id', [$released->id, $defaulted->id])->get();
        $this->assertEquals(
            round($collectible->sum(fn (Loan $loan): float => $loan->outstanding_balance), 2),
            $summary['total_outstanding'],
        );
        $this->assertNotEquals(
            round($collectible->sum(fn (Loan $loan): float => $loan->outstanding_balance) + $restructured->outstanding_balance, 2),
            $summary['total_outstanding'],
            'a restructured loan owes through its successor, not itself',
        );

        $this->assertEquals(
            round((float) Repayment::whereIn('loan_id', $this->member->loans()->pluck('id'))->where('status', 'posted')->sum('amount_paid'), 2),
            $summary['total_paid'],
        );
        $this->assertEquals(5000, $summary['total_paid']);

        // The payment moved the released loan on to ongoing.
        $this->assertSame([
            'released' => 0, 'ongoing' => 1, 'completed' => 1, 'defaulted' => 1, 'restructured' => 1,
        ], array_intersect_key($summary['counts'], array_flip(['released', 'ongoing', 'completed', 'defaulted', 'restructured'])));
        $this->assertSame(1, $summary['counts']['draft']);
        $this->assertSame(1, $summary['current_loans']);
        $this->assertSame(1, $summary['counts']['void']);
    }

    public function test_a_member_with_only_applications_has_borrowed_nothing(): void
    {
        $this->loan(5000, 'draft');
        $this->loan(7000, 'rejected');

        $summary = $this->getJson("/api/borrowers/{$this->member->id}/loan-summary")->assertOk()->json('data');

        $this->assertEquals(0, $summary['total_borrowed']);
        $this->assertEquals(0, $summary['total_outstanding']);
        $this->assertEquals(0, $summary['total_paid']);
    }

    public function test_a_voided_payment_is_left_out_of_total_paid(): void
    {
        $loan = $this->loan(60000, 'released');
        $payments = app(RepaymentService::class);

        $payments->processRepayment($loan->fresh(), 3000, '2026-07-01', $this->admin);
        $voided = $payments->processRepayment($loan->fresh(), 2000, '2026-07-02', $this->admin);
        $payments->voidRepayment($voided->fresh(), 'Entered twice', $this->admin);

        $this->assertEquals(3000, $this->getJson("/api/borrowers/{$this->member->id}/loan-summary")->assertOk()->json('data.total_paid'));
    }

    public function test_unpaid_insurance_owed_on_a_loan_counts_in_total_outstanding(): void
    {
        $loan = $this->loan(60000, 'released');
        $loan->update(['insurance_remaining_balance' => 1500.25]);

        $outstanding = $this->getJson("/api/borrowers/{$this->member->id}/loan-summary")->assertOk()->json('data.total_outstanding');

        $this->assertEquals(61500.25, $outstanding);
        $this->assertEquals($loan->fresh()->outstanding_balance, $outstanding);
    }

    public function test_a_user_without_borrowers_view_gets_403(): void
    {
        $this->actingAs(User::factory()->create(['branch_id' => $this->branch->id]));

        $this->getJson("/api/borrowers/{$this->member->id}/loan-summary")->assertForbidden();
    }
}
