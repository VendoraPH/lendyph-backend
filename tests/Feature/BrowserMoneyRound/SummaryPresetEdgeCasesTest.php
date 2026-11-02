<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Items 1, 3 and 5, the edges of GET /loans/{id}/summary's figures:
 * `advance_presets` read only the open periods (never one a restructure,
 * term extension or extension closed) and come back whole after a void; a
 * loan that takes no payments gets none; the deprecated `outstanding_balance`
 * keeps each endpoint's old meaning; and the payment preview's
 * `total_outstanding_before` counts a penalty the payment charges that was
 * not stamped yet.
 *
 * Non-round, whole-centavo figures: ₱61,234.57 over 6 monthly periods at 3%
 * straight is ₱10,205.76 principal (₱10,205.77 on the last) and ₱1,837.04
 * interest a period. Paying a preset is checked by the periods' statuses.
 */
class SummaryPresetEdgeCasesTest extends TestCase
{
    use BuildsReleasedLoans, OwesInsurancePremium, SetupLendyPH;

    private const PRINCIPAL = 61234.57;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_presets_after_a_restructure_read_only_the_open_periods(): void
    {
        $loan = $this->loanWithAClosedPeriod('restructure', ['term' => 4]);

        $this->assertPresetsSkipTheClosedPeriod($loan);
    }

    public function test_presets_after_a_term_extension_read_only_the_open_periods(): void
    {
        $loan = $this->loanWithAClosedPeriod('term_extension', ['additional_terms' => 2]);

        $this->assertPresetsSkipTheClosedPeriod($loan);
    }

    public function test_an_extended_upon_maturity_loan_presets_the_open_row(): void
    {
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, product: [
            'interest_method' => 'upon_maturity',
            'term' => 1,
            'frequency' => 'monthly',
        ]);
        // Part of the interest, before maturity: no penalty takes part of it.
        $this->payLoan($loan, 500.37);
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $closed = $this->period($loan, 1);
        $open = $this->openPeriods($loan);
        $this->assertSame('paid', $closed->status, 'setup: the extension closed period 1 at what was paid');
        $this->assertCount(1, $open, 'setup: one open row after the extension');

        $summary = $this->summaryOf($loan);

        $this->assertCount(1, $summary['advance_presets']);
        $this->assertEqualsWithDelta($this->owedOn($open->first()) / 100, (float) $summary['advance_presets'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta((float) $summary['payoff_amount'], (float) $summary['advance_presets'][0]['amount'], 0.001);

        $this->payLoan($loan, (float) $summary['advance_presets'][0]['amount']);

        $this->assertSame('paid', $open->first()->fresh()->status);
        $this->assertSame('completed', $loan->fresh()->status);
    }

    public function test_presets_come_back_whole_after_a_void(): void
    {
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL);
        $before = $this->summaryOf($loan)['advance_presets'];
        $this->assertCount(6, $before);

        $repayment = $this->payLoan($loan, (float) $before[1]['amount']);
        $this->assertSame(['paid', 'paid', 'pending'], $this->statuses($loan, [1, 2, 3]));
        $this->assertCount(4, $this->summaryOf($loan)['advance_presets']);

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();

        $this->assertSame($before, $this->summaryOf($loan)['advance_presets']);
    }

    public function test_a_loan_that_takes_no_payments_gets_no_presets(): void
    {
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL);
        $loan->update(['status' => 'defaulted']);

        $summary = $this->summaryOf($loan);

        $this->assertSame([], $summary['advance_presets']);
        $this->assertNull($summary['payoff_amount']);
        // What is owed is still stated.
        $this->assertEqualsWithDelta(72256.81, (float) $summary['total_outstanding'], 0.001);

        // A payment is refused there.
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => 100.37,
            'payment_date' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_the_deprecated_outstanding_balance_keeps_each_endpoints_meaning(): void
    {
        $loan = $this->loanOwingPremium();
        // ₱123.45 goes to the ₱400 premium owed, leaving ₱276.55 of it.
        $this->pay($loan, 123.45);

        $show = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');
        $row = collect($this->getJson("/api/loans?borrower_id={$loan->borrower_id}&per_page=100")->assertOk()->json('data'))
            ->firstWhere('id', $loan->id);
        $summary = $this->summaryOf($loan);

        // LoanResource: unpaid principal plus the premium owed.
        $this->assertEqualsWithDelta(60276.55, (float) $show['outstanding_balance'], 0.001);
        $this->assertEqualsWithDelta(60276.55, (float) $row['outstanding_balance'], 0.001);
        // The summary: everything owed, its `total_outstanding`.
        $this->assertEqualsWithDelta(71076.55, (float) $summary['outstanding_balance'], 0.001);
        $this->assertEqualsWithDelta((float) $summary['total_outstanding'], (float) $summary['outstanding_balance'], 0.001);
        $this->assertEqualsWithDelta((float) $summary['total_outstanding'], (float) $show['total_outstanding'], 0.001);
        $this->assertEqualsWithDelta(276.55, (float) $summary['insurance_owed'], 0.001);
    }

    public function test_the_preview_counts_a_penalty_the_payment_charges_that_was_not_stamped_yet(): void
    {
        // Period 1 fell due on 2026-02-10; ten days on it is past the 3-day
        // grace, and nothing has stamped its penalty: 2% of ₱10,205.76.
        $this->travelTo(Carbon::parse('2026-02-20 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-01-10');
        $period1 = $this->period($loan, 1);
        $this->assertSame('2026-02-10', $period1->due_date->toDateString(), 'setup: period 1 is late');
        $this->assertEqualsWithDelta(0.0, (float) $period1->penalty_amount, 0.001, 'setup: no penalty stamped yet');

        $summary = $this->summaryOf($loan);
        $stored = (float) $summary['total_outstanding'];
        $this->assertEqualsWithDelta(72256.81, $stored, 0.001);
        $this->assertEqualsWithDelta($stored + 204.12, (float) $summary['payoff_amount'], 0.001);

        $preview = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => 5000.37,
            'payment_date' => '2026-02-20',
        ])->assertOk()->json('data');

        $this->assertEqualsWithDelta(204.12, (float) $preview['total_penalty'], 0.001);
        $this->assertEqualsWithDelta($stored + 204.12, (float) $preview['total_outstanding_before'], 0.001);
        $this->assertEqualsWithDelta($stored + 204.12 - 5000.37, (float) $preview['total_outstanding_after'], 0.001);
    }

    /**
     * Pays period 1 in full and period 2 in part, before either falls due,
     * then applies `$type`, which closes period 2 at what it was paid.
     *
     * @param  array<string, mixed>  $newValues
     */
    private function loanWithAClosedPeriod(string $type, array $newValues): Loan
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-01-15');

        $this->travelTo(Carbon::parse('2026-02-10 09:00'));
        $this->payLoan($loan, (float) $this->summaryOf($loan)['advance_presets'][0]['amount']);
        $this->payLoan($loan, 5000.37);
        $this->assertSame(['paid', 'partial'], $this->statuses($loan, [1, 2]), 'setup: period 2 part paid');

        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => $type,
            'new_values' => $newValues,
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $closed = $this->period($loan, 2);
        $this->assertSame('paid', $closed->status, 'setup: period 2 is closed');
        $this->assertNotNull($closed->closed_by_adjustment_id, 'setup: closed by the adjustment');

        return $loan;
    }

    private function assertPresetsSkipTheClosedPeriod(Loan $loan): void
    {
        $open = $this->openPeriods($loan);
        $this->assertGreaterThanOrEqual(3, $open->count(), 'setup: open periods after the adjustment');
        $this->assertTrue($open->every(fn (AmortizationSchedule $s) => $s->period_number > 2));

        $summary = $this->summaryOf($loan);
        $presets = $summary['advance_presets'];

        $this->assertCount($open->count(), $presets);
        $this->assertEqualsWithDelta($this->owedOn($open[0]) / 100, (float) $presets[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(($this->owedOn($open[0]) + $this->owedOn($open[1])) / 100, (float) $presets[1]['amount'], 0.001);
        $this->assertEqualsWithDelta((float) $summary['payoff_amount'], (float) end($presets)['amount'], 0.001);

        $closedBefore = $this->period($loan, 2)->only(['principal_paid', 'interest_paid', 'status']);
        $this->payLoan($loan, (float) $presets[1]['amount']);

        $this->assertSame(['paid', 'paid', 'pending'], $this->statuses($loan, $open->take(3)->pluck('period_number')->all()));
        $this->assertSame($closedBefore, $this->period($loan, 2)->only(['principal_paid', 'interest_paid', 'status']));
    }

    /** @return Collection<int, AmortizationSchedule> */
    private function openPeriods(Loan $loan): Collection
    {
        return AmortizationSchedule::where('loan_id', $loan->id)
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->orderBy('period_number')
            ->get()
            ->values();
    }

    private function period(Loan $loan, int $number): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', $number)->sole();
    }

    /**
     * @param  list<int>  $numbers
     * @return list<string>
     */
    private function statuses(Loan $loan, array $numbers): array
    {
        return array_map(fn (int $n): string => $this->period($loan, $n)->status, $numbers);
    }

    /** What a period still owes, penalty included, in whole centavos. */
    private function owedOn(AmortizationSchedule $period): int
    {
        $c = fn (mixed $pesos): int => LoanService::toCentavos((float) $pesos);

        return max(0, $c($period->principal_due) - $c($period->principal_paid))
            + max(0, $c($period->interest_due) - $c($period->interest_paid))
            + max(0, $c($period->penalty_amount) - $c($period->penalty_paid));
    }
}
