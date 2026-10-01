<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/loans/{id}/amortization-balances: what is still owed on each
 * period, per component, read from what the payment allocation recorded.
 *
 * Every case also checks the totals against GET /api/loans/{id}/summary, the
 * figures the loan screen shows above the table (Current Outstanding, Total
 * Paid, Overdue), so the two can never disagree.
 *
 * The loan from createReleasedLoan(): ₱60,000 over 6 monthly periods at 3%
 * straight, so each period is ₱10,000 principal + ₱1,800 interest = ₱11,800,
 * ₱70,800 in all. Penalty is 2% of the unpaid principal, after a 3-day grace.
 */
class AmortizationBalanceTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_loan_with_no_payments_owes_every_period_in_full(): void
    {
        $loan = $this->createReleasedLoan();

        $data = $this->balances($loan);

        $this->assertCount(6, $data['periods']);
        foreach ($data['periods'] as $index => $period) {
            $this->assertSame($index + 1, $period['period_number']);
            $this->assertSame('pending', $period['status']);
            $this->assertFalse($period['is_late']);
            $this->assertMoney(10000, $period['principal']['balance']);
            $this->assertMoney(1800, $period['interest']['balance']);
            $this->assertMoney(0, $period['penalty']['balance']);
            $this->assertMoney(11800, $period['balance']);
        }
        $this->assertMoney(70800, $data['totals']['balance']);
        $this->assertMoney(0, $data['totals']['paid']);
        $this->assertTotalsMatchSummary($loan, $data);
    }

    public function test_a_partial_payment_leaves_the_rest_of_its_period_owing(): void
    {
        $loan = $this->createReleasedLoan();

        // Interest first, then principal: ₱1,800 interest + ₱3,200 principal.
        $this->pay($loan, 5000);

        $first = $this->balances($loan)['periods'][0];
        $this->assertSame('partial', $first['status']);
        $this->assertMoney(3200, $first['principal']['paid']);
        $this->assertMoney(6800, $first['principal']['balance']);
        $this->assertMoney(1800, $first['interest']['paid']);
        $this->assertMoney(0, $first['interest']['balance']);
        $this->assertMoney(6800, $first['balance']);

        $data = $this->balances($loan);
        $this->assertMoney(11800, $data['periods'][1]['balance']);
        $this->assertMoney(65800, $data['totals']['balance']);
        $this->assertMoney(5000, $data['totals']['paid']);
        $this->assertTotalsMatchSummary($loan, $data);
    }

    public function test_a_fully_paid_period_owes_nothing(): void
    {
        $loan = $this->createReleasedLoan();

        $this->pay($loan, 11800);

        $data = $this->balances($loan);
        $first = $data['periods'][0];
        $this->assertSame('paid', $first['status']);
        $this->assertMoney(0, $first['principal']['balance']);
        $this->assertMoney(0, $first['interest']['balance']);
        $this->assertMoney(0, $first['balance']);
        $this->assertSame('pending', $data['periods'][1]['status']);
        $this->assertMoney(11800, $data['periods'][1]['balance']);
        $this->assertMoney(59000, $data['totals']['balance']);
        $this->assertTotalsMatchSummary($loan, $data);
    }

    public function test_an_advance_payment_lowers_the_balance_of_the_period_it_reached(): void
    {
        $loan = $this->createReleasedLoan();

        // Period 1 in full, then period 2's ₱1,800 interest and ₱4,100 principal.
        $this->pay($loan, 17700);

        $this->assertSame('advance', $loan->repayments()->sole()->payment_type);

        $data = $this->balances($loan);
        $this->assertSame('paid', $data['periods'][0]['status']);
        $second = $data['periods'][1];
        $this->assertSame('partial', $second['status']);
        $this->assertMoney(4100, $second['principal']['paid']);
        $this->assertMoney(5900, $second['principal']['balance']);
        $this->assertMoney(0, $second['interest']['balance']);
        $this->assertMoney(5900, $second['balance']);
        $this->assertMoney(11800, $data['periods'][2]['balance']);
        $this->assertMoney(53100, $data['totals']['balance']);
        $this->assertTotalsMatchSummary($loan, $data);
    }

    public function test_overdue_periods_carry_their_penalty_and_make_up_the_overdue_total(): void
    {
        // Periods 1 and 2 are past their grace period; period 3 is not late yet.
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString()]);
        $this->artisan('loans:apply-penalties')->assertSuccessful();

        // Period 1: its ₱200 penalty first, then ₱100 of its interest.
        $this->pay($loan, 300);

        $data = $this->balances($loan);
        [$first, $second, $third] = $data['periods'];

        $this->assertTrue($first['is_late']);
        $this->assertMoney(200, $first['penalty']['charged']);
        $this->assertMoney(200, $first['penalty']['paid']);
        $this->assertMoney(0, $first['penalty']['balance']);
        $this->assertMoney(1700, $first['interest']['balance']);
        $this->assertMoney(10000, $first['principal']['balance']);
        $this->assertMoney(11700, $first['balance']);

        $this->assertTrue($second['is_late']);
        $this->assertSame('overdue', $second['status']);
        $this->assertMoney(200, $second['penalty']['balance']);
        $this->assertMoney(12000, $second['balance']);

        $this->assertFalse($third['is_late']);
        $this->assertMoney(0, $third['penalty']['balance']);

        $this->assertMoney(400, $data['totals']['penalty']['charged']);
        $this->assertMoney(200, $data['totals']['penalty']['balance']);
        $this->assertMoney(23700, $data['totals']['overdue']);
        $this->assertMoney(70900, $data['totals']['balance']);
        $this->assertTotalsMatchSummary($loan, $data);
    }

    public function test_a_fully_paid_loan_owes_nothing_on_any_period(): void
    {
        $loan = $this->createReleasedLoan();

        $this->pay($loan, 70800);

        $this->assertSame('completed', $loan->fresh()->status);

        $data = $this->balances($loan);
        $this->assertCount(6, $data['periods']);
        foreach ($data['periods'] as $period) {
            $this->assertSame('paid', $period['status']);
            $this->assertMoney(0, $period['balance']);
        }
        $this->assertMoney(0, $data['totals']['balance']);
        $this->assertMoney(70800, $data['totals']['paid']);
        $this->assertTotalsMatchSummary($loan, $data);
    }

    public function test_a_restructured_loan_with_no_periods_left_answers_an_empty_list(): void
    {
        $source = $this->createReleasedLoan(['start_date' => now()->subMonths(8)->toDateString()]);
        $outstanding = (float) $this->getJson("/api/loans/{$source->id}/summary")->json('data.outstanding_balance');

        $response = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => $outstanding,
            'start_date' => now()->toDateString(),
        ])->assertCreated();
        $restructure = Loan::findOrFail($response->json('data.id'));
        $this->patchJson("/api/loans/{$restructure->id}/submit")->assertOk();
        $this->patchJson("/api/loans/{$restructure->id}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->patchJson("/api/loans/{$restructure->id}/release")->assertOk();

        $this->assertSame('restructured', $source->fresh()->status);
        $this->assertSame(0, AmortizationSchedule::where('loan_id', $source->id)->count());

        $data = $this->balances($source);
        $this->assertSame([], $data['periods']);
        $this->assertMoney(0, $data['totals']['balance']);
        $this->assertMoney(0, $data['totals']['paid']);
        $this->assertMoney(0, $data['totals']['overdue']);
        $this->assertTotalsMatchSummary($source, $data);
    }

    public function test_a_loan_that_was_never_released_has_no_balances(): void
    {
        $draft = app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $this->getJson("/api/loans/{$draft->id}/amortization-balances")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No amortization schedule found. Loan may not have been released yet.');
    }

    public function test_it_requires_the_loans_view_permission(): void
    {
        $loan = $this->createReleasedLoan();

        $this->actingAs(User::factory()->create());

        $this->getJson("/api/loans/{$loan->id}/amortization-balances")->assertForbidden();
    }

    /**
     * @return array{periods: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function balances(Loan $loan): array
    {
        return $this->getJson("/api/loans/{$loan->id}/amortization-balances")
            ->assertOk()
            ->json('data');
    }

    private function pay(Loan $loan, float $amount): void
    {
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => now()->toDateString(),
        ])->assertCreated();
    }

    /**
     * The table's footer and the cards above it must show the same money:
     * Current Outstanding per component, Total Paid and Overdue.
     *
     * @param  array{periods: list<array<string, mixed>>, totals: array<string, mixed>}  $data
     */
    private function assertTotalsMatchSummary(Loan $loan, array $data): void
    {
        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');
        $totals = $data['totals'];

        $this->assertMoney($summary['outstanding_principal'], $totals['principal']['balance']);
        $this->assertMoney($summary['outstanding_interest'], $totals['interest']['balance']);
        $this->assertMoney($summary['outstanding_penalty'], $totals['penalty']['balance']);
        $this->assertMoney($summary['outstanding_balance'], $totals['balance']);
        $this->assertMoney($summary['total_paid'], $totals['paid']);
        $this->assertMoney($summary['overdue_amount'], $totals['overdue']);

        $this->assertMoney(array_sum(array_column($data['periods'], 'balance')), $totals['balance']);
    }

    private function assertMoney(float|int $expected, float|int $actual): void
    {
        $this->assertEqualsWithDelta((float) $expected, (float) $actual, 0.001);
    }
}
