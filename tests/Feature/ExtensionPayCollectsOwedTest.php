<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Models\User;
use App\Services\RepaymentService;
use Closure;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * An extension on "pay" collects everything the open period owes except its
 * principal (Arvin, 2026-11-03): the insurance premium owed, then the late
 * penalty owed, then the interest outstanding, in one payment. The old period
 * is then settled in full and only principal moves to the new period, which
 * owes nothing but the fresh interest (one cycle's interest per cycle moved).
 *
 * Before, "pay" collected only the interest figure: on an overdue loan the
 * penalty took its share of it first and the rest of the interest carried
 * into the new period, so "pay" acted like "defer" for the interest.
 *
 * The loan: ₱60,000 for one month at 3% upon maturity, started 27 Apr 2026
 * and due 27 May (₱1,800 interest a cycle), penalty 2% of unpaid principal
 * (₱1,200) after a 3-day grace.
 */
class ExtensionPayCollectsOwedTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    private const INTEREST = 1800.00;

    private const PENALTY = 1200.00;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** Released on 27 Apr 2026, then the clock moves to `$extendOn`. */
    private function oneMonthLoan(string $extendOn, float $principal = 60000): Loan
    {
        $this->travelTo('2026-04-27 09:00:00');

        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'principal_amount' => $principal,
            'start_date' => '2026-04-27',
        ]);

        $this->travelTo($extendOn.' 10:00:00');

        return $loan->fresh();
    }

    /** One-month upon-maturity loan owing the ₱400 premium, due `$dueDate`. */
    private function oneMonthLoanOwingPremium(string $dueDate): Loan
    {
        $loan = $this->loanOwingPremium(['interest_method' => 'upon_maturity', 'term' => 1]);
        $loan->amortizationSchedules()->update(['due_date' => $dueDate, 'status' => 'pending']);

        return $loan->fresh();
    }

    /** @return array<string, mixed> */
    private function preview(Loan $loan, string $option): array
    {
        return $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option={$option}")
            ->assertOk()
            ->json('data');
    }

    private function extend(Loan $loan, string $option): LoanAdjustment
    {
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => $option])->assertOk();

        return LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->sole();
    }

    /**
     * The preview's `collect_now` equals what the extension recorded, part by
     * part, to the centavo, and neither carries any interest.
     *
     * @param  array<string, mixed>  $preview
     * @param  array{amount: float, insurance: float, penalty: float, interest: float}  $expected
     */
    private function assertPreviewEqualsRecorded(array $preview, LoanAdjustment $adjustment, array $expected): void
    {
        $centavos = fn (mixed $pesos): int => (int) round((float) $pesos * 100);
        $values = $adjustment->new_values;

        $this->assertSame(array_map($centavos, $expected), [
            'amount' => $centavos($preview['collect_now']['amount']),
            'insurance' => $centavos($preview['collect_now']['insurance_part']),
            'penalty' => $centavos($preview['collect_now']['penalty_part']),
            'interest' => $centavos($preview['collect_now']['interest_part']),
        ], 'the preview quotes the expected parts');
        $this->assertSame(array_map($centavos, $expected), [
            'amount' => $centavos($values['amount_collected']),
            'insurance' => $centavos($values['insurance_paid']),
            'penalty' => $centavos($values['penalty_paid']),
            'interest' => $centavos($values['interest_paid']),
        ], 'the extension records the parts the preview quoted');
        $this->assertSame(0, $centavos($preview['carry_interest']));
        $this->assertSame(0, $centavos($values['carry_interest']));
    }

    private function applyAdjustment(Loan $loan, string $type, array $newValues): void
    {
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => $type,
            'new_values' => $newValues,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();
    }

    /** Nothing written: no payment, no adjustment, the periods and the premium owed as they were. */
    private function assertExtensionRefused(Loan $loan, string $option, string $message): void
    {
        $periodsBefore = $loan->amortizationSchedules()->orderBy('id')->get()->toArray();
        $premiumOwedBefore = (string) $loan->fresh()->insurance_remaining_balance;

        $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option={$option}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.loan.0', $message);
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => $option])
            ->assertUnprocessable()
            ->assertJsonPath('errors.loan.0', $message);

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $this->assertSame(0, LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->count());
        $this->assertSame($periodsBefore, $loan->amortizationSchedules()->orderBy('id')->get()->toArray());
        $this->assertSame($premiumOwedBefore, (string) $loan->fresh()->insurance_remaining_balance);
    }

    private function newPeriod(Loan $loan): AmortizationSchedule
    {
        return $loan->fresh()->amortizationSchedules()->where('status', 'pending')->sole();
    }

    public function test_pay_on_an_overdue_loan_collects_the_penalty_and_all_the_interest(): void
    {
        // 27 May -> 27 Aug: three cycles, ₱5,400 fresh interest.
        $loan = $this->oneMonthLoan('2026-07-30');
        $oldPeriodId = $loan->amortizationSchedules()->sole()->id;

        $adjustment = $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertEqualsWithDelta(self::PENALTY + self::INTEREST, (float) $repayment->amount_paid, 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->principal_applied, 0.001);

        // The old period is settled in full: everything it was due except the
        // principal that moved on was paid.
        $old = AmortizationSchedule::findOrFail($oldPeriodId);
        $this->assertSame('paid', $old->status);
        $this->assertSame($adjustment->id, $old->closed_by_adjustment_id);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $old->interest_paid, 0.001);
        $this->assertEqualsWithDelta((float) $old->interest_due, (float) $old->interest_paid, 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $old->penalty_paid, 0.001);
        $this->assertEqualsWithDelta((float) $old->penalty_amount, (float) $old->penalty_paid, 0.001);

        // Only principal moves; the new period owes the fresh interest alone.
        $new = $this->newPeriod($loan);
        $this->assertSame('2026-08-27', $new->due_date->toDateString());
        $this->assertEqualsWithDelta(60000.00, (float) $new->principal_due, 0.001);
        $this->assertEqualsWithDelta(3 * self::INTEREST, (float) $new->interest_due, 0.001);
        $this->assertEqualsWithDelta(60000.00 + 3 * self::INTEREST, (float) $new->total_due, 0.001);

        $values = $adjustment->new_values;
        $this->assertSame(3, $values['cycles']);
        $this->assertEqualsWithDelta(0.00, (float) $values['carry_interest'], 0.001);
        $this->assertEqualsWithDelta(3 * self::INTEREST, (float) $values['fresh_interest'], 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $values['interest_paid'], 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $values['penalty_paid'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $values['insurance_paid'], 0.001);
        $this->assertEqualsWithDelta(self::PENALTY + self::INTEREST, (float) $values['amount_collected'], 0.001);
        $this->assertSame($repayment->id, $values['repayment_id']);
    }

    public function test_pay_with_premium_owed_collects_the_premium_the_penalty_and_all_the_interest(): void
    {
        $this->travelTo('2026-11-20 09:00:00');
        // Due ten days ago: past grace, one cycle to 10 Dec.
        $loan = $this->oneMonthLoanOwingPremium(now()->subDays(10)->toDateString());

        $adjustment = $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertEqualsWithDelta(self::OWED + self::PENALTY + self::INTEREST, (float) $repayment->amount_paid, 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->principal_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);

        $new = $this->newPeriod($loan);
        $this->assertEqualsWithDelta(60000.00, (float) $new->principal_due, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $new->interest_due, 0.001);

        $values = $adjustment->new_values;
        $this->assertEqualsWithDelta(0.00, (float) $values['carry_interest'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $values['insurance_paid'], 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $values['penalty_paid'], 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $values['interest_paid'], 0.001);
        $this->assertEqualsWithDelta(self::OWED + self::PENALTY + self::INTEREST, (float) $values['amount_collected'], 0.001);
    }

    public function test_pay_before_the_due_date_collects_the_interest_as_before(): void
    {
        $loan = $this->oneMonthLoan('2026-05-20');

        $adjustment = $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertEqualsWithDelta(self::INTEREST, (float) $repayment->amount_paid, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $repayment->interest_applied, 0.001);

        $new = $this->newPeriod($loan);
        $this->assertSame('2026-06-27', $new->due_date->toDateString());
        $this->assertEqualsWithDelta(self::INTEREST, (float) $new->interest_due, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $adjustment->new_values['penalty_paid'], 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $adjustment->new_values['amount_collected'], 0.001);
    }

    public function test_the_pay_preview_shows_each_part_and_equals_the_extension_to_the_centavo(): void
    {
        $this->travelTo('2026-11-20 09:00:00');
        $loan = $this->oneMonthLoanOwingPremium(now()->subDays(10)->toDateString());

        $preview = $this->preview($loan, 'pay');

        $this->assertSame(
            ['amount' => 3400.0, 'insurance_part' => 400.0, 'penalty_part' => 1200.0, 'interest_part' => 1800.0],
            array_map('floatval', $preview['collect_now']),
        );
        $this->assertEqualsWithDelta(0.00, (float) $preview['carry_interest'], 0.001);

        $adjustment = $this->extend($loan, 'pay');
        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $new = $this->newPeriod($loan);

        $this->assertSame(
            [
                'amount' => (float) $repayment->amount_paid,
                'insurance_part' => (float) $repayment->insurance_applied,
                'penalty_part' => (float) $repayment->penalty_applied,
                'interest_part' => (float) $repayment->interest_applied,
            ],
            array_map('floatval', $preview['collect_now']),
        );
        $this->assertEquals((float) $adjustment->new_values['amount_collected'], (float) $preview['collect_now']['amount']);
        $this->assertEquals((float) $adjustment->new_values['carry_principal'], (float) $preview['carry_principal']);
        $this->assertEquals((float) $adjustment->new_values['carry_interest'], (float) $preview['carry_interest']);
        $this->assertEquals((float) $adjustment->new_values['fresh_interest'], (float) $preview['fresh_interest']);
        $this->assertEquals((float) $new->interest_due, (float) $preview['new_interest_due']);
        $this->assertEquals((float) $new->total_due, (float) $preview['new_total_due']);
        $this->assertSame($new->due_date->toDateString(), $preview['new_due_date']);
    }

    public function test_defer_collects_nothing_and_stacks_the_interest_as_before(): void
    {
        $loan = $this->oneMonthLoan('2026-07-30');

        $preview = $this->preview($loan, 'defer');
        $this->assertEquals(['amount' => 0, 'insurance_part' => 0, 'penalty_part' => 0, 'interest_part' => 0], $preview['collect_now']);

        $adjustment = $this->extend($loan, 'defer');

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $new = $this->newPeriod($loan);
        $this->assertEqualsWithDelta(self::INTEREST + 3 * self::INTEREST, (float) $new->interest_due, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $adjustment->new_values['carry_interest'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $adjustment->new_values['interest_paid'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $adjustment->new_values['penalty_paid'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $adjustment->new_values['amount_collected'], 0.001);
        $this->assertNull($adjustment->new_values['repayment_id']);
    }

    public function test_the_ledger_and_the_summary_agree_after_a_pay_extension(): void
    {
        $loan = $this->oneMonthLoan('2026-07-30');
        $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $ledger = $this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data');
        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');

        $payment = collect($ledger['rows'])->firstWhere('repayment_id', $repayment->id);
        $this->assertNotNull($payment, 'the collection has a ledger row');
        $this->assertEqualsWithDelta(self::PENALTY, $payment['penalty'], 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, $payment['interest'], 0.001);

        $last = end($ledger['rows']);
        $this->assertEqualsWithDelta($summary['outstanding_principal'], $last['balances']['principal'], 0.001);
        $this->assertEqualsWithDelta($summary['outstanding_interest'], $last['balances']['interest'], 0.001);
        $this->assertEqualsWithDelta($summary['outstanding_penalty'], $last['balances']['penalty'], 0.001);
        $this->assertEqualsWithDelta(60000.00, (float) $summary['outstanding_principal'], 0.001);
        $this->assertEqualsWithDelta(3 * self::INTEREST, (float) $summary['outstanding_interest'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $summary['outstanding_penalty'], 0.001);
    }

    public function test_the_extension_collection_cannot_be_voided(): void
    {
        $loan = $this->oneMonthLoan('2026-07-30');
        $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('repayment');

        $this->assertNull($repayment->fresh()->voided_at);
    }

    public function test_a_waived_penalty_is_not_collected(): void
    {
        $loan = $this->oneMonthLoan('2026-07-30');
        app(RepaymentService::class)->applyPenalties($loan, now());
        $this->applyAdjustment($loan, 'penalty_waiver', ['waive_all' => true]);

        $preview = $this->preview($loan->fresh(), 'pay');
        $adjustment = $this->extend($loan->fresh(), 'pay');

        $this->assertPreviewEqualsRecorded($preview, $adjustment, ['amount' => 1800.00, 'insurance' => 0.00, 'penalty' => 0.00, 'interest' => 1800.00]);
    }

    public function test_a_partly_paid_penalty_collects_only_what_is_left_of_it(): void
    {
        // ₱1,200.00 charged, ₱500.00 paid today: ₱700.00 + ₱1,800.00.
        $loan = $this->oneMonthLoan('2026-07-30');
        $first = $this->pay($loan, 500.00);
        $this->assertEqualsWithDelta(500.00, (float) $first->penalty_applied, 0.001, 'setup: the ₱500.00 went to penalty');

        $preview = $this->preview($loan->fresh(), 'pay');
        $adjustment = $this->extend($loan->fresh(), 'pay');

        $this->assertPreviewEqualsRecorded($preview, $adjustment, ['amount' => 2500.00, 'insurance' => 0.00, 'penalty' => 700.00, 'interest' => 1800.00]);
    }

    public function test_an_overdue_loan_inside_its_grace_collects_no_penalty(): void
    {
        // Due 27 May, 3-day grace: on 29 May nothing is charged yet.
        $loan = $this->oneMonthLoan('2026-05-29');

        $preview = $this->preview($loan, 'pay');
        $adjustment = $this->extend($loan, 'pay');

        $this->assertPreviewEqualsRecorded($preview, $adjustment, ['amount' => 1800.00, 'insurance' => 0.00, 'penalty' => 0.00, 'interest' => 1800.00]);
        $this->assertSame(1, $adjustment->new_values['cycles']);
        $this->assertSame('2026-06-27', $adjustment->new_values['new_due_date']);
    }

    public function test_odd_centavos_are_collected_exactly(): void
    {
        // ₱61,234.57 at 3%: ₱1,837.04 interest; 2% penalty: ₱1,224.69.
        $loan = $this->oneMonthLoan('2026-07-30', 61234.57);

        $preview = $this->preview($loan, 'pay');
        $adjustment = $this->extend($loan, 'pay');

        $this->assertPreviewEqualsRecorded($preview, $adjustment, ['amount' => 3061.73, 'insurance' => 0.00, 'penalty' => 1224.69, 'interest' => 1837.04]);
        $this->assertEqualsWithDelta(61234.57, (float) $this->newPeriod($loan)->principal_due, 0.001);
    }

    public function test_a_loan_with_more_than_one_open_period_cannot_be_extended(): void
    {
        $loan = $this->oneMonthLoan('2026-05-20');
        $only = $loan->amortizationSchedules()->sole();
        AmortizationSchedule::create([
            'loan_id' => $loan->id,
            'period_number' => 2,
            'due_date' => '2026-06-27',
            'principal_due' => 1000,
            'interest_due' => 30,
            'total_due' => 1030,
            'remaining_balance' => 0,
            'status' => 'pending',
        ]);
        $only->update(['principal_due' => 59000, 'total_due' => 60800]);

        foreach (['pay', 'defer'] as $option) {
            $this->assertExtensionRefused($loan, $option, 'This loan has more than one open period, so it cannot be extended.');
        }
    }

    public function test_a_loan_whose_principal_is_paid_cannot_be_extended(): void
    {
        // The principal paid, the interest still owed: "pay" would settle the
        // period and leave nothing to carry, "defer" would carry ₱0.00.
        $loan = $this->oneMonthLoan('2026-05-20');
        $loan->amortizationSchedules()->update(['principal_paid' => 60000, 'status' => 'partial']);

        foreach (['pay', 'defer'] as $option) {
            $this->assertExtensionRefused($loan, $option, 'Nothing is left to extend: the principal is already paid.');
        }
        $this->assertSame('released', $loan->fresh()->status);
    }

    /**
     * How the swapped-in service misreports the allocation: ₱0.01 moved
     * from penalty to interest (the total unchanged), or ₱0.01 reported on
     * principal, which an extension's collection never reaches.
     *
     * @return array<string, array{Closure(Repayment): void}>
     */
    public static function offQuoteApplications(): array
    {
        return [
            'penalty to interest' => [function (Repayment $repayment): void {
                $repayment->penalty_applied = (float) $repayment->penalty_applied - 0.01;
                $repayment->interest_applied = (float) $repayment->interest_applied + 0.01;
            }],
            'a centavo on principal' => [function (Repayment $repayment): void {
                $repayment->principal_applied = (float) $repayment->principal_applied + 0.01;
            }],
        ];
    }

    /**
     * @param  Closure(Repayment): void  $misreport
     */
    #[DataProvider('offQuoteApplications')]
    public function test_a_collection_applied_differently_from_its_quote_is_logged_and_refused(Closure $misreport): void
    {
        $this->travelTo('2026-11-20 09:00:00');
        // Owes the ₱400.00 premium, a penalty and the interest.
        $loan = $this->oneMonthLoanOwingPremium(now()->subDays(10)->toDateString());
        $this->app->instance(RepaymentService::class, new class($misreport) extends RepaymentService
        {
            public function __construct(private Closure $misreport) {}

            public function recordRepaymentOnLockedLoan(
                Loan $loan,
                float $amountPaid,
                string $paymentDate,
                User $user,
                ?string $remarks = null,
                string $method = 'cash',
                ?string $referenceNumber = null,
                bool $postToBooks = true,
                bool $preview = false,
            ): Repayment {
                $repayment = parent::recordRepaymentOnLockedLoan($loan, $amountPaid, $paymentDate, $user, $remarks, $method, $referenceNumber, $postToBooks, $preview);
                ($this->misreport)($repayment);

                return $repayment;
            }
        });
        Log::spy();
        $maturityBefore = $loan->maturity_date->toDateString();

        $this->assertExtensionRefused($loan, 'pay', 'The amount to collect could not be applied as quoted, so nothing was charged. Refresh and try again.');

        Log::shouldHaveReceived('error')
            ->with('Loan extension: the collection did not apply what was quoted; extension refused.', \Mockery::type('array'))
            ->twice();
        $this->assertEqualsWithDelta(self::OWED, $this->owed($loan), 0.001);
        $this->assertSame($maturityBefore, $loan->fresh()->maturity_date->toDateString());
    }
}
