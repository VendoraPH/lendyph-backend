<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Loan;
use Tests\Feature\Ledger\Release\ReleasesLoans;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A restructured source loan's Contract Total is its original contract: every
 * row it was released with, closed ones included (Lead's call 8).
 *
 * A restructure into a new loan deletes the source's unpaid periods and
 * shrinks a part-paid one to what was collected (LoanService::
 * clearOpenSchedules()), so the rows left add up to what was paid, not to the
 * contract. The figure is the schedule the loan was released with, built
 * again by the engine that built it, as its Ledger's release line is
 * (LoanLedgerService).
 *
 * createReleasedLoan(): ₱60,000.00 over 6 monthly periods at 3% straight,
 * ₱11,800.00 a period, ₱70,800.00 principal plus interest.
 */
class RestructuredSourceContractTotalTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    private const CONTRACT_TOTAL = 70800.00;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** A source with period 1 paid, then restructured at what it still owed. */
    private function restructuredSource(): Loan
    {
        $source = $this->createReleasedLoan();
        $this->payOn($source, 11800);
        $new = $this->approvedRestructureOf($source, 59000);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $source = $source->fresh();
        $this->assertSame('restructured', $source->status);

        return $source;
    }

    public function test_a_restructured_source_shows_its_original_contract_total(): void
    {
        $source = $this->restructuredSource();
        $this->assertSame(1, $source->amortizationSchedules()->count(), 'only the paid period is left on the source');

        $this->assertContractTotal(self::CONTRACT_TOTAL, $source);
    }

    public function test_a_source_restructured_with_a_part_paid_period_still_shows_its_original_contract_total(): void
    {
        $source = $this->createReleasedLoan();
        // Period 1 in full and ₱5,000.00 of period 2: period 2 is closed at what was collected.
        $this->payOn($source, 16800);
        $new = $this->approvedRestructureOf($source, 54000);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $this->assertSame(2, $source->amortizationSchedules()->count());
        $this->assertContractTotal(self::CONTRACT_TOTAL, $source);
    }

    /**
     * A list never builds a schedule per row, so a restructured source's row
     * has no Contract Total rather than the rows that are left.
     */
    public function test_a_list_row_of_a_restructured_source_has_no_contract_total(): void
    {
        $source = $this->restructuredSource();

        $row = collect($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'))->firstWhere('id', $source->id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('contract_total', $row);
        $this->assertNull($row['contract_total']);
    }

    /** The new loan is an ordinary released loan: its own stored schedule. */
    public function test_the_new_loan_keeps_its_own_stored_schedules_contract_total(): void
    {
        $source = $this->restructuredSource();
        $new = Loan::where('source_loan_id', $source->id)->sole();

        $schedule = $new->amortizationSchedules()->get();
        $expected = ($schedule->sum(fn ($row) => (int) round((float) $row->principal_due * 100))
            + $schedule->sum(fn ($row) => (int) round((float) $row->interest_due * 100))) / 100;

        $this->assertContractTotal($expected, $new);
    }

    /** GET /loans/{id}'s `contract_total`, compared as pesos: whole pesos serialize as an integer. */
    private function assertContractTotal(float|int $expected, Loan $loan): void
    {
        $total = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.contract_total');

        $this->assertNotNull($total);
        $this->assertSame(round((float) $expected, 2), round((float) $total, 2));
    }
}
