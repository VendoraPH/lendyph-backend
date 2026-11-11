<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\CdaReport;
use App\Services\Cda\Reports\ReportEngine;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The `date_range` rule's bounds (C1 eeebed9): `from` and `to` are each
 * period_start, period_end, effective_deadline or today, defaulting to the
 * period, and a date is never in the future. A sworn statement signed in the
 * filing year, after Dec 31 and before the deadline, raises no Warning.
 */
class CdaDateRangeTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function swornStatement(array $bounds): CdaReport
    {
        $this->activeTemplate('sworn_statement', [
            'schema_version' => 1,
            'meta' => ['verified' => true],
            'parts' => ['annual'],
            'sections' => [['key' => 'statement', 'title' => 'Statement', 'kind' => 'fields', 'fields' => [
                ['key' => 'statement_date', 'label' => 'Date of the statement', 'type' => 'date', 'required' => true],
            ]]],
            'rules' => [['id' => 'statement_date_in_range', 'type' => 'date_range', 'field' => 'statement_date', 'severity' => 'warning', 'category' => 'invalid_data', 'component' => 'fields', ...$bounds]],
        ]);
        $report = $this->report($this->openYear(), 'sworn_statement');
        app(ReportEngine::class)->refresh($report);

        return $report->refresh();
    }

    /** @return list<string> the date rule's messages after saving the date */
    private function dateIssues(CdaReport $report, string $date): array
    {
        $issues = $this->actingAs($this->cdaStaff())->putJson("/api/cda/reports/{$report->id}", ['fields' => [['field_key' => 'statement.statement_date', 'value' => $date]]])
            ->assertOk()->json('data.issues');

        return collect($issues)->where('rule_id', 'statement_date_in_range')->pluck('message')->values()->all();
    }

    public function test_a_statement_signed_in_the_filing_year_before_the_deadline_raises_no_warning(): void
    {
        $report = $this->swornStatement(['from' => 'period_start', 'to' => 'effective_deadline']);

        $this->assertSame([], $this->dateIssues($report, '2027-01-20'));
        $this->assertSame([], $this->dateIssues($report, '2026-06-30'));
        $this->assertSame(['Date of the statement must be a date from Jan 1, 2026 to Apr 30, 2027.'], $this->dateIssues($report, '2025-12-31'));
        $this->assertSame(['Date of the statement can\'t be in the future.'], $this->dateIssues($report, '2027-03-01'));
    }

    public function test_the_bounds_follow_the_reports_own_deadline(): void
    {
        $report = $this->swornStatement(['from' => 'period_start', 'to' => 'effective_deadline']);
        $report->forceFill(['due_override_on' => '2027-01-31', 'due_override_reference' => 'CDA letter 12'])->save();

        $this->assertSame(['Date of the statement must be a date from Jan 1, 2026 to Jan 31, 2027.'], $this->dateIssues($report, '2027-02-05'));
    }

    public function test_without_bounds_the_date_stays_within_the_period(): void
    {
        $report = $this->swornStatement([]);

        $this->assertSame(['Date of the statement must be a date from Jan 1, 2026 to Dec 31, 2026.'], $this->dateIssues($report, '2027-01-20'));
        $this->assertSame([], $this->dateIssues($report, '2026-12-31'));
    }
}
