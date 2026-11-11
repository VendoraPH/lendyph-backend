<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\Borrower;
use App\Models\CdaReportingPeriod;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShareCapitalLedger;
use App\Models\TimeDeposit;
use App\Services\Accounting\BalanceSheetBuilder;
use App\Services\Accounting\IncomeStatementBuilder;
use App\Services\Accounting\TrialBalanceBuilder;
use App\Services\Cda\Reports\Rational;
use App\Services\Cda\Reports\ReportEngine;
use App\Services\Cda\Sources\MembersSource;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use App\Services\Cda\Sources\SourceResult;
use App\Services\Cda\Sources\SourceUnavailable;
use App\Services\LoanListTotals;
use App\Services\ReportService;
use App\Services\Savings\Reports\GlControl;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The core CDA sources (design §2, §11 "CAPR sources"): each figure equals
 * its own module's figure for the same date or range. Members by membership
 * date and end date; loans from the Portfolio Summary, Releases, Portfolio
 * by Product and Collection Efficiency; PAR from the daily snapshot; share
 * capital from its ledger; savings from the GL control's subledger;
 * financial statements from the existing builders. Figures from the books
 * are provisional, with a Critical issue, until the year is closed.
 */
class CdaCaprSourcesTest extends TestCase
{
    use CdaReportTestHelpers, PostsJournals, SetupLendyPH;

    private CdaReportingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        $this->period = $this->openYear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function resolve(string $key, array $params = []): SourceResult
    {
        return app(SourceRegistry::class)->resolve($key, $params, new SourceContext($this->period));
    }

    public function test_members_count_by_membership_date_and_end_date(): void
    {
        $this->member(['membership_date' => '2025-06-01', 'gender' => 'male', 'birthdate' => '1990-05-01']);
        $this->member(['membership_date' => '2026-03-15', 'gender' => 'female', 'member_type' => 'associate', 'birthdate' => '2000-01-01']);
        $this->member(['membership_date' => '2026-12-31', 'gender' => null]);
        $this->member(['membership_date' => '2027-01-02']);
        $this->member(['membership_date' => '2024-01-01', 'status' => 'withdrawn', 'membership_ended_on' => '2026-05-01', 'membership_end_reason' => 'Moved away']);
        $this->member(['membership_date' => '2024-01-01', 'status' => 'deceased', 'membership_ended_on' => '2027-01-05']);
        $this->member(['membership_date' => null]);
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending', 'membership_date' => null]);

        $expected = Borrower::query()->members()->whereNotNull('membership_date')->where('membership_date', '<=', '2026-12-31')
            ->where(fn ($q) => $q->whereNull('membership_ended_on')->orWhere('membership_ended_on', '>', '2026-12-31'))->count();

        $total = $this->resolve('members.count');
        $this->assertSame($expected, $total->value);
        $this->assertSame(4, $total->value);
        $this->assertSame('count', $total->unit);
        $this->assertSame('Members · as of Dec 31, 2026', $total->label);
        $this->assertSame('2026-12-31', $total->asOf);

        $this->assertSame(1, $this->resolve('members.count', ['gender' => 'male'])->value);
        $this->assertSame(1, $this->resolve('members.count', ['gender' => 'unspecified'])->value);
        $this->assertSame(1, $this->resolve('members.count', ['member_type' => 'associate'])->value);
        $this->assertSame(1, $this->resolve('members.count', ['age_from' => 30, 'age_to' => 40])->value);
        $this->assertSame(3, $this->resolve('members.count', ['as_of' => '2026-06-30'])->value, 'The withdrawal on May 1 is out; the death in 2027 is not yet.');
        $this->assertSame(2, $this->resolve('members.new')->value);
        $this->assertSame(1, $this->resolve('members.ended', ['reason' => 'withdrawn'])->value);
        $this->assertSame(0, $this->resolve('members.ended', ['reason' => 'deceased'])->value);
        $this->assertSame(1, $this->resolve('members.missing', ['field' => 'membership_date'])->value);
        $this->assertSame(1, $this->resolve('members.missing', ['field' => 'gender'])->value);
    }

    public function test_active_members_follow_the_member_report_rule(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => '2026-03-01']);
        $loan->borrower->update(['membership_date' => '2026-01-10']);
        $this->member();

        $report = app(ReportService::class)->memberCounts(['branch_ids' => null, 'date_to' => '2026-12-31']);

        $this->assertSame($report['active'], $this->resolve('members.count', ['activity' => 'active'])->value);
        $this->assertSame(1, $this->resolve('members.count', ['activity' => 'active'])->value);
        $this->assertSame(1, $this->resolve('members.count', ['activity' => 'inactive'])->value);
    }

    public function test_loans_equal_releases_portfolio_summary_and_portfolio_by_product(): void
    {
        $first = $this->createReleasedLoan(['principal_amount' => 60000]);
        $this->createReleasedLoan(['principal_amount' => 25000.50]);
        $filters = ['branch_ids' => null, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31'];
        $releases = app(ReportService::class)->listOfReleasesTotals($filters);

        $this->assertSame($releases['count'], $this->resolve('loans.released', ['measure' => 'count'])->value);
        $this->assertSame(number_format($releases['total_principal'], 2, '.', ''), $this->resolve('loans.released')->value);
        $this->assertSame('85000.50', $this->resolve('loans.released')->value);
        $this->assertSame('60000.00', $this->resolve('loans.released', ['product' => (int) $first->loan_product_id])->value);

        $summary = app(ReportService::class)->loanBalanceSummary(['branch_ids' => null]);
        $principal = $this->resolve('loans.outstanding');
        $this->assertSame(number_format($summary['outstanding_principal'], 2, '.', ''), $principal->value);
        $this->assertSame('money', $principal->unit);
        $this->assertTrue($principal->provisional, 'Dec 31 has not come yet, so the figure may still change.');
        $this->assertSame(number_format($summary['total_outstanding'], 2, '.', ''), $this->resolve('loans.outstanding', ['measure' => 'total'])->value);
        $this->assertSame(2, $this->resolve('loans.outstanding', ['measure' => 'count'])->value);

        $owed = app(LoanListTotals::class)->for(Loan::query()->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES));
        $this->assertSame(number_format($owed['overdue_amount'], 2, '.', ''), $this->resolve('loans.past_due')->value);
        $this->assertSame($owed['overdue_count'], $this->resolve('loans.past_due', ['measure' => 'count'])->value);

        $byProduct = app(ReportService::class)->portfolioByProduct($filters)['products'];
        $rows = $this->resolve('loans.by_product')->value;
        $this->assertCount(count($byProduct), $rows);
        $this->assertSame(
            collect($byProduct)->map(fn ($p) => [$p['product_name'], $p['loan_count'], number_format($p['total_released'], 2, '.', '')])->sort()->values()->all(),
            collect($rows)->map(fn ($r) => [$r['product'], $r['count'], $r['amount']])->sort()->values()->all(),
        );

        $efficiency = app(ReportService::class)->collectionEfficiency($filters);
        $this->assertSame(number_format($efficiency['collection_rate'], 2, '.', ''), $this->resolve('loans.collection_efficiency')->value);
    }

    public function test_a_past_date_reads_the_daily_portfolio_snapshot(): void
    {
        PortfolioDailySnapshot::query()->create([
            'snapshot_date' => '2026-06-30', 'overdue_loans_count' => 3, 'overdue_amount' => '1500.25',
            'total_outstanding' => '90000.00', 'outstanding_principal' => '80000.00',
            'par_1_amount' => '8000.00', 'par_30_amount' => '4000.00', 'par_90_amount' => '1000.00', 'created_at' => now(),
        ]);

        $par = $this->resolve('loans.par', ['days' => 30, 'as_of' => '2026-06-30']);
        $this->assertSame('5.00', $par->value);
        $this->assertFalse($par->provisional);
        $this->assertSame('80000.00', $this->resolve('loans.outstanding', ['as_of' => '2026-06-30'])->value);
        $this->assertSame('1500.25', $this->resolve('loans.past_due', ['as_of' => '2026-06-30'])->value);
        $this->assertSame(3, $this->resolve('loans.past_due', ['as_of' => '2026-06-30', 'measure' => 'count'])->value);

        $missing = $this->resolve('loans.outstanding', ['as_of' => '2026-05-31']);
        $this->assertTrue($missing->provisional, 'No snapshot or capture for a past day: computed from current balances.');
        $this->assertStringContainsString('current balances', (string) ($missing->meta['note'] ?? ''));
    }

    public function test_the_year_end_capture_is_read_for_the_period_end(): void
    {
        $this->period->forceFill([
            'year_end_capture' => ['as_of' => '2026-12-31', 'loans' => [
                ['loan_id' => 1, 'outstanding_principal' => 1000050, 'total_outstanding' => 1200000, 'past_due_amount' => 30000, 'is_past_due' => true],
                ['loan_id' => 2, 'outstanding_principal' => 500000, 'total_outstanding' => 520000, 'past_due_amount' => 0, 'is_past_due' => false],
            ], 'totals' => ['loan_count' => 2, 'outstanding_principal' => 1500050]],
            'year_end_captured_at' => now(),
        ])->save();

        $principal = $this->resolve('loans.outstanding');
        $this->assertSame('15000.50', $principal->value);
        $this->assertFalse($principal->provisional);
        $this->assertSame('17200.00', $this->resolve('loans.outstanding', ['measure' => 'total'])->value);
        $this->assertSame('300.00', $this->resolve('loans.past_due')->value);
        $this->assertSame(1, $this->resolve('loans.past_due', ['measure' => 'count'])->value);
    }

    public function test_share_capital_equals_its_ledger(): void
    {
        $a = $this->member();
        $b = $this->member();
        ShareCapitalLedger::query()->create(['borrower_id' => $a->id, 'date' => '2026-02-01', 'description' => 'Paid', 'credit' => '1500.50', 'debit' => '0']);
        ShareCapitalLedger::query()->create(['borrower_id' => $b->id, 'date' => '2026-03-01', 'description' => 'Paid', 'credit' => '700.00', 'debit' => '0']);
        ShareCapitalLedger::query()->create(['borrower_id' => $b->id, 'date' => '2026-04-01', 'description' => 'Withdrawn', 'credit' => '0', 'debit' => '700.00']);
        ShareCapitalLedger::query()->create(['borrower_id' => $a->id, 'date' => '2027-01-05', 'description' => 'Next year', 'credit' => '99.00', 'debit' => '0']);

        $ledger = app(ReportService::class)->shareCapital(['branch_ids' => null, 'date_to' => '2026-12-31']);

        $this->assertSame(number_format($ledger['closing_balance'], 2, '.', ''), $this->resolve('share_capital.paid_up')->value);
        $this->assertSame('1500.50', $this->resolve('share_capital.paid_up')->value);
        $this->assertSame($ledger['member_count'], $this->resolve('share_capital.holders')->value);
        $this->assertSame('2200.50', $this->resolve('share_capital.movements', ['side' => 'credit'])->value);
        $this->assertSame('1500.50', $this->resolve('share_capital.movements')->value);
    }

    public function test_savings_equal_the_gl_control_subledger(): void
    {
        $saver = $this->member();
        $account = SavingsAccount::factory()->create(['borrower_id' => $saver->id, 'opened_on' => '2026-02-01']);
        SavingsTransaction::factory()->create(['account_id' => $account->id, 'date' => '2026-02-01', 'credit' => '2500.50', 'debit' => '0.00']);
        SavingsTransaction::factory()->create(['account_id' => $account->id, 'date' => '2026-05-01', 'type' => 'withdrawal', 'credit' => '0.00', 'debit' => '500.25']);
        SavingsTransaction::factory()->create(['account_id' => $account->id, 'date' => '2027-01-05', 'credit' => '99.00', 'debit' => '0.00']);
        TimeDeposit::factory()->create(['borrower_id' => $saver->id, 'principal' => '50000.00', 'placement_date' => '2026-06-01']);
        TimeDeposit::factory()->create(['principal' => '10000.00', 'placement_date' => '2026-03-01', 'status' => 'withdrawn', 'settled_on' => '2026-06-01']);

        $control = app(GlControl::class)->build('2026-12-31', null);

        $this->assertSame($control['totals']['subledger_balance'], $this->resolve('savings.balance')->value);
        $this->assertSame('52000.25', $this->resolve('savings.balance')->value);
        $this->assertSame('2000.25', $this->resolve('savings.balance', ['kind' => 'regular'])->value);
        $this->assertSame('50000.00', $this->resolve('savings.balance', ['kind' => 'time_deposit'])->value);
        $this->assertSame(2, $this->resolve('savings.accounts')->value);
        $this->assertSame(1, $this->resolve('savings.depositors')->value);
        $this->assertSame('money', $this->resolve('savings.balance', ['kind' => 'time_deposit'])->unit);
    }

    public function test_financial_statements_equal_the_builders_and_are_provisional_until_the_year_closes(): void
    {
        $this->seedChartOfAccounts();
        $this->postSimpleJournal('1010', '3060', 100000050, ['date' => '2026-03-01']);
        $this->postSimpleJournal('1010', '4010', 8000000, ['date' => '2026-06-01']);
        $this->postSimpleJournal('5020', '1010', 3000000, ['date' => '2026-07-01']);

        $sheet = app(BalanceSheetBuilder::class)->build('2026-12-31');
        $income = app(IncomeStatementBuilder::class)->build('2026-01-01', '2026-12-31');

        $assets = $this->resolve('fs.total_assets');
        $this->assertSame(Rational::fromCentavos($sheet['total_assets'])->toFixed(2), $assets->value);
        $this->assertSame('1050000.50', $assets->value);
        $this->assertTrue($assets->provisional);
        $this->assertSame(Rational::fromCentavos($sheet['total_liabilities'])->toFixed(2), $this->resolve('fs.total_liabilities')->value);
        $this->assertSame(Rational::fromCentavos($sheet['total_equity'])->toFixed(2), $this->resolve('fs.total_equity')->value);
        $this->assertTrue($this->resolve('fs.is_balanced')->value);
        $this->assertSame(Rational::fromCentavos($income['total_income'])->toFixed(2), $this->resolve('fs.total_income')->value);
        $this->assertSame(Rational::fromCentavos($income['total_expenses'])->toFixed(2), $this->resolve('fs.total_expenses')->value);
        $this->assertSame('50000.00', $this->resolve('fs.net_surplus')->value);
        $this->assertSame('1000000.50', $this->resolve('accounting.balance', ['code' => '3060'])->value);
        $this->assertSame('80000.00', $this->resolve('accounting.flow', ['code' => '4010', 'side' => 'credit'])->value);
        $this->assertNotEmpty($this->resolve('fs.balance_sheet')->value);
        $this->assertTrue((bool) app(TrialBalanceBuilder::class)->build('2026-12-31')['is_balanced']);
        $this->assertFalse($this->resolve('accounting.year_closed')->value);

        $this->closeBooks(2026);
        $this->period = $this->period->fresh();

        $this->assertTrue($this->resolve('accounting.year_closed')->value);
        $this->assertFalse($this->resolve('fs.total_assets')->provisional);
    }

    public function test_an_unclosed_year_is_a_critical_issue_and_marks_the_report_provisional(): void
    {
        $this->seedChartOfAccounts();
        $this->postSimpleJournal('1010', '3060', 100000, ['date' => '2026-03-01']);
        $report = $this->report($this->period, 'capr');

        app(ReportEngine::class)->refresh($report);
        $report->refresh();

        $issue = collect($report->issues)->firstWhere('rule_id', 'year_closed');
        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue['severity']);
        $this->assertSame('financial_data', $issue['category']);

        $response = $this->getJson("/api/cda/reports/{$report->id}")->assertOk()->assertJsonPath('data.provisional', true);
        $financial = collect($response->json('data.sections'))->firstWhere('key', 'financial');
        $this->assertTrue(collect($financial['fields'])->firstWhere('key', 'total_assets')['provisional']);

        $this->closeBooks(2026);
        app(ReportEngine::class)->refresh($report->refresh());
        $this->assertNull(collect($report->refresh()->issues)->firstWhere('rule_id', 'year_closed'));
    }

    public function test_report_field_reads_another_reports_value(): void
    {
        $fs = $this->report($this->period, 'financial_statements');
        $fs->values()->create(['field_key' => 'statements.total_assets', 'value' => '1234.50']);

        $result = $this->resolve('report.field', ['type' => 'financial_statements', 'field' => 'statements.total_assets']);
        $this->assertSame('1234.50', $result->value);
        $this->assertTrue($result->provisional, 'The other report is not finalized yet.');
        $this->assertSame('Audited Financial Statements · Total assets', $result->label);
    }

    public function test_a_family_does_not_know_a_key_from_another_family(): void
    {
        $this->expectException(SourceUnavailable::class);
        app(MembersSource::class)->resolve('loans.released', [], new SourceContext($this->period));
    }
}
