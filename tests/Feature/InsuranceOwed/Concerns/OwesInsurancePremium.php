<?php

namespace Tests\Feature\InsuranceOwed\Concerns;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Services\LoanService;
use App\Services\RepaymentService;

/**
 * A loan whose release collected only part of the insurance premium.
 *
 * ₱60,000 over 6 monthly periods at 3% straight: ₱10,000 principal and
 * ₱1,800 interest each period, ₱70,800 over the schedule. A 1% premium is
 * ₱600; the release collects ₱200 of it and leaves ₱400 owed on the loan
 * (`loans.insurance_remaining_balance`).
 *
 * Standing decision: the unpaid part is a separate, interest-free amount owed
 * on the loan, collected first, never added to principal.
 */
trait OwesInsurancePremium
{
    protected const PREMIUM = 600.00;

    protected const COLLECTED_AT_RELEASE = 200.00;

    protected const OWED = 400.00;

    protected const PERIOD_DUE = 11800.00;

    protected const SCHEDULE_TOTAL = 70800.00;

    /**
     * @param  array<string, mixed>  $product  product overrides
     */
    protected function loanOwingPremium(array $product = [], ?string $startDate = null): Loan
    {
        $product = LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ], $product));

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loans = app(LoanService::class);

        $loan = $loans->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => $startDate ?? now()->toDateString(),
        ], $this->admin);

        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin, [
            'insurance_premium_percentage' => 1.0,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => self::COLLECTED_AT_RELEASE,
        ]);

        $loan = $loan->fresh('amortizationSchedules');

        // The setup itself, so a failure below is the contract's, not this helper's.
        $this->assertSame('released', $loan->status);
        $this->assertEqualsWithDelta(self::PREMIUM, (float) $loan->insurance_premium_amount, 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $loan->insurance_remaining_balance, 0.001);

        return $loan;
    }

    protected function pay(Loan $loan, float $amount, ?string $date = null): Repayment
    {
        return app(RepaymentService::class)->processRepayment(
            $loan->fresh(),
            $amount,
            $date ?? now()->toDateString(),
            $this->admin,
        );
    }

    protected function owed(Loan $loan): float
    {
        return (float) $loan->fresh()->insurance_remaining_balance;
    }
}
