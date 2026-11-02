<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\RepaymentAllocation;
use App\Models\ShareCapitalLedger;
use App\Services\LoanService;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Decision m5-1, around the edges: the allocator's whole centavos hold with
 * a share capital build-up, an insurance premium owed, and a void.
 *
 * ₱45,678.91 over 6 monthly periods at 3% straight: ₱7,613.15 principal and
 * ₱1,370.37 interest a period, ₱8,983.52 due, the amounts that left float
 * dust before. The premium is 1% of principal, of which the release collects
 * ₱123.45 and leaves the rest owed on the loan.
 */
class AllocatorCentavoEdgeCasesTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    private const PRINCIPAL = 45678.91;

    private const INSURANCE = [
        'insurance_premium_percentage' => 1.0,
        'insurance_payment_type' => 'partial',
        'insurance_partial_amount' => 123.45,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_build_up_payment_with_a_centavo_remainder_credits_exactly_the_remainder(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 09:00'));
        $loan = $this->buildUpLoanOwingPremium();
        $premiumOwed = $this->centavos($loan->insurance_remaining_balance);
        $this->assertGreaterThan(0, $premiumOwed, 'setup: a premium is owed');

        // The 1-period preset (premium, period 1, its ₱100 build-up) plus ₱12.34.
        $preset = $this->centavos($this->summaryOf($loan)['advance_presets'][0]['amount']);
        $this->assertSame($premiumOwed + 898352 + 10000, $preset, 'setup: the 1-period preset');
        $amount = $preset + 1234;

        $repayment = $this->payLoan($loan, $amount / 100, '2026-02-15');

        $allocations = $repayment->allocations()->get();
        $allocated = $allocations->sum(fn (RepaymentAllocation $a) => $this->centavos($a->penalty) + $this->centavos($a->interest) + $this->centavos($a->principal));
        $remainder = $amount - $premiumOwed - 898352;
        $credit = $this->centavos(ShareCapitalLedger::where('repayment_id', $repayment->id)->sum('credit'));

        $this->assertSame([1], $allocations->pluck('period_number')->all(), 'only period 1 takes money');
        $this->assertSame($premiumOwed, $this->centavos($repayment->insurance_applied));
        $this->assertSame(898352, $allocated);
        $this->assertSame($remainder, $this->centavos($repayment->overpayment), 'the remainder is the overpayment');
        $this->assertSame(11234, $credit, 'the share capital credit is exactly the remainder');
        $this->assertSame($remainder, $credit);
        // Every centavo of the payment is accounted for, once: the premium,
        // the periods, and the overpayment that became the credit.
        $this->assertSame(
            $this->centavos($repayment->amount_paid),
            $this->centavos($repayment->insurance_applied) + $allocated + $this->centavos($repayment->overpayment),
        );
        $this->assertSame('paid', $this->period($loan, 1)->status);
        $this->assertSame('pending', $this->period($loan, 2)->status);
        $this->assertSame(0, $this->zeroAllocationRows($loan));
    }

    public function test_voiding_a_centavo_exact_payment_reverses_it_exactly(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-02-15');
        $before = $this->schedulePaid($loan);
        $owedBefore = $this->summaryOf($loan)['total_outstanding'];

        $amount = (float) $this->summaryOf($loan)['advance_presets'][1]['amount'];
        $this->assertEqualsWithDelta(17967.04, $amount, 0.001, 'setup: the 2-period preset');
        $repayment = $this->payLoan($loan, $amount, '2026-02-15');

        $this->assertSame(
            [[1, 0, 137037, 761315], [2, 0, 137037, 761315]],
            $repayment->allocations()->orderBy('period_number')->get()
                ->map(fn (RepaymentAllocation $a) => [$a->period_number, $this->centavos($a->penalty), $this->centavos($a->interest), $this->centavos($a->principal)])
                ->all(),
            'one row per period paid, to the centavo',
        );
        $this->assertSame(0, $this->zeroAllocationRows($loan));

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();

        $this->assertSame('voided', $repayment->fresh()->status);
        $this->assertSame($before, $this->schedulePaid($loan), 'every period is back to what it was, to the centavo');
        $this->assertSame($owedBefore, $this->summaryOf($loan)['total_outstanding'], 'the loan owes exactly what it did before');
        $this->assertSame(0, $this->zeroAllocationRows($loan));
    }

    public function test_an_exact_preset_with_a_premium_owed_is_recorded_exact(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-02-15', release: self::INSURANCE);
        $premiumOwed = $this->centavos($loan->insurance_remaining_balance);
        $this->assertGreaterThan(0, $premiumOwed, 'setup: a premium is owed');
        $this->travelTo(Carbon::parse('2026-03-15 09:00'));

        $amount = $this->centavos($this->summaryOf($loan)['advance_presets'][0]['amount']);
        $this->assertSame($premiumOwed + 898352, $amount, 'setup: the 1-period preset carries the premium');

        $preview = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => $amount / 100,
            'payment_date' => '2026-03-15',
        ])->assertOk()->json('data');
        $this->assertSame('exact', $preview['payment_badge']['type']);

        $repayment = $this->payLoan($loan, $amount / 100, '2026-03-15');

        $this->assertSame('exact', $repayment->payment_type);
        $this->assertSame($premiumOwed, $this->centavos($repayment->insurance_applied));
        $this->assertSame(0, $this->centavos($repayment->overpayment));
        $this->assertSame(0, $this->centavos($loan->fresh()->insurance_remaining_balance));
        $this->assertSame([1], $repayment->allocations()->pluck('period_number')->all());
        $this->assertSame('pending', $this->period($loan, 2)->status);
    }

    private function buildUpLoanOwingPremium(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => self::PRINCIPAL,
            'scb_amount' => 100,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin, self::INSURANCE);

        $loan = $loan->fresh();
        $this->assertContains($loan->status, Loan::ACTIVE_STATUSES, 'setup: the loan is released');

        return $loan;
    }

    /** @return array<int, array{0: string, 1: int, 2: int, 3: int}> each period's status and paid parts, in centavos */
    private function schedulePaid(Loan $loan): array
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->orderBy('period_number')->get()
            ->mapWithKeys(fn (AmortizationSchedule $s) => [$s->period_number => [
                $s->status,
                $this->centavos($s->penalty_paid),
                $this->centavos($s->interest_paid),
                $this->centavos($s->principal_paid),
            ]])
            ->all();
    }

    private function zeroAllocationRows(Loan $loan): int
    {
        return RepaymentAllocation::whereIn('repayment_id', Repayment::where('loan_id', $loan->id)->pluck('id'))
            ->where('penalty', 0)->where('interest', 0)->where('principal', 0)
            ->count();
    }

    private function period(Loan $loan, int $number): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', $number)->sole();
    }

    private function centavos(mixed $pesos): int
    {
        return LoanService::toCentavos((float) ($pesos ?? 0));
    }
}
