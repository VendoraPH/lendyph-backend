<?php

namespace Tests\Feature\Accounting\LoanAccounting;

use App\Models\Loan;
use App\Services\LoanListTotals;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * LoanListTotals::byLoan() gains `outstanding_principal`, the per-loan figure
 * for() already sums, and keeps its other two keys as they were.
 */
class LoanListTotalsByLoanTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_by_loan_outstanding_principal_equals_the_loan_endpoint(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString(), 'principal_amount' => 61234.57]);
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 7777.77,
            'method' => 'cash',
        ])->assertCreated();

        $figures = app(LoanListTotals::class)->byLoan([$loan->id])[$loan->id];
        $shown = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertSame(['total_outstanding', 'overdue_amount', 'outstanding_principal'], array_keys($figures));
        $this->assertGreaterThan(0, $figures['outstanding_principal']);
        $this->assertEquals($shown['outstanding_principal'], $figures['outstanding_principal']);
        $this->assertEquals($shown['total_outstanding'], $figures['total_outstanding']);
        $this->assertEquals($shown['overdue_amount'], $figures['overdue_amount']);
    }

    public function test_by_loan_outstanding_principal_is_what_for_sums(): void
    {
        $first = $this->createReleasedLoan(['principal_amount' => 10000.01]);
        $second = $this->createReleasedLoan(['principal_amount' => 20000.02]);

        $totals = app(LoanListTotals::class);
        $figures = $totals->byLoan([$first->id, $second->id]);
        $summed = $totals->for(Loan::query()->whereIn('loans.id', [$first->id, $second->id]));

        $this->assertEqualsWithDelta(
            $summed['outstanding_principal'],
            $figures[$first->id]['outstanding_principal'] + $figures[$second->id]['outstanding_principal'],
            0.001,
        );
    }
}
