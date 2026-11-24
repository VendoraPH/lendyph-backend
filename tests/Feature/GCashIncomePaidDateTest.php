<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The GCash income report counts a Cash In's charge on the day it was paid
 * (`paid_at`, a Manila day), the date AutomaticPoster books its 4090 credit,
 * and a Cash Out's on the day it was recorded, as before. A pending Cash In's
 * charge is income in no period (Finish run round 2, decision 2).
 *
 * Before, every charge counted on `transaction_date`: a Cash In recorded on
 * the 30th and paid on the 2nd counted in the month the books never saw it.
 */
class GCashIncomePaidDateTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Borrower $member;

    /** Each recording's amount differs, so none reads as a double-tap duplicate. */
    private int $amount = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    }

    public function test_a_cash_in_recorded_on_the_30th_and_paid_on_the_2nd_counts_on_the_2nd(): void
    {
        $settled = $this->record('2026-10-30 10:00:00', 'cash_in', pending: true);
        $this->markPaid($settled, '2026-11-02 09:15:00');

        $october30 = $this->income('2026-10-30', '2026-10-31');
        $this->assertEquals(0.0, $october30['total_income']);
        $this->assertSame(0, $october30['cash_in_count']);
        $this->assertNotContains($settled->id, array_column($october30['transactions'], 'id'));

        $november2 = $this->income('2026-11-02', '2026-11-02');
        $this->assertEquals(20.0, $november2['total_income']);
        $this->assertSame(1, $november2['cash_in_count']);
        $this->assertSame([$settled->id], array_column($november2['transactions'], 'id'));
    }

    public function test_the_paid_day_is_a_manila_day(): void
    {
        $early = $this->record('2026-11-01 18:00:00', 'cash_in', pending: true);
        $this->markPaid($early, '2026-11-02 00:30:00');

        $this->assertEquals(0.0, $this->income('2026-11-01', '2026-11-01')['total_income']);
        $this->assertEquals(20.0, $this->income('2026-11-02', '2026-11-02')['total_income']);
    }

    public function test_a_pending_cash_in_is_income_in_no_period(): void
    {
        $pending = $this->record('2026-10-30 10:00:00', 'cash_in', pending: true);
        $this->travelTo(Carbon::parse('2026-12-31 12:00:00'));

        $year = $this->income('2026-01-01', '2026-12-31');

        $this->assertEquals(0.0, $year['total_income']);
        $this->assertSame(0, $year['cash_in_count']);
        $this->assertNotContains($pending->id, array_column($year['transactions'], 'id'));
    }

    public function test_a_cash_out_and_a_cash_in_paid_at_the_counter_count_on_their_own_day(): void
    {
        $cashOut = $this->record('2026-10-30 10:00:00', 'cash_out');
        $counter = $this->record('2026-10-31 23:30:00', 'cash_in');

        $report = $this->income('2026-10-30', '2026-10-31');

        $this->assertEquals(35.0, $report['total_income']);
        $this->assertSame(1, $report['cash_in_count']);
        $this->assertSame(1, $report['cash_out_count']);
        $this->assertSame([$cashOut->id, $counter->id], array_column($report['transactions'], 'id'));
        $this->assertEquals(0.0, $this->income('2026-11-01', '2026-11-01')['total_income']);
    }

    public function test_the_report_agrees_with_the_4090_postings_for_each_period(): void
    {
        $this->record('2026-10-05 09:00:00', 'cash_out');
        $this->record('2026-10-12 11:00:00', 'cash_in');
        $settled = $this->record('2026-10-30 10:00:00', 'cash_in', pending: true);
        $this->record('2026-10-31 15:00:00', 'cash_in', pending: true);
        $this->markPaid($settled, '2026-11-02 09:15:00');
        $this->record('2026-11-03 14:00:00', 'cash_out');

        foreach ([['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-11-30'], ['2026-10-01', '2026-11-30']] as [$from, $to]) {
            $this->assertSame(
                $this->postedChargeIncome($from, $to),
                (int) round($this->income($from, $to)['total_income'] * 100),
                "GCash income for {$from} to {$to} equals the 4090 credits posted in it.",
            );
        }

        $this->assertSame(3500, $this->postedChargeIncome('2026-10-01', '2026-10-31'));
        $this->assertSame(3500, $this->postedChargeIncome('2026-11-01', '2026-11-30'));
    }

    public function test_the_paid_date_range_is_served_by_an_index(): void
    {
        $this->assertTrue(Schema::hasIndex('gcash_transactions', 'gcash_transactions_paid_at_index'));

        $plan = DB::select("EXPLAIN SELECT id FROM gcash_transactions WHERE type = 'cash_in' AND status = 'paid' AND paid_at BETWEEN ? AND ?", ['2026-11-01 00:00:00', '2026-11-30 23:59:59']);

        $this->assertStringContainsString('gcash_transactions_paid_at_index', (string) $plan[0]->possible_keys);
    }

    /** A transaction recorded at `$at` (Manila) through the API. */
    private function record(string $at, string $type, bool $pending = false): GCashTransaction
    {
        $this->travelTo(Carbon::parse($at));

        $id = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->member->id,
            'type' => $type,
            'amount' => $this->amount++,
            'is_pending' => $pending,
        ])->assertCreated()->json('data.id');

        return GCashTransaction::findOrFail($id);
    }

    private function markPaid(GCashTransaction $tx, string $at): void
    {
        $this->travelTo(Carbon::parse($at));
        $this->patchJson("/api/gcash/transactions/{$tx->id}/paid")->assertOk();
    }

    /** @return array{total_income: float, cash_in_count: int, cash_out_count: int, transactions: list<array<string, mixed>>} */
    private function income(string $from, string $to): array
    {
        return $this->getJson("/api/gcash/reports/income?start_date={$from}&end_date={$to}")->assertOk()->json('data');
    }

    /** The 4090 GCash Service Income credits, in centavos, on GCash journals dated in the range. */
    private function postedChargeIncome(string $from, string $to): int
    {
        return (int) DB::table('accounting_journal_lines')
            ->join('accounting_journals', 'accounting_journals.id', '=', 'accounting_journal_lines.accounting_journal_id')
            ->where('accounting_journals.postable_type', GCashTransaction::class)
            ->whereBetween('accounting_journals.date', [$from, $to])
            ->where('accounting_journal_lines.accounting_account_id', $this->account('4090'))
            ->sum(DB::raw('accounting_journal_lines.credit - accounting_journal_lines.debit'));
    }
}
