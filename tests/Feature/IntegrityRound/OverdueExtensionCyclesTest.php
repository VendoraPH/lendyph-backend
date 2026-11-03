<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Decision 4a (Arvin, 2026-11-02): extending an overdue loan moves its due
 * date to the first anchored date strictly after the extension date, and
 * charges one cycle of fresh interest per anchored cycle the due date moves
 * (at least 1). The preview quotes `cycles`, the extension records it in
 * `new_values`, and on "pay" the late penalty is still taken first, and
 * collected on top of the interest (Arvin, 2026-11-03).
 *
 * The loan: ₱60,000 for one month at 3% upon maturity, started 27 Apr 2026
 * and due 27 May (₱1,800 interest a cycle).
 */
class OverdueExtensionCyclesTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function oneMonthLoan(string $startDate = '2026-04-27', float $principal = 60000, float $rate = 3.0): Loan
    {
        $this->travelTo($startDate.' 09:00:00');

        return $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => $rate],
            'principal_amount' => $principal,
            'start_date' => $startDate,
        ]);
    }

    public function test_an_overdue_extension_charges_one_cycle_of_interest_per_cycle_moved(): void
    {
        $loan = $this->oneMonthLoan();
        $this->travelTo('2026-11-02 10:00:00');

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")
            ->assertOk()
            ->json('data');

        // 27 May -> 27 Nov: six anchored cycles, six cycles of ₱1,800.
        $this->assertSame(6, $preview['cycles']);
        $this->assertSame('2026-11-27', $preview['new_due_date']);
        $this->assertEqualsWithDelta(10800.00, (float) $preview['fresh_interest'], 0.001);
        $this->assertEqualsWithDelta(1800.00 + 10800.00, (float) $preview['new_interest_due'], 0.001);
        $this->assertEqualsWithDelta(60000.00 + 12600.00, (float) $preview['new_total_due'], 0.001);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $adjustment = LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->sole();
        $this->assertSame(6, $adjustment->new_values['cycles']);
        $this->assertEqualsWithDelta(10800.00, (float) $adjustment->new_values['fresh_interest'], 0.001);
        $this->assertSame('2026-11-27', $adjustment->new_values['new_due_date']);
        $this->assertSame('Loan extended by 6 cycles.', $adjustment->description);

        $openRow = $loan->fresh()->amortizationSchedules()->where('status', 'pending')->sole();
        $this->assertSame('2026-11-27', $openRow->due_date->toDateString());
        $this->assertEqualsWithDelta(12600.00, (float) $openRow->interest_due, 0.001);
        $this->assertEqualsWithDelta(72600.00, (float) $openRow->total_due, 0.001);
    }

    public function test_on_pay_the_late_penalty_is_taken_first_and_collected_on_top_of_the_interest(): void
    {
        $loan = $this->oneMonthLoan();
        $this->travelTo('2026-11-02 10:00:00');

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=pay")
            ->assertOk()
            ->json('data');

        $this->assertSame(6, $preview['cycles']);
        $this->assertGreaterThan(0, (float) $preview['collect_now']['penalty_part'], 'setup: the overdue loan owes a late penalty');
        // The penalty and the whole ₱1,800 interest are collected, so no
        // interest carries.
        $this->assertEqualsWithDelta(
            1800.00 + (float) $preview['collect_now']['penalty_part'],
            (float) $preview['collect_now']['amount'],
            0.001,
        );
        $this->assertEqualsWithDelta(1800.00, (float) $preview['collect_now']['interest_part'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $preview['carry_interest'], 0.001);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertEqualsWithDelta((float) $preview['collect_now']['penalty_part'], (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta((float) $preview['collect_now']['interest_part'], (float) $repayment->interest_applied, 0.001);

        $adjustment = LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->sole();
        $this->assertSame(6, $adjustment->new_values['cycles']);
        $this->assertEqualsWithDelta(10800.00, (float) $adjustment->new_values['fresh_interest'], 0.001);
        $this->assertEqualsWithDelta((float) $preview['new_total_due'], (float) $loan->fresh()->amortizationSchedules()->where('status', 'pending')->sole()->total_due, 0.001);
    }

    public function test_an_extension_on_or_just_after_the_due_date_moves_one_cycle(): void
    {
        $loan = $this->oneMonthLoan();

        foreach (['2026-05-27', '2026-05-28', '2026-06-26'] as $day) {
            $this->travelTo($day.' 10:00:00');

            $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")->assertOk()->json('data');

            $this->assertSame(1, $preview['cycles'], "extended on {$day}");
            $this->assertSame('2026-06-27', $preview['new_due_date'], "extended on {$day}");
            $this->assertEqualsWithDelta(1800.00, (float) $preview['fresh_interest'], 0.001, "extended on {$day}");
        }
    }

    public function test_an_extension_on_the_next_anchored_date_moves_past_it(): void
    {
        $loan = $this->oneMonthLoan();
        $this->travelTo('2026-06-27 10:00:00');

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")->assertOk()->json('data');

        $this->assertSame(2, $preview['cycles']);
        $this->assertSame('2026-07-27', $preview['new_due_date']);
        $this->assertEqualsWithDelta(3600.00, (float) $preview['fresh_interest'], 0.001);
    }

    public function test_a_loan_anchored_on_the_31st_keeps_its_month_end_cap(): void
    {
        // Started 31 Jan, due 28 Feb. Extended on 31 Mar: 31 Mar is not after
        // the extension date, so the due date moves two cycles, to 30 Apr.
        $loan = $this->oneMonthLoan('2026-01-31');
        $this->assertSame('2026-02-28', $loan->maturity_date->toDateString(), 'setup: capped at February\'s end');
        $this->travelTo('2026-03-31 10:00:00');

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")->assertOk()->json('data');

        $this->assertSame(2, $preview['cycles']);
        $this->assertSame('2026-04-30', $preview['new_due_date']);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();
        $this->assertSame('2026-04-30', $loan->fresh()->maturity_date->toDateString());
    }

    public function test_one_cycle_is_rounded_to_the_centavo_then_multiplied(): void
    {
        // ₱33,333.00 at 2.5%: one cycle is ₱833.325, ₱833.33 to the centavo.
        // Due 27 May and extended on 1 Aug, it moves three cycles, to 27 Aug:
        // 3 × ₱833.33 = ₱2,499.99, not ₱2,499.98 (₱833.325 × 3, rounded once).
        $loan = $this->oneMonthLoan(principal: 33333, rate: 2.5);
        $this->travelTo('2026-08-01 10:00:00');

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")->assertOk()->json('data');

        $this->assertSame(3, $preview['cycles']);
        $this->assertSame('2026-08-27', $preview['new_due_date']);
        $this->assertEqualsWithDelta(2499.99, (float) $preview['fresh_interest'], 0.001);
        $this->assertEqualsWithDelta((float) $preview['carry_interest'] + 2499.99, (float) $preview['new_interest_due'], 0.001);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $adjustment = LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->sole();
        $this->assertSame(3, $adjustment->new_values['cycles']);
        $this->assertEqualsWithDelta(2499.99, (float) $adjustment->new_values['fresh_interest'], 0.001);
    }
}
