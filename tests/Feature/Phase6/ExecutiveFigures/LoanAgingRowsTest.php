<?php

namespace Tests\Feature\Phase6\ExecutiveFigures;

use App\Models\Loan;
use App\Services\Accounting\LoanAgingBuilder;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-A: each aged loan row names its product and its last posted
 * payment on or before the as-of date (for the PAR drilldown), and
 * bucketTotals() counts the loans and their principal per bucket.
 */
class LoanAgingRowsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
    }

    public function test_a_row_names_its_product_and_last_payment(): void
    {
        $paid = $this->createReleasedLoan();
        $this->pay($paid, 1000, '2026-11-19');
        $unpaid = $this->createReleasedLoan();

        $rows = collect(app(LoanAgingBuilder::class)->rows('2026-11-19'))->keyBy('loan_id');

        $this->assertSame(['id' => $paid->loan_product_id, 'name' => $paid->loanProduct->name], $rows[$paid->id]['product']);
        $this->assertSame('2026-11-19', $rows[$paid->id]['last_payment_date']);
        $this->assertNull($rows[$unpaid->id]['last_payment_date']);
    }

    public function test_a_payment_after_the_as_of_date_is_not_its_last_payment(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan, 1000, '2026-11-19');

        $rows = collect(app(LoanAgingBuilder::class)->rows('2026-11-18'))->keyBy('loan_id');

        $this->assertNull($rows[$loan->id]['last_payment_date']);
    }

    public function test_bucket_totals_count_loans_and_principal_in_centavos(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->amortizationSchedules()->where('period_number', 1)->update(['due_date' => '2026-10-01']);
        $this->createReleasedLoan(['principal_amount' => 30000]);

        $totals = app(LoanAgingBuilder::class)->bucketTotals('2026-11-19');

        $this->assertSame(['current', '1_30', '31_60', '61_90', '91_120', 'over_120'], array_keys($totals));
        $this->assertSame(['loans' => 1, 'principal' => 3000000], $totals['current']);
        $this->assertSame(['loans' => 1, 'principal' => 6000000], $totals['31_60']);
        $this->assertSame(['loans' => 0, 'principal' => 0], $totals['over_120']);
    }

    private function pay(Loan $loan, float $amount, string $date): void
    {
        app(RepaymentService::class)->processRepayment($loan->fresh(), $amount, $date, $this->admin);
    }
}
