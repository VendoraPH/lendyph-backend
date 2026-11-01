<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\ShareCapitalLedger;
use App\Services\RepaymentService;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The payment screens' figures that collecting the premium first changes,
 * sent by the server:
 *
 * - LoanResource `amount_due_now`: what a payment must be today to clear the
 *   premium owed and the first open period in full, in the order the
 *   allocation takes money, with the build-up a payment credits;
 * - the payment preview's `balance_before` / `balance_after`: the loan's
 *   outstanding balance (principal plus premium owed) around the payment;
 * - GET /repayments `meta.totals.total_insurance_applied`.
 */
class InsuranceOwedPaymentScreenFiguresTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, mixed> */
    private function amountDueNow(Loan $loan): array
    {
        $due = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.amount_due_now');
        $this->assertIsArray($due, 'amount_due_now is missing from the loan');

        return $due;
    }

    private function firstOpenPeriod(Loan $loan): ?AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('period_number')
            ->first();
    }

    /**
     * Builds the scenario twice: paying exactly `amount` clears the premium
     * and the period; ₱0.01 less leaves the period unpaid.
     *
     * @param  Closure(): Loan  $scenario
     * @return array<string, mixed> the quoted figures
     */
    private function assertAmountClearsPremiumAndPeriod(Closure $scenario): array
    {
        $loan = $scenario();
        $due = $this->amountDueNow($loan);
        $period = AmortizationSchedule::findOrFail($due['schedule_id']);
        $this->assertSame($period->period_number, $due['period_number']);

        // The parts add up to the amount, to the centavo.
        $parts = ['insurance', 'principal', 'interest', 'penalty', 'share_capital_build_up'];
        $this->assertSame(
            (int) round($due['amount'] * 100),
            array_sum(array_map(fn (string $k): int => (int) round($due[$k] * 100), $parts)),
        );

        $this->pay($loan, (float) $due['amount']);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);
        $this->assertSame('paid', $period->fresh()->status);

        $short = $scenario();
        $shortDue = $this->amountDueNow($short);
        $this->assertEquals($due['amount'], $shortDue['amount'], 'setup: both scenarios quote the same amount');
        $this->pay($short, round((float) $shortDue['amount'] - 0.01, 2));
        $this->assertNotSame('paid', AmortizationSchedule::findOrFail($shortDue['schedule_id'])->status);

        return $due;
    }

    public function test_amount_due_now_on_a_monthly_loan_is_the_premium_then_the_first_period(): void
    {
        $due = $this->assertAmountClearsPremiumAndPeriod(fn () => $this->loanOwingPremium());

        $this->assertEquals(12200, $due['amount']);
        $this->assertEquals(400, $due['insurance']);
        $this->assertEquals(10000, $due['principal']);
        $this->assertEquals(1800, $due['interest']);
        $this->assertEquals(0, $due['penalty']);
        $this->assertEquals(0, $due['share_capital_build_up']);
        $this->assertSame(1, $due['period_number']);
    }

    public function test_amount_due_now_on_a_part_paid_period(): void
    {
        $due = $this->assertAmountClearsPremiumAndPeriod(function (): Loan {
            $loan = $this->loanOwingPremium();
            $this->pay($loan, 150.00);

            return $loan->fresh();
        });

        // ₱250 of premium left, then the whole period.
        $this->assertEquals(250, $due['insurance']);
        $this->assertEquals(12050, $due['amount']);
    }

    public function test_amount_due_now_on_an_overdue_period_includes_the_penalty_a_payment_charges(): void
    {
        $due = $this->assertAmountClearsPremiumAndPeriod(
            fn () => $this->loanOwingPremium(startDate: now()->subMonth()->subDays(10)->toDateString()),
        );

        $this->assertGreaterThan(0, $due['penalty']);
        $this->assertEquals(400, $due['insurance']);
    }

    public function test_amount_due_now_on_an_extended_upon_maturity_loan(): void
    {
        $due = $this->assertAmountClearsPremiumAndPeriod(function (): Loan {
            $loan = $this->loanOwingPremium(['interest_method' => 'upon_maturity', 'term' => 1]);
            $loan->amortizationSchedules()->update(['due_date' => now()->addWeek()->toDateString(), 'status' => 'pending']);
            $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

            return $loan->fresh();
        });

        // ₱1,800 deferred plus ₱1,800 fresh on the extension's period.
        $this->assertEquals(400, $due['insurance']);
        $this->assertEquals(60000, $due['principal']);
        $this->assertEquals(3600, $due['interest']);
        $this->assertEquals(64000, $due['amount']);
        $this->assertSame(2, $due['period_number']);
    }

    public function test_amount_due_now_includes_the_build_up_a_payment_credits_as_share_capital(): void
    {
        $loan = $this->loanOwingPremium();
        DB::table('loans')->where('id', $loan->id)->update(['scb_amount' => 500]);

        $due = $this->amountDueNow($loan);

        $this->assertEquals(500, $due['share_capital_build_up']);
        $this->assertEquals(12700, $due['amount']);

        $repayment = $this->pay($loan, (float) $due['amount']);

        // Premium first, the period in full, and the rest is the build-up.
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);
        $this->assertSame('paid', AmortizationSchedule::findOrFail($due['schedule_id'])->status);
        $this->assertEqualsWithDelta(500.00, (float) ShareCapitalLedger::where('repayment_id', $repayment->id)->sum('credit'), 0.001);
    }

    public function test_amount_due_now_with_no_open_period_is_the_premium_alone(): void
    {
        $loan = $this->loanOwingPremium();
        $loan->amortizationSchedules()->update([
            'principal_paid' => DB::raw('principal_due'),
            'interest_paid' => DB::raw('interest_due'),
            'status' => 'paid',
        ]);
        $loan->update(['status' => 'ongoing']);

        $due = $this->amountDueNow($loan);

        $this->assertEquals(400, $due['amount']);
        $this->assertEquals(400, $due['insurance']);
        $this->assertEquals(0, $due['principal']);
        $this->assertNull($due['period_number']);
        $this->assertNull($due['schedule_id']);
        $this->assertNull($this->firstOpenPeriod($loan));
    }

    public function test_the_preview_balance_is_the_outstanding_balance_including_the_premium_owed(): void
    {
        $loan = $this->loanOwingPremium();

        $data = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => 150,
            'payment_date' => now()->toDateString(),
        ])->assertOk()->json('data');

        // ₱60,000 principal + ₱400 premium before; ₱250 premium left after.
        $this->assertEquals(60400, $data['balance_before']);
        $this->assertEquals(60250, $data['balance_after']);
        $this->assertEquals((float) $loan->fresh()->outstanding_balance, $data['balance_before']);
    }

    public function test_the_repayments_list_totals_the_premium_collected_by_posted_payments(): void
    {
        $first = $this->loanOwingPremium();
        $second = $this->loanOwingPremium();

        $this->pay($first, 1000);
        $this->pay($second, 150);
        $voided = $this->pay($second, 1000);
        app(RepaymentService::class)->voidRepayment($voided->fresh(), 'Keyed twice', $this->admin);

        $this->getJson('/api/repayments')
            ->assertOk()
            ->assertJsonPath('meta.totals.total_insurance_applied', fn ($v) => abs((float) $v - 550.00) < 0.001);
    }
}
