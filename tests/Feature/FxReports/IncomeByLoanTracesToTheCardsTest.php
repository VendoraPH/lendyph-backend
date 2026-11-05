<?php

namespace Tests\Feature\FxReports;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Income by Loan traces to the Income report's cards (fix round fx, decision
 * D9): rows carry `processing_fee` and a total, loans whose only income in the
 * period is a fee are listed, and `meta.totals` equal GET /reports/income to
 * the centavo.
 */
class IncomeByLoanTracesToTheCardsTest extends TestCase
{
    use SetupLendyPH;

    private Branch $reportBranch;

    private LoanProduct $product;

    private const PERIOD = 'date_from=2026-03-01&date_to=2026-03-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->reportBranch = Branch::factory()->create();
        $this->product = LoanProduct::factory()->create(['processing_fee' => 2.0]);
    }

    public function test_a_row_carries_its_processing_fee_and_a_total_with_it(): void
    {
        $loan = $this->releasedLoan(60000, [$this->fee('Processing Fee', 900)], '2026-03-05 09:00:00');
        $this->repayment($loan, '2026-03-20', 1800, 50.50);

        $row = $this->report()->assertOk()->json('data.0');

        $this->assertSame($loan->id, $row['loan_id']);
        $this->assertEquals(1800, $row['interest_income']);
        $this->assertEquals(50.50, $row['penalty_income']);
        $this->assertEquals(900, $row['processing_fee']);
        $this->assertEquals(2750.50, $row['total_income']);
    }

    public function test_a_loan_whose_only_income_in_the_period_is_a_fee_is_listed(): void
    {
        $loan = $this->releasedLoan(60000, [$this->fee('Processing Fee', 900)], '2026-03-05 09:00:00');

        $response = $this->report()->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $row = $response->json('data.0');
        $this->assertSame($loan->id, $row['loan_id']);
        $this->assertSame($loan->loan_account_number, $row['loan_account_number']);
        $this->assertSame($loan->borrower->full_name, $row['borrower_name']);
        $this->assertSame(0, $row['payments']);
        $this->assertEquals(0, $row['interest_income']);
        $this->assertEquals(900, $row['processing_fee']);
        $this->assertEquals(900, $row['total_income']);
    }

    public function test_a_payment_in_the_period_on_a_loan_released_earlier_carries_no_fee(): void
    {
        $loan = $this->releasedLoan(60000, [$this->fee('Processing Fee', 900)], '2026-02-05 09:00:00');
        $this->repayment($loan, '2026-03-20', 1800, 0);

        $row = $this->report()->assertOk()->json('data.0');

        $this->assertEquals(0, $row['processing_fee']);
        $this->assertEquals(1800, $row['total_income']);
    }

    public function test_a_loan_with_no_fee_and_no_payment_in_the_period_is_not_listed(): void
    {
        $this->releasedLoan(60000, [$this->fee('Service Fee', 300)], '2026-03-05 09:00:00');

        $this->assertSame(0, $this->report()->assertOk()->json('meta.total'));
    }

    public function test_meta_totals_equal_the_income_report_to_the_centavo(): void
    {
        $recorded = $this->releasedLoan(61728, [$this->fee('Processing Fee', 925.92), $this->fee('Service Fee', 300)], '2026-03-05 09:00:00');
        $this->repayment($recorded, '2026-03-20', 1851.84, 12.34);
        // No record of its deductions: the product's 2% of ₱33,333.33.
        $this->releasedLoan(33333.33, null, '2026-03-06 09:00:00');
        $older = $this->releasedLoan(30000, [$this->fee('Processing Fee', 450)], '2026-02-05 09:00:00');
        $this->repayment($older, '2026-03-21', 900.25, 0);
        $this->repayment($older, '2026-03-22', 500, 0, 'voided');
        // Outside the period altogether.
        $this->repayment($older, '2026-04-02', 777, 0);

        $income = $this->getJson('/api/reports/income?'.self::PERIOD)->assertOk()->json('data');
        $response = $this->report()->assertOk();
        $totals = $response->json('meta.totals');

        $this->assertEquals($income['interest_income'], $totals['interest_income']);
        $this->assertEquals($income['penalty_income'], $totals['penalty_income']);
        $this->assertEquals($income['processing_fees'], $totals['processing_fee']);
        $this->assertEquals($income['total'], $totals['total_income']);

        $this->assertSame(3, $totals['count']);
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $totals['payments']);
        $this->assertSame($totals, $response->json('totals'), 'The top-level totals stay, with the same figures.');

        // The fee column traces too.
        $this->assertEqualsWithDelta(
            $income['processing_fees'],
            array_sum(array_column($response->json('data'), 'processing_fee')),
            0.001,
        );
    }

    public function test_the_loan_and_branch_filters_narrow_the_fees_like_the_card(): void
    {
        $other = Branch::factory()->create();
        $here = $this->releasedLoan(60000, [$this->fee('Processing Fee', 900)], '2026-03-05 09:00:00');
        $there = $this->releasedLoan(30000, [$this->fee('Processing Fee', 450)], '2026-03-05 09:00:00', $other);

        foreach (["branch_id={$other->id}", "loan_id={$there->id}"] as $filter) {
            $query = self::PERIOD.'&'.$filter;
            $response = $this->getJson("/api/reports/income/by-loan?{$query}")->assertOk();

            $this->assertSame([$there->id], array_column($response->json('data'), 'loan_id'), $filter);
            $this->assertEquals(
                $this->getJson("/api/reports/income?{$query}")->json('data.processing_fees'),
                $response->json('meta.totals.processing_fee'),
                $filter,
            );
        }

        $this->assertSame(2, $this->report()->json('meta.total'));
        $this->assertNotNull($here);
    }

    public function test_rows_are_ordered_by_total_income_with_the_fee_in_it_across_pages(): void
    {
        $fees = [100, 900, 500, 700, 300];
        $loans = [];

        foreach ($fees as $fee) {
            $loans[$fee] = $this->releasedLoan(60000, [$this->fee('Processing Fee', $fee)], '2026-03-05 09:00:00');
        }

        // ₱400.00 interest beats the ₱300.00 fee.
        $this->repayment($loans[100], '2026-03-20', 300, 0);

        $first = $this->getJson('/api/reports/income/by-loan?'.self::PERIOD.'&per_page=2&page=1')->assertOk();
        $second = $this->getJson('/api/reports/income/by-loan?'.self::PERIOD.'&per_page=2&page=2')->assertOk();
        $third = $this->getJson('/api/reports/income/by-loan?'.self::PERIOD.'&per_page=2&page=3')->assertOk();

        $this->assertSame([$loans[900]->id, $loans[700]->id], array_column($first->json('data'), 'loan_id'));
        $this->assertSame([$loans[500]->id, $loans[100]->id], array_column($second->json('data'), 'loan_id'));
        $this->assertSame([$loans[300]->id], array_column($third->json('data'), 'loan_id'));
        $this->assertSame(5, $first->json('meta.total'));
        $this->assertSame(3, $first->json('meta.last_page'));
    }

    public function test_the_page_costs_the_same_queries_however_many_rows_it_has(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->report()->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->releasedLoan(60000, [$this->fee('Processing Fee', 900)], '2026-03-05 09:00:00');
        // The first request also loads the caller's permissions.
        $count();
        $few = $count();

        foreach (range(1, 6) as $i) {
            $this->releasedLoan(60000, [$this->fee('Processing Fee', 100 * $i)], '2026-03-05 09:00:00');
        }

        $this->assertSame($few, $count());
    }

    private function report()
    {
        return $this->getJson('/api/reports/income/by-loan?'.self::PERIOD);
    }

    /** @param list<array<string, mixed>>|null $deductions */
    private function releasedLoan(float $principal, ?array $deductions, string $releasedAt, ?Branch $branch = null): Loan
    {
        $branch ??= $this->reportBranch;
        $total = round(array_sum(array_column($deductions ?? [], 'amount')), 2);

        return Loan::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $branch->id])->id,
            'loan_product_id' => $this->product->id,
            'branch_id' => $branch->id,
            'created_by' => $this->admin->id,
            'principal_amount' => $principal,
            'deductions' => $deductions,
            'total_deductions' => $total,
            'net_proceeds' => round($principal - $total, 2),
            'status' => 'ongoing',
            'released_at' => $releasedAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function fee(string $name, float $amount): array
    {
        return ['name' => $name, 'amount' => $amount, 'type' => 'fixed', 'original_value' => $amount];
    }

    private function repayment(Loan $loan, string $date, float $interest, float $penalty, string $status = 'posted'): Repayment
    {
        return Repayment::factory()->create([
            'loan_id' => $loan->id,
            'payment_date' => $date,
            'amount_paid' => $interest + $penalty,
            'principal_applied' => 0,
            'interest_applied' => $interest,
            'penalty_applied' => $penalty,
            'status' => $status,
            'received_by' => $this->admin->id,
        ]);
    }
}
