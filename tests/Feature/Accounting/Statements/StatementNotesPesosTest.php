<?php

namespace Tests\Feature\Accounting\Statements;

use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Pesos\PesosAssessment;
use App\Services\Cda\Sources\SourceContext;
use Illuminate\Support\Carbon;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /accounting/statements/notes?year= (gap round B4): the Enhanced PESOS
 * rating for the notes to the financial statements. The finalized PESOS
 * report's snapshot when there is one, else the live result marked
 * provisional; `verified` stays false while the PESOS rules are
 * placeholders. Needs `accounting:view` and `branches:view_all`.
 */
class StatementNotesPesosTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private const URI = '/api/accounting/statements/notes?year=2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-02-10 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_needs_accounting_view_and_branches_view_all(): void
    {
        $this->actingAs($this->userWith(['branches:view_all']))->getJson(self::URI)->assertForbidden();
        $this->actingAs($this->userWith(['accounting:view']))->getJson(self::URI)->assertForbidden()
            ->assertJsonPath('message', 'The notes cover the whole cooperative, so they need access to every branch.');
        $this->actingAs($this->userWith(['accounting:view', 'branches:view_all']))->getJson(self::URI)->assertOk();
    }

    public function test_a_bad_year_answers_422(): void
    {
        $this->getJson('/api/accounting/statements/notes?year=abc')->assertUnprocessable()->assertJsonValidationErrors(['year']);
    }

    public function test_without_a_cda_year_it_answers_nulls(): void
    {
        $this->getJson(self::URI)->assertOk()->assertExactJson(['data' => ['pesos' => [
            'year' => 2026,
            'score' => null,
            'rating' => null,
            'source' => null,
            'provisional' => null,
            'finalized_at' => null,
            'verified' => false,
            'disclosure' => null,
        ]]]);
    }

    public function test_an_unfinalized_year_reads_the_live_result_marked_provisional(): void
    {
        $period = app(PeriodOpener::class)->ensure(2026);
        $assessment = app(PesosAssessment::class);
        $live = $assessment->for(new SourceContext($period, $assessment->reportFor($period)));

        $data = $this->getJson(self::URI)->assertOk()->json('data.pesos');

        $this->assertSame('live', $data['source']);
        $this->assertTrue($data['provisional']);
        $this->assertFalse($data['verified']);
        $this->assertSame($live['score'], $data['score']);
        $this->assertSame($live['rating'], $data['rating']);
        $this->assertNull($data['finalized_at']);
        $this->assertStringContainsString('MC 2025-12', $data['disclosure']);
    }

    public function test_a_finalized_year_reads_its_snapshot(): void
    {
        $period = app(PeriodOpener::class)->ensure(2026);
        $report = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'pesos')->firstOrFail();
        $snapshot = CdaReportSnapshot::query()->create([
            'report_id' => $report->id,
            'version' => 1,
            'template_id' => $report->template_id,
            'payload' => json_encode(['values' => [
                ['field_key' => 'result.score', 'value' => '78.00', 'source_value' => ['meta' => ['to_verify' => ['The weights']]]],
                ['field_key' => 'result.rating', 'value' => 'Good'],
            ]]),
            'checksum' => str_repeat('a', 64),
            'finalized_by' => $this->admin->id,
            'finalized_at' => Carbon::parse('2027-02-01 15:30:00', 'Asia/Manila'),
        ]);
        $report->forceFill(['status' => CdaReport::STATUS_FINALIZED, 'current_snapshot_id' => $snapshot->id])->save();

        $data = $this->getJson(self::URI)->assertOk()->json('data.pesos');

        $this->assertSame(['year' => 2026, 'score' => '78.00', 'rating' => 'Good', 'source' => 'finalized', 'provisional' => false], array_intersect_key($data, array_flip(['year', 'score', 'rating', 'source', 'provisional'])));
        $this->assertSame($snapshot->finalized_at->toJSON(), $data['finalized_at']);
        $this->assertFalse($data['verified'], 'Placeholder rules: still to verify.');
        $this->assertNotNull($data['disclosure']);
    }

    public function test_a_reopened_years_superseded_snapshot_is_not_used(): void
    {
        $period = app(PeriodOpener::class)->ensure(2026);
        $report = CdaReport::query()->where('period_id', $period->id)->where('report_type', 'pesos')->firstOrFail();
        $snapshot = CdaReportSnapshot::query()->create([
            'report_id' => $report->id, 'version' => 1, 'template_id' => $report->template_id,
            'payload' => json_encode(['values' => [['field_key' => 'result.score', 'value' => '50.00']]]),
            'checksum' => str_repeat('b', 64), 'finalized_by' => $this->admin->id, 'finalized_at' => now(), 'superseded_at' => now(),
        ]);
        $report->forceFill(['status' => CdaReport::STATUS_DRAFT, 'current_snapshot_id' => $snapshot->id])->save();

        $this->assertSame('live', $this->getJson(self::URI)->assertOk()->json('data.pesos.source'));
    }
}
