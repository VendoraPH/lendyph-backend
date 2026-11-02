<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\User;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 2, row by row. GET /loans/{id}/ledger's rows are recorded facts in
 * order, each with the balances after it, and none of the ordinary flows needs
 * a restated balance: a release and payments, a void, a late penalty and its
 * waiver, a balance adjustment, a schedule restructure, the premium collected,
 * and share capital credited.
 *
 * ₱61,234.57 over 6 monthly periods at 3% straight: ₱10,205.76 principal and
 * ₱1,837.04 interest a period, ₱11,022.24 interest over the schedule.
 */
class LoanLedgerRowsTest extends TestCase
{
    use BuildsReleasedLoans, OwesInsurancePremium, SetupLendyPH;

    private const PRINCIPAL = 61234.57;

    private const SCHEDULE_INTEREST = 11022.24;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_release_and_payments_walk_down_from_the_original_schedule(): void
    {
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL);
        $this->payLoan($loan, 12042.80);
        $this->payLoan($loan, 5000.37);

        $ledger = $this->ledger($loan);

        $this->assertSame(['release', 'repayment', 'repayment'], array_column($ledger['rows'], 'kind'));
        [$release, $first, $second] = $ledger['rows'];

        $this->assertMoney(self::PRINCIPAL, $release['principal']);
        $this->assertMoney(self::SCHEDULE_INTEREST, $release['interest']);
        $this->assertBalances([self::PRINCIPAL, self::SCHEDULE_INTEREST, 0], $release);

        $this->assertMoney(10205.76, $first['principal']);
        $this->assertMoney(1837.04, $first['interest']);
        $this->assertBalances([51028.81, 9185.20, 0], $first);

        // ₱5,000.37: ₱1,837.04 interest, then ₱3,163.33 principal.
        $this->assertMoney(3163.33, $second['principal']);
        $this->assertBalances([47865.48, 7348.16, 0], $second);
        $this->assertClosingIsTheSummary($loan, $ledger);
    }

    public function test_a_voided_payment_is_put_back_on_the_day_it_was_voided(): void
    {
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL);
        $repayment = $this->payLoan($loan, 12042.80);
        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();

        $ledger = $this->ledger($loan);

        $this->assertSame(['release', 'repayment', 'void'], array_column($ledger['rows'], 'kind'));
        $this->assertSame($repayment->id, $ledger['rows'][2]['repayment_id']);
        $this->assertMoney(10205.76, $ledger['rows'][2]['principal']);
        $this->assertBalances([self::PRINCIPAL, self::SCHEDULE_INTEREST, 0], $ledger['rows'][2]);
        $this->assertClosingIsTheSummary($loan, $ledger);
    }

    public function test_a_late_penalty_is_charged_on_its_first_late_day_and_paid_first(): void
    {
        // Released 2026-01-10. Period 1 fell due 2026-02-10; 3 days' grace,
        // so late from 2026-02-14.
        $this->travelTo(Carbon::parse('2026-01-10 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-01-10');
        $this->travelTo(Carbon::parse('2026-02-20 09:00'));
        $this->payLoan($loan, 5000.37);

        $ledger = $this->ledger($loan);

        $this->assertSame(['release', 'penalty_charge', 'repayment'], array_column($ledger['rows'], 'kind'));
        [, $charge, $payment] = $ledger['rows'];

        // 2% of ₱10,205.76.
        $this->assertSame('2026-02-14', $charge['date']);
        $this->assertMoney(204.12, $charge['penalty']);
        $this->assertBalances([self::PRINCIPAL, self::SCHEDULE_INTEREST, 204.12], $charge);
        $this->assertMoney(204.12, $payment['penalty']);
        $this->assertMoney(1837.04, $payment['interest']);
        $this->assertMoney(2959.21, $payment['principal']);
        $this->assertBalances([58275.36, 9185.20, 0], $payment);
        $this->assertClosingIsTheSummary($loan, $ledger);
    }

    public function test_a_penalty_waiver_takes_off_what_it_forgave(): void
    {
        $this->travelTo(Carbon::parse('2026-01-10 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-01-10');
        $this->travelTo(Carbon::parse('2026-02-20 09:00'));
        app(RepaymentService::class)->applyPenalties($loan, now());
        $this->applyAdjustment($loan, 'penalty_waiver', ['waive_all' => true]);

        $ledger = $this->ledger($loan);

        $this->assertSame(['release', 'penalty_charge', 'adjustment'], array_column($ledger['rows'], 'kind'));
        $this->assertMoney(204.12, $ledger['rows'][1]['penalty']);
        $this->assertMoney(-204.12, $ledger['rows'][2]['penalty']);
        $this->assertNotNull($ledger['rows'][2]['loan_adjustment_id']);
        $this->assertBalances([self::PRINCIPAL, self::SCHEDULE_INTEREST, 0], $ledger['rows'][2]);
        $this->assertClosingIsTheSummary($loan, $ledger);
    }

    public function test_a_balance_adjustment_lowers_the_principal_by_its_own_amount(): void
    {
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL);
        $this->applyAdjustment($loan, 'balance_adjustment', ['target_principal_balance' => 50000.25]);

        $ledger = $this->ledger($loan);

        $this->assertSame(['release', 'adjustment'], array_column($ledger['rows'], 'kind'));
        $this->assertMoney(-11234.32, $ledger['rows'][1]['principal']);
        $this->assertMoney(0, $ledger['rows'][1]['interest']);
        $this->assertBalances([50000.25, self::SCHEDULE_INTEREST, 0], $ledger['rows'][1]);
        $this->assertClosingIsTheSummary($loan, $ledger);
    }

    public function test_a_schedule_restructure_keeps_the_original_interest_opening_and_carries_the_change(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-01-15');
        $this->travelTo(Carbon::parse('2026-02-10 09:00'));
        $this->payLoan($loan, 12042.80);
        $this->applyAdjustment($loan, 'restructure', ['term' => 3]);

        $ledger = $this->ledger($loan);

        $this->assertSame(['release', 'repayment', 'adjustment'], array_column($ledger['rows'], 'kind'));
        $this->assertMoney(self::SCHEDULE_INTEREST, $ledger['rows'][0]['interest']);
        $restructure = $ledger['rows'][2];
        $this->assertMoney(0, $restructure['principal']);
        $this->assertBalances([51028.81, null, 0], $restructure);
        // What the rebuilt schedule changed the interest owed by.
        $this->assertMoney(
            $this->summaryOf($loan)['outstanding_interest'] - 9185.20,
            $restructure['interest'],
        );
        $this->assertClosingIsTheSummary($loan, $ledger);
    }

    public function test_a_payment_row_carries_the_premium_it_collected(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $payment = $this->ledger($loan)['rows'][1];

        $this->assertSame('repayment', $payment['kind']);
        $this->assertMoney(400, $payment['insurance']);
        $this->assertMoney(600, $payment['interest']);
        $this->assertBalances([60000, 10200, 0], $payment);
    }

    public function test_share_capital_credited_lowers_the_build_up_balance(): void
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly', 'penalty_rate' => 2.0, 'grace_period_days' => 3]);
        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'scb_amount' => 100,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin);
        $this->payLoan($loan, 11900);

        $ledger = $this->ledger($loan->fresh());

        $this->assertMoney(600, $ledger['rows'][0]['scb']);
        $this->assertMoney(600, $ledger['rows'][0]['balances']['scb']);
        $this->assertMoney(100, $ledger['rows'][1]['scb']);
        $this->assertMoney(500, $ledger['rows'][1]['balances']['scb']);
        $this->assertMoney(500, $ledger['closing']['scb']);
    }

    public function test_an_unreleased_loan_has_no_rows(): void
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly']);
        $loan = app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $ledger = $this->ledger($loan);

        $this->assertSame([], $ledger['rows']);
        $this->assertMoney(0, $ledger['closing']['principal']);
    }

    public function test_a_loan_released_after_a_period_fell_late_is_charged_no_earlier_than_its_release(): void
    {
        $this->travelTo(Carbon::parse('2026-02-20 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-01-10');
        $this->payLoan($loan, 5000.37);

        $ledger = $this->ledger($loan);

        $this->assertSame(['release', 'penalty_charge', 'repayment'], array_column($ledger['rows'], 'kind'));
        $this->assertSame('2026-02-20', $ledger['rows'][1]['date']);
        $this->assertClosingIsTheSummary($loan, $ledger);
    }

    public function test_an_extension_on_pay_is_one_payment_row_then_its_fresh_interest(): void
    {
        // One month at 3%, ₱61,234.57: ₱1,837.04 interest. Released, then
        // due ten days ago: a ₱1,224.69 penalty (2%) is taken first.
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, product: ['interest_method' => 'upon_maturity', 'term' => 1]);
        $loan->amortizationSchedules()->update(['due_date' => now()->subDays(10)->toDateString()]);
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $ledger = $this->ledger($loan);
        $repayment = Repayment::where('loan_id', $loan->id)->sole();

        $this->assertSame(['release', 'penalty_charge', 'extension_payment', 'extension_charge'], array_column($ledger['rows'], 'kind'));
        [$release, $charge, $payment, $fresh] = $ledger['rows'];
        $this->assertMoney(1837.04, $release['interest']);
        $this->assertMoney(1224.69, $charge['penalty']);
        $this->assertSame($repayment->id, $payment['repayment_id']);
        $this->assertNotNull($payment['loan_adjustment_id']);
        $this->assertMoney(1224.69, $payment['penalty']);
        $this->assertMoney(612.35, $payment['interest']);
        $this->assertBalances([self::PRINCIPAL, 1224.69, 0], $payment);
        $this->assertMoney(1837.04, $fresh['interest']);
        $this->assertBalances([self::PRINCIPAL, 3061.73, 0], $fresh);
        $this->assertClosingIsTheSummary($loan, $ledger);
    }

    public function test_it_needs_loans_view(): void
    {
        $loan = $this->releasedLoanFor();
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->getJson("/api/loans/{$loan->id}/ledger")->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function ledger(Loan $loan): array
    {
        $ledger = $this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data');

        $this->assertNotContains(
            'Balance restated to the schedule',
            array_column($ledger['rows'], 'description'),
            'ordinary flows need no restated balance',
        );

        return $ledger;
    }

    /** @param  array<string, mixed>  $newValues */
    private function applyAdjustment(Loan $loan, string $type, array $newValues): void
    {
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => $type,
            'new_values' => $newValues,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();
    }

    /**
     * @param  array{0: float|int, 1: float|int|null, 2: float|int}  $expected  principal, interest (null: not checked), penalty
     * @param  array<string, mixed>  $row
     */
    private function assertBalances(array $expected, array $row): void
    {
        $this->assertMoney($expected[0], $row['balances']['principal'], 'balances.principal');
        if ($expected[1] !== null) {
            $this->assertMoney($expected[1], $row['balances']['interest'], 'balances.interest');
        }
        $this->assertMoney($expected[2], $row['balances']['penalty'], 'balances.penalty');
    }

    /** @param  array<string, mixed>  $ledger */
    private function assertClosingIsTheSummary(Loan $loan, array $ledger): void
    {
        $summary = $this->summaryOf($loan);
        $last = end($ledger['rows']);

        foreach (['principal', 'interest', 'penalty'] as $column) {
            $this->assertMoney($summary["outstanding_{$column}"], $ledger['closing'][$column], "closing.{$column}");
            $this->assertMoney($summary["outstanding_{$column}"], $last['balances'][$column], "last row {$column}");
        }
    }

    private function assertMoney(float|int|string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertEqualsWithDelta((float) $expected, (float) $actual, 0.001, $message);
    }
}
