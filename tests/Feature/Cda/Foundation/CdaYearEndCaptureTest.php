<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\AuditLog;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Services\Accounting\AllowanceCalculator;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `cda:capture-year-end` (CDA design §7): at 23:58 Manila on Dec 31, the
 * allowance base (AllowanceCalculator::forDate), the 1200 balances and the
 * loan-by-loan aging rows are kept on the year's reporting period, because
 * loan balances can't be read for a past date afterwards. It opens the
 * period when there is none, runs safely twice (the first capture stands),
 * and refuses a date that isn't Dec 31.
 */
class CdaYearEndCaptureTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_capture_matches_the_allowance_calculator_for_a_fixture_loan_book(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00', 'Asia/Manila'));
        $old = $this->createReleasedLoan(['principal_amount' => 60000, 'start_date' => '2026-06-01']);
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'Asia/Manila'));
        $recent = $this->createReleasedLoan(['principal_amount' => 30000, 'start_date' => '2026-11-15']);
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:58:00', 'Asia/Manila'));
        $expected = app(AllowanceCalculator::class)->forDate('2026-12-31');
        Auth::logout();

        $this->artisan('cda:capture-year-end')->assertSuccessful();

        $period = CdaReportingPeriod::query()->where('year', 2026)->firstOrFail();
        $capture = $period->year_end_capture;
        $this->assertNotNull($period->year_end_captured_at);
        $this->assertSame('2026-12-31', $capture['as_of']);
        $this->assertSame($this->sorted($expected), $this->sorted($capture['allowance']), 'The JSON column keeps the values; MySQL orders the keys.');
        $this->assertSame(11, CdaReport::query()->where('period_id', $period->id)->count(), 'The period is opened with its reports.');

        $rows = collect($capture['loans'])->keyBy('loan_id');
        $this->assertCount(2, $rows);
        $this->assertSame('over_120', $rows[$old->id]['bucket']);
        $this->assertSame('1_30', $rows[$recent->id]['bucket']);
        $this->assertSame(6000000, $rows[$old->id]['outstanding_principal']);
        $this->assertGreaterThan(0, $rows[$old->id]['unpaid_interest_due']);
        $this->assertArrayNotHasKey('member', $rows[$old->id], 'No member names are kept in the capture.');
        $this->assertSame($expected['totals']['required'], $capture['totals']['required']);
        $this->assertSame(['branches', 'unassigned'], array_keys($capture['balances']));
        $this->assertSame(0, $capture['totals']['allowance_booked']);
        $this->assertSame($expected['totals']['required'], $capture['totals']['provision_gap']);

        $row = AuditLog::query()->where('auditable_type', CdaReportingPeriod::class)->where('action', 'year_end_captured')->sole();
        $this->assertNull($row->user_id);
        $this->assertStringContainsString('2 loans', (string) $row->description);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00', 'Asia/Manila'));
        $this->createReleasedLoan(['principal_amount' => 60000, 'start_date' => '2026-06-01']);
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:58:00', 'Asia/Manila'));
        $this->artisan('cda:capture-year-end', ['--date' => '2026-12-31'])->assertSuccessful();
        $first = CdaReportingPeriod::query()->where('year', 2026)->firstOrFail();

        $this->createReleasedLoan(['principal_amount' => 10000, 'start_date' => '2026-12-31']);
        $audits = AuditLog::query()->count();
        Carbon::setTestNow(Carbon::parse('2027-01-02 08:00:00', 'Asia/Manila'));
        $this->artisan('cda:capture-year-end', ['--date' => '2026-12-31'])
            ->expectsOutputToContain('already captured')
            ->assertSuccessful();

        $again = $first->fresh();
        $this->assertSame($first->year_end_capture, $again->year_end_capture);
        $this->assertEquals($first->year_end_captured_at, $again->year_end_captured_at);
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_an_existing_period_is_used_and_a_date_other_than_dec_31_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00:00', 'Asia/Manila'));
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();

        $this->artisan('cda:capture-year-end', ['--date' => '2026-11-30'])->assertFailed();
        $this->assertNull(CdaReportingPeriod::query()->where('year', 2026)->firstOrFail()->year_end_captured_at);

        $this->artisan('cda:capture-year-end', ['--date' => '2026-12-31'])->assertSuccessful();
        $this->assertSame(1, CdaReportingPeriod::query()->count());
        $this->assertSame(11, CdaReport::query()->count());
        $this->assertSame([], CdaReportingPeriod::query()->firstOrFail()->year_end_capture['loans']);
    }

    /** The array with its string keys sorted, at every level (lists keep their order). */
    private function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->sorted($item) : $item, $value);
    }

    public function test_it_is_scheduled_at_2358_manila_on_dec_31_after_the_portfolio_snapshot(): void
    {
        $events = collect(app(Schedule::class)->events());
        $capture = $events->first(fn (Event $event) => Str::contains((string) $event->command, 'cda:capture-year-end'));

        $this->assertNotNull($capture);
        $this->assertSame('58 23 31 12 *', $capture->getExpression());
        $this->assertSame('Asia/Manila', $capture->timezone instanceof \DateTimeZone ? $capture->timezone->getName() : $capture->timezone);
    }
}
