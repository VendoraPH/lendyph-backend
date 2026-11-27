<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\PeriodOpener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A year opened by staff (PeriodOpener::open()) or by the Dec 31 capture
 * (PeriodOpener::ensure()) computes at once: after the period is committed,
 * each applicable report is refreshed in its own transaction. A report whose
 * refresh fails is logged and left for the refresh action or the nightly
 * run; the period stays open and the other reports are still refreshed.
 */
class CdaPeriodOpenerRefreshTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    /** The report whose refresh is made to fail. */
    private const FAILING = 'social_audit';

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

    /** Makes the refresh of one report type throw when it saves its refreshed time. */
    private function failRefreshOf(string $type): void
    {
        CdaReport::saving(function (CdaReport $report) use ($type): void {
            if ($report->report_type === $type && $report->isDirty('refreshed_at') && $report->refreshed_at !== null) {
                throw new RuntimeException("Refresh of {$type} failed in the test.");
            }
        });
    }

    private function assertRefreshedExcept(CdaReportingPeriod $period, ?string $failed): void
    {
        $applicable = CdaReport::query()->where('period_id', $period->id)->where('applicable', true)->get();
        $this->assertGreaterThan(1, $applicable->count());

        foreach ($applicable as $report) {
            if ($report->report_type === $failed) {
                $this->assertNull($report->refreshed_at, "{$report->report_type} failed, so it waits for the nightly run.");
                $this->assertSame(0, $report->values()->count(), 'The failed refresh wrote nothing.');

                continue;
            }

            $this->assertNotNull($report->refreshed_at, "{$report->report_type}:{$report->part} was refreshed when the year opened.");
        }

        $this->assertSame(0, CdaReport::query()->where('period_id', $period->id)->where('applicable', false)->whereNotNull('refreshed_at')->count(), 'A report that does not apply is not refreshed.');
    }

    public function test_a_year_opened_by_ensure_computes_at_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));

        $period = app(PeriodOpener::class)->ensure(2026);

        $this->assertRefreshedExcept($period, null);
        $capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
        $this->assertGreaterThan(0, $capr->readiness);
        $this->assertNotNull(collect($capr->issues)->first(fn (array $issue) => $issue['severity'] === 'critical' && $issue['category'] === 'financial_data'), 'The unclosed year raises its Critical issue at once.');
    }

    public function test_ensure_leaves_an_existing_year_as_it_is(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        $period = app(PeriodOpener::class)->ensure(2026);
        CdaReport::query()->where('period_id', $period->id)->update(['refreshed_at' => null]);

        $again = app(PeriodOpener::class)->ensure(2026);

        $this->assertSame($period->id, $again->id);
        $this->assertSame(0, CdaReport::query()->where('period_id', $period->id)->whereNotNull('refreshed_at')->count(), 'Only the call that opens the year refreshes it.');
    }

    public function test_the_year_end_capture_opens_a_year_that_computes_at_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:58:00', 'Asia/Manila'));
        Auth::logout();

        $this->artisan('cda:capture-year-end')->assertSuccessful();

        $period = CdaReportingPeriod::query()->where('year', 2026)->firstOrFail();
        $this->assertNotNull($period->year_end_captured_at);
        $this->assertNull($period->created_by, 'The capture opens the year as the system.');
        $this->assertRefreshedExcept($period, null);

        $capr = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->firstOrFail();
        $this->assertNull(
            collect($capr->issues)->firstWhere('rule_id', 'classification_captured'),
            'CAPR is refreshed after the capture is saved, so it never warns that the capture hasn\'t run.',
        );
        $outstanding = $capr->values()->where('field_key', 'loans.loans_outstanding')->firstOrFail();
        $this->assertSame('capture', $outstanding->source_value['meta']['basis'] ?? null, 'CAPR reads its loans from the year-end capture.');
        $this->assertFalse($outstanding->source_value['provisional'], 'Figures from the capture aren\'t provisional.');
    }

    public function test_opening_a_year_still_answers_201_when_one_reports_refresh_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-04-20 09:00:00', 'Asia/Manila'));
        Exceptions::fake();
        $this->failRefreshOf(self::FAILING);

        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated()->assertJsonPath('data.year', 2026);

        $period = CdaReportingPeriod::query()->where('year', 2026)->firstOrFail();
        $this->assertSame(11, CdaReport::query()->where('period_id', $period->id)->count());
        $this->assertRefreshedExcept($period, self::FAILING);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Refresh of social_audit failed in the test.');
    }

    public function test_the_year_end_capture_still_opens_and_captures_when_one_reports_refresh_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:58:00', 'Asia/Manila'));
        Auth::logout();
        Exceptions::fake();
        $this->failRefreshOf(self::FAILING);

        $this->artisan('cda:capture-year-end')->assertSuccessful();

        $period = CdaReportingPeriod::query()->where('year', 2026)->firstOrFail();
        $this->assertNotNull($period->year_end_captured_at, 'The capture is kept.');
        $this->assertSame(11, CdaReport::query()->where('period_id', $period->id)->count());
        $this->assertRefreshedExcept($period, self::FAILING);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Refresh of social_audit failed in the test.');
    }
}
