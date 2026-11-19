<?php

namespace Tests\Feature\Phase6\ExecutiveHistory;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PortfolioDailySnapshot;
use App\Models\PortfolioSnapshotLine;
use App\Models\PortfolioSnapshotRun;
use App\Services\Dashboard\PortfolioSnapshotLines;
use App\Services\LoanListTotals;
use App\Services\PortfolioRisk;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-B: `portfolio:snapshot` also writes one line per branch × loan
 * product (PAR 1/30/60/90 amounts and loans, loan counts, overdue and
 * outstanding figures) and logs each run. The lines must add up to the day's
 * organisation-wide row, which must equal the Portfolio Summary; a day whose
 * lines don't add up keeps its organisation row but no lines, and the run
 * says why.
 *
 * Each loan is ₱60,000.00 over 6 periods of ₱10,000.00 principal and
 * ₱1,800.00 interest.
 */
class SnapshotLinesTest extends TestCase
{
    use SetupLendyPH;

    private const FIGURES = [
        'loan_count', 'active_loan_count', 'overdue_loans_count', 'overdue_amount', 'total_outstanding',
        'outstanding_principal', 'par_1_amount', 'par_1_loans', 'par_30_amount', 'par_30_loans',
        'par_60_amount', 'par_60_loans', 'par_90_amount', 'par_90_loans',
    ];

    private Branch $other;

    private LoanProduct $first;

    private LoanProduct $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-11-19 23:55:00'));

        $this->other = Branch::factory()->create();
        $this->first = LoanProduct::factory()->create();
        $this->second = LoanProduct::factory()->create();
    }

    public function test_a_line_per_branch_and_product_with_its_own_figures(): void
    {
        $this->book();

        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $lines = PortfolioSnapshotLine::query()->where('snapshot_date', '2026-11-19')->get()
            ->keyBy(fn (PortfolioSnapshotLine $line) => "{$line->branch_id}-{$line->loan_product_id}");

        $this->assertCount(3, $lines, 'Main × first, main × second, other × first; a completed loan makes no line.');

        $mainFirst = $lines["{$this->branch->id}-{$this->first->id}"];
        $this->assertSame(2, $mainFirst->loan_count);
        $this->assertSame(2, $mainFirst->active_loan_count);
        $this->assertSame(1, $mainFirst->overdue_loans_count);
        $this->assertEquals(11800, $mainFirst->overdue_amount);
        $this->assertEquals(120000, $mainFirst->outstanding_principal);
        $this->assertEquals(141600, $mainFirst->total_outstanding);
        $this->assertEquals(60000, $mainFirst->par_30_amount);
        $this->assertSame(1, $mainFirst->par_30_loans);
        $this->assertSame(0, $mainFirst->par_60_loans);

        $mainSecond = $lines["{$this->branch->id}-{$this->second->id}"];
        $this->assertSame(1, $mainSecond->par_90_loans);
        $this->assertEquals(60000, $mainSecond->par_60_amount);
        $this->assertSame(0, $mainSecond->active_loan_count, 'Defaulted: collectible, not active.');

        $otherFirst = $lines["{$this->other->id}-{$this->first->id}"];
        $this->assertSame(1, $otherFirst->par_1_loans, 'Two days late: PAR 1 from the bare due date.');
        $this->assertSame(0, $otherFirst->overdue_loans_count, 'Inside its 3-day grace: not past due.');
    }

    public function test_the_lines_add_up_to_the_organisation_row_and_the_portfolio_summary(): void
    {
        $this->book();

        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $row = PortfolioDailySnapshot::query()->sole();
        $lines = PortfolioSnapshotLine::query()->where('snapshot_date', '2026-11-19')->get();

        foreach (self::FIGURES as $figure) {
            $this->assertEquals((float) $row->{$figure}, round((float) $lines->sum($figure), 2), $figure);
        }
        $this->assertSame(3, $row->line_count);

        $summary = $this->getJson('/api/reports/loan-balance-summary')->assertOk()->json('data');
        $this->assertEquals($summary['outstanding_principal'], (float) $row->outstanding_principal);
        $this->assertEquals($summary['total_outstanding'], (float) $row->total_outstanding);
        $this->assertEquals($summary['past_due_amount'], (float) $row->overdue_amount);
        $this->assertSame($summary['past_due_loan_count'], $row->overdue_loans_count);
        $this->assertSame($summary['active_loans'], $row->active_loan_count);
        foreach (['par_1', 'par_30', 'par_60', 'par_90'] as $par) {
            $this->assertEquals($summary[$par]['amount'], (float) $row->{"{$par}_amount"}, $par);
            $this->assertSame($summary[$par]['loans'], $row->{"{$par}_loans"}, $par);
        }
    }

    public function test_each_run_is_logged(): void
    {
        $this->book();

        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $run = PortfolioSnapshotRun::query()->sole();
        $this->assertSame('2026-11-19', $run->snapshot_date->toDateString());
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(3, $run->line_count);
        $this->assertFalse($run->is_replay);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->message);
    }

    public function test_lines_that_don_t_add_up_are_not_kept_and_the_run_says_so(): void
    {
        $this->book();

        $real = new PortfolioSnapshotLines(app(LoanListTotals::class), app(PortfolioRisk::class));
        $this->snapshotLinesWhere()->shouldReceive('build')->once()->andReturnUsing(function (\Carbon\Carbon $asOf) use ($real): array {
            $lines = $real->build($asOf);
            $lines[0]['outstanding_principal'] += 0.01;

            return $lines;
        });

        $this->artisan('portfolio:snapshot')->assertFailed();

        $row = PortfolioDailySnapshot::query()->sole();
        $this->assertNull($row->line_count, 'The organisation row stays; it has no lines.');
        $this->assertSame(0, PortfolioSnapshotLine::query()->count());

        $run = PortfolioSnapshotRun::query()->sole();
        $this->assertSame('mismatch', $run->status);
        $this->assertSame('The branch and product lines did not add up to the day\'s portfolio, so they were not kept.', $run->message);
        $this->assertArrayHasKey('outstanding_principal', $run->checks);
    }

    public function test_a_failed_run_is_logged_in_words(): void
    {
        $this->snapshotLinesWhere()->shouldReceive('build')->once()->andThrow(new RuntimeException('connection lost'));

        $this->artisan('portfolio:snapshot')->assertFailed();

        $run = PortfolioSnapshotRun::query()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('The snapshot could not be recorded: connection lost', $run->message);
        $this->assertSame(0, PortfolioDailySnapshot::query()->count(), 'Nothing is half written.');
    }

    public function test_a_forced_replay_replaces_the_day_s_lines(): void
    {
        $this->book();
        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
        $this->artisan('portfolio:snapshot', ['--date' => '2026-11-19', '--force' => true])->assertSuccessful();

        $this->assertSame(3, PortfolioSnapshotLine::query()->where('snapshot_date', '2026-11-19')->count());
        $runs = PortfolioSnapshotRun::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertTrue($runs[1]->is_replay);
    }

    public function test_an_empty_book_records_a_day_with_no_lines(): void
    {
        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $this->assertSame(0, PortfolioDailySnapshot::query()->sole()->line_count);
        $this->assertSame('succeeded', PortfolioSnapshotRun::query()->sole()->status);
    }

    /**
     * Main × first: 40 days late (grace 0) and current. Main × second:
     * defaulted, 100 days late. Other × first: 2 days late inside a 3-day
     * grace. Main × second: a completed loan.
     */
    private function book(): void
    {
        $late = $this->loanIn($this->branch, $this->first, grace: 0, daysLate: 40);
        $this->loanIn($this->branch, $this->first, grace: 0, daysLate: 0);
        $defaulted = $this->loanIn($this->branch, $this->second, grace: 0, daysLate: 100);
        DB::table('loans')->where('id', $defaulted->id)->update(['status' => 'defaulted']);
        $this->loanIn($this->other, $this->first, grace: 3, daysLate: 2);

        $completed = $this->loanIn($this->branch, $this->second, grace: 0, daysLate: 0);
        app(RepaymentService::class)->processRepayment($completed->fresh(), 70800, now()->toDateString(), $this->admin);
        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertNotNull($late);
    }

    /**
     * The real PortfolioSnapshotLines, bound for the command, with only the
     * methods a test names replaced.
     */
    private function snapshotLinesWhere(): MockInterface
    {
        $mock = Mockery::mock(PortfolioSnapshotLines::class, [app(LoanListTotals::class), app(PortfolioRisk::class)])->makePartial();
        $this->instance(PortfolioSnapshotLines::class, $mock);

        return $mock;
    }

    private function loanIn(Branch $branch, LoanProduct $product, int $grace, int $daysLate): Loan
    {
        $loan = $this->createReleasedLoan(['product' => ['grace_period_days' => $grace]]);

        DB::table('loans')->where('id', $loan->id)->update(['branch_id' => $branch->id, 'loan_product_id' => $product->id]);
        DB::table('borrowers')->where('id', $loan->borrower_id)->update(['branch_id' => $branch->id]);
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 1)
            ->update(['due_date' => Carbon::today()->subDays($daysLate)->toDateString()]);

        return $loan->fresh();
    }
}
