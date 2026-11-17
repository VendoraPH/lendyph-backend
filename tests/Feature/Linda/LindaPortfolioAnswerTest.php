<?php

namespace Tests\Feature\Linda;

use App\Models\PortfolioDailySnapshot;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\ReportService;

/**
 * Linda round 3, portfolio questions: the Portfolio Summary report's figures
 * as of today (ReportService::loanBalanceSummary(), GET
 * /reports/loan-balance-summary), by branch, plus PAR 1 and PAR 90 from the
 * latest daily portfolio snapshot for a caller who sees every branch.
 */
class LindaPortfolioAnswerTest extends LindaTopicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->releaseTo($this->member($this->branch, 'Ana', 'Reyes'), 60000, now()->subMonths(3)->toDateString());
        $this->releaseTo($this->member($this->branch, 'Ben', 'Cruz'), 30000);
        $this->releaseTo($this->member($this->far, 'Cora', 'Far'), 24000);
    }

    public function test_the_figures_equal_the_portfolio_summary_with_each_branch(): void
    {
        $user = $this->headOffice();
        $reply = $this->ask($user, LindaIntent::PortfolioSummary);
        $summary = app(ReportService::class)->loanBalanceSummary($this->scopeOf($user));
        $stats = $this->stats($reply);

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame('PortfolioSummaryAnswer', $reply->answerFunction);
        $this->assertEquals($summary['outstanding_principal'], $stats['Principal Balance']);
        $this->assertEquals($summary['total_outstanding'], $stats['Outstanding']);
        $this->assertEquals($summary['par_ratio'], $stats['PAR 30']);
        $this->assertEquals($summary['at_risk_amount'], $stats['Principal at Risk (PAR 30)']);
        $this->assertSame($summary['active_loans'], $stats['Active Loans']);
        $this->assertGreaterThan(0, $summary['at_risk_amount']);

        $rows = $this->rows($reply);
        foreach ($summary['by_branch'] as $branch) {
            $this->assertEquals($branch['outstanding_principal'], $rows[$branch['branch_name']]['Principal Balance']);
            $this->assertEquals($branch['total_outstanding'], $rows[$branch['branch_name']]['Outstanding']);
            $this->assertSame($branch['active_loan_count'], $rows[$branch['branch_name']]['Active Loans']);
        }

        $this->assertStringContainsString('As of today, ', $reply->answer);
        $this->assertStringContainsString('across all branches', $reply->answer);
        $this->assertStringContainsString('PAR 30 is '.number_format($summary['par_ratio'], 1).'%', $reply->answer);
        $this->assertSame(['/reports/portfolio_summary'], $this->linkUrls($reply));
    }

    public function test_a_branch_user_and_a_named_branch_narrow_it(): void
    {
        $branchUser = $this->branchUser();
        $own = $this->ask($branchUser, LindaIntent::PortfolioSummary);
        $this->assertEquals(
            app(ReportService::class)->loanBalanceSummary($this->scopeOf($branchUser))['outstanding_principal'],
            $this->stats($own)['Principal Balance'],
        );
        $this->assertStringNotContainsString('Far Branch', json_encode($own->toArray()));

        $head = $this->headOffice();
        $far = $this->ask($head, LindaIntent::PortfolioSummary, branch: 'Far');
        $farSummary = app(ReportService::class)->loanBalanceSummary($this->scopeOf($head, ['branch_id' => $this->far->id]));
        $this->assertEquals($farSummary['outstanding_principal'], $this->stats($far)['Principal Balance']);
        $this->assertStringContainsString('for Far Branch', $far->answer);
        $this->assertSame(['/reports/portfolio_summary?branch='.$this->far->id], $this->linkUrls($far));

        $this->assertSame('no_branch', $this->ask($branchUser, LindaIntent::PortfolioSummary, branch: 'Far')->outcome);
    }

    public function test_par_1_and_par_90_come_from_the_latest_snapshot_for_the_whole_cooperative_only(): void
    {
        PortfolioDailySnapshot::query()->create([
            'snapshot_date' => now()->subDays(3)->toDateString(),
            'overdue_loans_count' => 1, 'overdue_amount' => 1, 'total_outstanding' => 1, 'outstanding_principal' => 1,
            'par_1_amount' => 111.11, 'par_30_amount' => 222.22, 'par_90_amount' => 333.33,
        ]);
        $latest = PortfolioDailySnapshot::query()->create([
            'snapshot_date' => now()->subDay()->toDateString(),
            'overdue_loans_count' => 1, 'overdue_amount' => 1, 'total_outstanding' => 1, 'outstanding_principal' => 1,
            'par_1_amount' => 4500.25, 'par_30_amount' => 3000, 'par_90_amount' => 1250.5,
        ]);

        $head = $this->stats($this->ask($this->headOffice(), LindaIntent::PortfolioSummary));
        $this->assertEquals(4500.25, $head['PAR 1 (₱, last snapshot)']);
        $this->assertEquals(1250.5, $head['PAR 90 (₱, last snapshot)']);
        $this->assertSame($latest->snapshot_date->toDateString(), $head['Snapshot Date']);

        $this->assertArrayNotHasKey('PAR 1 (₱, last snapshot)', $this->stats($this->ask($this->branchUser(), LindaIntent::PortfolioSummary)));
        $this->assertArrayNotHasKey('PAR 1 (₱, last snapshot)', $this->stats($this->ask($this->headOffice(), LindaIntent::PortfolioSummary, branch: 'Main')));
    }

    public function test_it_needs_the_reports_permission_and_ignores_periods(): void
    {
        $this->assertSame('denied', $this->ask($this->headOffice(['loans:view', 'borrowers:view']), LindaIntent::PortfolioSummary)->outcome);

        $reply = $this->ask($this->headOffice(), LindaIntent::PortfolioSummary, LindaPeriod::named('last_month'));
        $this->assertStringContainsString('These figures are as of today', $reply->answer);
        $this->assertFalse(LindaIntent::PortfolioSummary->takesPeriod());
    }
}
