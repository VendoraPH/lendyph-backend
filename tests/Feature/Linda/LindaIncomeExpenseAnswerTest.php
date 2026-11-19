<?php

namespace Tests\Feature\Linda;

use App\Models\AccountingExpense;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\ReportService;
use Tests\Traits\PostsJournals;

/**
 * Linda round 3, income and expenses for a period: the Income report's
 * totals (ReportService::incomeReport(), reports:view) and the Expenses &
 * Payables list's `meta.totals` (GET /accounting/expenses, expenses:view),
 * in the caller's branches. This month by default.
 */
class LindaIncomeExpenseAnswerTest extends LindaTopicTestCase
{
    use PostsJournals;

    private LindaPeriod $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->month = LindaPeriod::named('this_month');

        $main = $this->releaseTo($this->member($this->branch, 'Ana', 'Reyes'));
        $this->pay($main, 5000);
        $far = $this->releaseTo($this->member($this->far, 'Cora', 'Far'), 30000);
        $this->pay($far, 2500);

        $this->seedChartOfAccounts();
        $today = now()->toDateString();
        AccountingExpense::factory()->create(['date' => $today, 'branch_id' => $this->branch->id, 'amount' => 150000, 'amount_paid' => 150000, 'settlement_status' => 'paid']);
        AccountingExpense::factory()->create(['date' => $today, 'branch_id' => $this->far->id, 'amount' => 99950]);
        AccountingExpense::factory()->create(['date' => $today, 'branch_id' => null, 'amount' => 20000]);
        AccountingExpense::factory()->create(['date' => now()->subMonths(2)->toDateString(), 'branch_id' => $this->branch->id, 'amount' => 777700]);
    }

    /** @return array<string, int> */
    private function expenseTotals(?int $branchId = null, ?LindaPeriod $period = null): array
    {
        $period ??= $this->month;
        $query = http_build_query(array_filter([
            'from' => $period->from->toDateString(),
            'to' => $period->to->toDateString(),
            'branch_id' => $branchId,
        ]));

        return $this->getJson('/api/accounting/expenses?'.$query)->assertOk()->json('meta.totals');
    }

    public function test_income_equals_the_income_report_for_the_period(): void
    {
        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            $report = app(ReportService::class)->incomeReport($this->scopeOf($user, [
                'date_from' => $this->month->from->toDateString(),
                'date_to' => $this->month->to->toDateString(),
            ]));
            $reply = $this->ask($user, LindaIntent::IncomeSummary);
            $stats = $this->stats($reply);

            $this->assertSame('answered', $reply->outcome);
            $this->assertSame('IncomeSummaryAnswer', $reply->answerFunction);
            $this->assertEquals($report['total'], $stats['Total Income']);
            $this->assertEquals($report['interest_income'], $stats['Interest']);
            $this->assertEquals($report['penalty_income'], $stats['Penalties']);
            $this->assertEquals($report['processing_fees'], $stats['Processing Fees']);
            $this->assertEquals($report['service_fee'], $stats['Service Fees']);
            $this->assertEquals($report['other_fees'], $stats['Other Fees']);
            $this->assertGreaterThan(0, $report['interest_income']);
            $this->assertStringStartsWith('From '.$this->month->label.', income ', $reply->answer);
            $this->assertSame(
                ['/reports/income_report?'.http_build_query(['from' => $this->month->from->toDateString(), 'to' => $this->month->to->toDateString()])],
                $this->linkUrls($reply),
            );
        }

        $this->assertNotEquals(
            $this->stats($this->ask($this->headOffice(), LindaIntent::IncomeSummary))['Total Income'],
            $this->stats($this->ask($this->branchUser(), LindaIntent::IncomeSummary))['Total Income'],
        );
        $this->assertSame('denied', $this->ask($this->headOffice(['loans:view']), LindaIntent::IncomeSummary)->outcome);
    }

    public function test_expenses_equal_the_expenses_list_totals(): void
    {
        $head = $this->headOffice(['expenses:view', 'accounting:view']);
        $this->actingAs($head);
        $totals = $this->expenseTotals();

        $reply = $this->ask($head, LindaIntent::ExpenseSummary);
        $stats = $this->stats($reply);

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame($totals['count'], $stats['Expenses']);
        $this->assertEquals($totals['amount'] / 100, $stats['Total Amount']);
        $this->assertEquals($totals['amount_paid'] / 100, $stats['Paid']);
        $this->assertEquals($totals['amount_due'] / 100, $stats['Still Due']);
        $this->assertSame(3, $totals['count']);
        $this->assertStringContainsString('3 expenses were recorded across all branches, totalling ₱2,699.50: ₱1,500.00 paid and ₱1,199.50 still due.', $reply->answer);
        $this->assertSame(['/accounting/expenses'], $this->linkUrls($reply));

        // A named branch narrows it; last month has none.
        $far = $this->ask($head, LindaIntent::ExpenseSummary, branch: 'Far');
        $this->assertSame($this->expenseTotals($this->far->id)['count'], $this->stats($far)['Expenses']);
        $this->assertSame(1, $this->stats($far)['Expenses']);
        $this->assertSame(0, $this->stats($this->ask($head, LindaIntent::ExpenseSummary, LindaPeriod::named('last_month')))['Expenses']);
    }

    public function test_a_branch_user_sees_only_their_branches_expenses_and_needs_the_permission(): void
    {
        $branchUser = $this->branchUser(['expenses:view', 'accounting:view']);
        $this->actingAs($branchUser);
        $totals = $this->expenseTotals();

        $stats = $this->stats($this->ask($branchUser, LindaIntent::ExpenseSummary));
        $this->assertSame($totals['count'], $stats['Expenses']);
        $this->assertSame(1, $stats['Expenses']);
        $this->assertEquals(1500, $stats['Total Amount']);

        $this->assertSame('denied', $this->ask($this->headOffice(['reports:view', 'accounting:view']), LindaIntent::ExpenseSummary)->outcome);
    }
}
