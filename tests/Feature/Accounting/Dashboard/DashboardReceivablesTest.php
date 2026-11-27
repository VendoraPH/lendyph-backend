<?php

namespace Tests\Feature\Accounting\Dashboard;

use App\Models\AccountingAccountMapping;
use App\Models\Branch;
use App\Services\Accounting\ReceivablePosition;
use App\Services\PortfolioOutstanding;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The dashboard's receivable figures (Phase 2 decisions 7 and 12, and the
 * 2026-11-09 rule that net loans receivable subtracts 1210 as well as the
 * allowance), all read from one trial balance through ReceivablePosition, and
 * past due from the loan records by the grace rule.
 */
class DashboardReceivablesTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function dashboard(string $query = 'as_of=2026-09-30'): array
    {
        return $this->getJson('/api/accounting/dashboard?'.$query)->assertOk()->json('data');
    }

    public function test_net_receivable_is_gross_less_the_allowance_less_the_unearned_restructure_income(): void
    {
        $this->postSimpleJournal('1110', '1010', 6000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1120', '1110', 1000000, ['date' => '2026-09-05']);
        $this->postSimpleJournal('5140', '1200', 500000, ['date' => '2026-09-06']);
        // A restructure capitalises interest and penalty: Dr 1110 / Cr 1210.
        $this->postSimpleJournal('1110', '1210', 300000, ['date' => '2026-09-07']);

        $dashboard = $this->dashboard();

        $this->assertSame(6300000, $dashboard['loans_receivable_gross']);
        $this->assertSame(500000, $dashboard['allowance_for_credit_losses']);
        $this->assertSame(300000, $dashboard['unearned_restructure_income']);
        $this->assertSame(6300000 - 500000 - 300000, $dashboard['loans_receivable_net']);
        $this->assertSame(0, $dashboard['adjustments_clearing']);
        $this->assertFalse($dashboard['adjustments_clearing_needs_review']);
    }

    public function test_the_receivable_position_has_the_shape_b3_reads(): void
    {
        $this->postSimpleJournal('1110', '1010', 6000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('5140', '1200', 500000, ['date' => '2026-09-06']);
        $this->postSimpleJournal('1110', '1210', 300000, ['date' => '2026-09-07']);
        $this->postSimpleJournal('1180', '1110', 20000, ['date' => '2026-09-08']);

        $this->assertSame([
            'gross' => 6280000,
            'allowance' => 500000,
            'unearned_income' => 300000,
            'adjustments_clearing' => 20000,
            'net' => 5480000,
        ], ReceivablePosition::build('2026-09-30', null));

        // As of a date before the restructure and the clearing entry.
        $this->assertSame([
            'gross' => 6000000,
            'allowance' => 500000,
            'unearned_income' => 0,
            'adjustments_clearing' => 0,
            'net' => 5500000,
        ], ReceivablePosition::build('2026-09-06', null));
    }

    public function test_any_balance_on_adjustments_clearing_needs_review_either_way(): void
    {
        $this->postSimpleJournal('1110', '1010', 1000000, ['date' => '2026-09-01']);
        // A reduction posted with no matching increase leaves 1180 in debit.
        $this->postSimpleJournal('1180', '1110', 25000, ['date' => '2026-09-02']);

        $dashboard = $this->dashboard();
        $this->assertSame(25000, $dashboard['adjustments_clearing']);
        $this->assertTrue($dashboard['adjustments_clearing_needs_review']);

        // Debit normal, so a credit balance reads negative and still flags.
        $this->postSimpleJournal('1110', '1180', 40000, ['date' => '2026-09-03']);

        $dashboard = $this->dashboard();
        $this->assertSame(-15000, $dashboard['adjustments_clearing']);
        $this->assertTrue($dashboard['adjustments_clearing_needs_review']);

        $this->postSimpleJournal('1180', '1110', 15000, ['date' => '2026-09-04']);

        $dashboard = $this->dashboard();
        $this->assertSame(0, $dashboard['adjustments_clearing']);
        $this->assertFalse($dashboard['adjustments_clearing_needs_review']);
    }

    public function test_an_unmapped_role_reads_zero(): void
    {
        $this->postSimpleJournal('1110', '1010', 1000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1110', '1210', 100000, ['date' => '2026-09-02']);
        $this->postSimpleJournal('1180', '1110', 5000, ['date' => '2026-09-03']);

        AccountingAccountMapping::query()
            ->whereIn('role', ['unearned_restructure_income', 'loan_adjustments', 'allowance_credit_losses'])
            ->delete();

        $position = ReceivablePosition::build('2026-09-30', null);

        $this->assertSame(0, $position['unearned_income']);
        $this->assertSame(0, $position['adjustments_clearing']);
        $this->assertSame(0, $position['allowance']);
        $this->assertSame($position['gross'], $position['net']);

        AccountingAccountMapping::query()->where('role', 'loans_receivable')->delete();

        $this->assertSame(0, ReceivablePosition::build('2026-09-30', null)['gross']);
    }

    public function test_past_due_is_the_loan_records_figure_by_the_grace_rule_in_centavos_as_of_today(): void
    {
        $late = $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString(), 'principal_amount' => 60000]);
        $this->createReleasedLoan(['start_date' => now()->toDateString(), 'principal_amount' => 30000]);

        $totals = app(PortfolioOutstanding::class)->totals(null);
        $this->assertGreaterThan(0, $totals['overdue_amount']);
        $this->assertSame(1, $totals['overdue_count']);

        $pastDue = $this->dashboard('as_of=2026-01-31')['past_due'];

        $this->assertSame(now()->toDateString(), $pastDue['as_of']);
        $this->assertSame((int) round($totals['overdue_amount'] * 100), $pastDue['amount']);
        $this->assertSame(1, $pastDue['count']);

        // Branch scope is a list for PortfolioOutstanding: a loan in another
        // branch is not this branch's past due.
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        DB::table('loans')->where('id', $late->id)->update(['branch_id' => $far->id]);

        $scoped = $this->dashboard('as_of=2026-01-31&branch_id='.$this->branch->id)['past_due'];
        $this->assertSame(0, $scoped['amount']);
        $this->assertSame(0, $scoped['count']);
    }
}
