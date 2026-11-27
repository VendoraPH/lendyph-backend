<?php

namespace Tests\Feature\BrowserMoneyRound\Concerns;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Services\LoanService;
use App\Services\RepaymentService;

/**
 * Released loans for the browser-money round's contract tests.
 *
 * The default loan: ₱60,000 over 6 monthly periods at 3% straight, ₱10,000
 * principal and ₱1,800 interest a period (₱11,800 due each), ₱70,800 over the
 * schedule, 2% penalty, 3 days grace.
 */
trait BuildsReleasedLoans
{
    /**
     * @param  array<string, mixed>  $product  product overrides
     * @param  array<string, mixed>  $release  release payload (insurance)
     */
    protected function releasedLoanFor(
        ?Borrower $borrower = null,
        float $principal = 60000,
        ?string $startDate = null,
        array $product = [],
        array $release = [],
    ): Loan {
        $product = LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ], $product));

        $borrower ??= Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loans = app(LoanService::class);

        $loan = $loans->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => $startDate ?? now()->toDateString(),
        ], $this->admin);

        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin, $release);

        $loan = $loan->fresh('amortizationSchedules');
        $this->assertContains($loan->status, Loan::ACTIVE_STATUSES, 'setup: the loan is released');

        return $loan;
    }

    protected function payLoan(Loan $loan, float $amount, ?string $date = null): Repayment
    {
        return app(RepaymentService::class)->processRepayment(
            $loan->fresh(),
            $amount,
            $date ?? now()->toDateString(),
            $this->admin,
        );
    }

    /** @return array<string, mixed> */
    protected function summaryOf(Loan $loan): array
    {
        return $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');
    }
}
