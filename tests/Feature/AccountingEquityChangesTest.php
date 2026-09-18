<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * How equity moved over a period.
 *
 * Needs an opening balance AND the movements between it and the close, which a
 * single trial balance does not carry — which is why this comes from the server
 * rather than being regrouped client-side the way the balance sheet is.
 */
class AccountingEquityChangesTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @return array<string, mixed> */
    private function report(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        return $this->getJson("/api/accounting/statements/equity-changes?from={$from}&to={$to}")
            ->assertOk()->json('data');
    }

    /** @return array<string, array<string, mixed>> keyed by account code */
    private function rowsByCode(array $report): array
    {
        return collect($report['rows'])->mapWithKeys(fn (array $r): array => [$r['account_code'] => $r])->all();
    }

    public function test_it_rolls_a_component_forward_from_its_opening_balance(): void
    {
        // ₱500,000 of capital put in before the period.
        $this->postSimpleJournal('1010', '3010', 50000000, ['date' => '2026-08-15']);
        // ₱150,000.50 more during it, and ₱20,000 taken back out.
        $this->postSimpleJournal('1010', '3010', 15000050, ['date' => '2026-09-05']);
        $this->postSimpleJournal('3010', '1010', 2000000, ['date' => '2026-09-20']);

        $row = $this->rowsByCode($this->report())['3010'];

        $this->assertSame(50000000, $row['beginning']);
        $this->assertSame(15000050, $row['additions']);
        $this->assertSame(2000000, $row['deductions']);
        $this->assertSame(63000050, $row['ending']);

        // `beginning + additions - deductions`, exactly.
        $this->assertSame($row['beginning'] + $row['additions'] - $row['deductions'], $row['ending']);

        // CENTAVOS, as integers. `Amount` on the table would render a decimal
        // string as ₱0.00.
        foreach (['beginning', 'additions', 'deductions', 'ending'] as $field) {
            $this->assertIsInt($row[$field]);
        }
    }

    public function test_the_opening_balance_is_the_close_of_the_day_before(): void
    {
        // Dated the last day BEFORE the period. Using `from` itself as the
        // cut-off would fold the first day's movements into the opening figure
        // and take them off the statement.
        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2026-08-31']);
        $this->postSimpleJournal('1010', '3010', 5000000, ['date' => '2026-09-01']);

        $row = $this->rowsByCode($this->report())['3010'];

        $this->assertSame(10000000, $row['beginning']);
        $this->assertSame(5000000, $row['additions']);
    }

    public function test_the_totals_are_the_sums_of_the_rows(): void
    {
        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2026-08-15']);
        $this->postSimpleJournal('1010', '3020', 2500000, ['date' => '2026-09-10']);

        $report = $this->report();

        $this->assertSame(10000000, $report['total_beginning']);
        $this->assertSame(12500000, $report['total_ending']);

        $this->assertSame(
            array_sum(array_column($report['rows'], 'ending')),
            $report['total_ending'],
        );
    }

    /**
     * A component that neither held anything nor moved is not on the statement.
     *
     * The default chart carries several equity accounts a given co-op will
     * never use, and a row of four zeros is noise.
     */
    public function test_untouched_equity_accounts_are_left_off(): void
    {
        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2026-09-10']);

        $codes = array_column($this->report()['rows'], 'account_code');

        $this->assertSame(['3010'], $codes);
    }

    /**
     * The label is what the table renders AND its React key, so it carries the
     * code as well as the name — two accounts sharing a name would otherwise
     * collide in the reconciler and render as one row.
     */
    public function test_each_row_is_labelled_with_its_code_and_name(): void
    {
        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2026-09-10']);

        $this->assertSame('3010 Capital', $this->report()['rows'][0]['label']);
    }

    /**
     * A contra-equity account reduces equity, and gets there without any
     * `is_contra` branching.
     *
     * Every figure is expressed as a contribution TO EQUITY — `credit - debit`
     * — so a debit-normal contra account comes out negative by the same
     * arithmetic that makes Share Capital positive. One expression, one place
     * for the sign to be right.
     */
    public function test_a_contra_equity_account_reduces_equity(): void
    {
        $drawings = $this->postJson('/api/accounting/accounts', [
            'code' => '3060', 'name' => 'Owner Drawings', 'type' => 'equity',
            'is_contra' => true, 'parent_id' => $this->account('3000'),
        ])->assertCreated()->json('data');

        $this->assertSame('debit', $drawings['normal_balance']);

        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2026-09-01']);

        // Built by hand rather than through `postSimpleJournal`, whose account
        // lookup is a map cached when the chart was seeded and so cannot see an
        // account created since. The owner takes ₱30,000 out.
        $this->postJournal([
            ['account_id' => $drawings['id'], 'debit' => 3000000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 3000000],
        ], ['date' => '2026-09-20']);

        $report = $this->report();
        $row = $this->rowsByCode($report)['3060'];

        $this->assertSame(0, $row['beginning']);
        $this->assertSame(0, $row['additions']);
        $this->assertSame(3000000, $row['deductions']);
        $this->assertSame(-3000000, $row['ending']);

        // Total equity is capital less drawings, not capital plus them.
        $this->assertSame(7000000, $report['total_ending']);
    }

    public function test_it_refuses_a_missing_or_backwards_range(): void
    {
        $this->getJson('/api/accounting/statements/equity-changes')
            ->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);

        $this->getJson('/api/accounting/statements/equity-changes?from=2026-09-30&to=2026-09-01')
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_it_needs_accounting_view(): void
    {
        $this->actingAs($this->userWithNoRole())
            ->getJson('/api/accounting/statements/equity-changes?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }
}
