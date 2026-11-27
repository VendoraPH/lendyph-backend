<?php

namespace Tests\Feature\FzReports;

use App\Models\Loan;
use App\Models\LoanAdjustment;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Loan::adjustments() carries latest(), so an orderBy after it still reads
 * newest first. With two rebuilds at different times the Ledger took the last
 * one for its "first rebuild", and rebuilt the original schedule from the wrong
 * terms. The first rebuild's `old_values` are the only record of them.
 *
 * ₱61,234.57 over 6 monthly periods at 3% straight: ₱11,022.24 interest over the
 * schedule.
 */
class AdjustmentsReadOldestFirstTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    private const PRINCIPAL = 61234.57;

    private const SCHEDULE_INTEREST = 11022.24;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-01-15');
        $this->travelTo(Carbon::parse('2026-02-10 09:00'));
        $this->payLoan($this->loan, 12042.80);
        $this->applyAdjustment('restructure', ['term' => 4]);
        $this->travelTo(Carbon::parse('2026-03-10 09:00'));
        $this->applyAdjustment('term_extension', ['additional_terms' => 2]);
    }

    public function test_the_release_line_reads_the_first_rebuild_s_reference(): void
    {
        $ledger = $this->getJson("/api/loans/{$this->loan->id}/ledger")->assertOk()->json('data');

        $this->assertSame('release', $ledger['rows'][0]['kind']);
        $this->assertEquals(self::SCHEDULE_INTEREST, $ledger['rows'][0]['interest']);
    }

    public function test_the_ledger_closes_on_the_loan_summary(): void
    {
        $ledger = $this->getJson("/api/loans/{$this->loan->id}/ledger")->assertOk()->json('data');
        $summary = $this->getJson("/api/loans/{$this->loan->id}/summary")->assertOk()->json('data');
        $last = end($ledger['rows']);

        foreach (['principal', 'interest', 'penalty'] as $column) {
            $this->assertEquals($summary["outstanding_{$column}"], $ledger['closing'][$column], "closing {$column}");
            $this->assertEquals($summary["outstanding_{$column}"], $last['balances'][$column], "last row {$column}");
        }
    }

    public function test_the_statement_closes_and_its_note_reads_oldest_first(): void
    {
        $data = $this->getJson("/api/reports/statement-of-account/{$this->loan->id}")->assertOk()->json('data');
        $ledger = $this->getJson("/api/loans/{$this->loan->id}/ledger")->assertOk()->json('data');
        $lines = collect($data['transactions']);

        $this->assertEquals(round(self::PRINCIPAL + self::SCHEDULE_INTEREST, 2), $lines->firstWhere('kind', 'release')['debit']);
        $this->assertEquals($ledger['closing']['principal'], $lines->last()['principal_balance']);
        $this->assertEquals(
            $data['summary']['outstanding_balance'],
            round($data['transaction_totals']['debit'] - $data['transaction_totals']['credit'], 2),
        );

        $restructure = LoanAdjustment::where('loan_id', $this->loan->id)->where('adjustment_type', 'restructure')->value('adjustment_number');
        $extension = LoanAdjustment::where('loan_id', $this->loan->id)->where('adjustment_type', 'term_extension')->value('adjustment_number');

        $this->assertSame(
            "This schedule was restated by restructure {$restructure} on Feb 10, 2026 and term extension {$extension} on Mar 10, 2026.",
            $data['schedule_note'],
        );
    }

    public function test_the_member_ledger_closes_on_the_loan_summary(): void
    {
        $meta = $this->getJson("/api/borrowers/{$this->loan->borrower_id}/ledger")->assertOk()->json('meta');
        $summary = $this->getJson("/api/loans/{$this->loan->id}/summary")->assertOk()->json('data');

        foreach (['principal', 'interest', 'penalty'] as $column) {
            $this->assertEquals($summary["outstanding_{$column}"], $meta['closing'][$column], "closing {$column}");
        }
    }

    /** @param  array<string, mixed>  $newValues */
    private function applyAdjustment(string $type, array $newValues): void
    {
        $id = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => $type,
            'new_values' => $newValues,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();
    }
}
