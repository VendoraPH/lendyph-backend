<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\User;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use App\Services\Cda\Sources\SourceUnavailable;
use Illuminate\Support\Carbon;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /cda/periods/{year}/pesos and the `pesos.result` source (CDA design
 * §8): the year's PESOS template run through the engine on the sources it
 * names, with every result marked "to verify", and a trend from the finalized
 * snapshots of earlier years.
 */
class PesosAssessmentTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private const URI = '/api/cda/periods/2026/pesos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function viewer(): User
    {
        return $this->userWith(['cda:view', 'branches:view_all']);
    }

    public function test_it_scores_the_template_and_marks_everything_to_verify(): void
    {
        app(PeriodOpener::class)->ensure(2026);

        $data = $this->actingAs($this->viewer())->getJson(self::URI)->assertOk()->json('data');

        $this->assertSame(2026, $data['year']);
        $this->assertFalse($data['verified']);
        $this->assertCount(11, $data['to_verify']);
        $this->assertStringContainsString('MC 2025-12', $data['disclosure']);
        $this->assertSame(['portfolio_quality', 'efficiency', 'solvency', 'operations', 'sustainability'], array_column($data['components'], 'key'));
        $this->assertCount(6, $data['metrics']);

        foreach ($data['metrics'] as $metric) {
            $this->assertContains($metric['status'], ['scored', 'missing_data', 'not_computable']);
            $this->assertTrue($metric['to_verify']);
            $this->assertNotSame('', $metric['formula']);
            $this->assertNotSame('', $metric['source']);
        }

        $coverage = collect($data['metrics'])->firstWhere('key', 'allowance_coverage');
        $this->assertSame('scored', $coverage['status'], 'With nothing to provide for, the allowance covers it.');
        $this->assertSame('100.00', $coverage['value']);
        $this->assertSame('15.00', $coverage['score']);
        $this->assertSame('15.00', $coverage['weight']);
        $this->assertSame([['year' => 2026, 'score' => $data['score']]], $data['trend']);
    }

    public function test_the_trend_reads_earlier_years_finalized_snapshots(): void
    {
        $earlier = app(PeriodOpener::class)->ensure(2025);
        app(PeriodOpener::class)->ensure(2026);
        $report = CdaReport::query()->where('period_id', $earlier->id)->where('report_type', 'pesos')->firstOrFail();
        $snapshot = CdaReportSnapshot::query()->create([
            'report_id' => $report->id,
            'version' => 1,
            'template_id' => $report->template_id,
            'payload' => json_encode(['values' => [['field_key' => 'result.score', 'value' => '72.50'], ['field_key' => 'result.rating', 'value' => 'Fair']]]),
            'checksum' => str_repeat('a', 64),
            'finalized_by' => $this->admin->id,
            'finalized_at' => now(),
        ]);
        $report->forceFill(['status' => CdaReport::STATUS_FINALIZED, 'current_snapshot_id' => $snapshot->id])->save();

        $trend = $this->actingAs($this->viewer())->getJson(self::URI)->assertOk()->json('data.trend');

        $this->assertSame(2025, $trend[0]['year']);
        $this->assertSame('72.50', $trend[0]['score']);
        $this->assertSame(2026, $trend[1]['year']);
    }

    public function test_it_needs_cda_view_and_branches_view_all(): void
    {
        app(PeriodOpener::class)->ensure(2026);

        $this->actingAs($this->userWith(['branches:view_all']))->getJson(self::URI)->assertForbidden();
        $this->actingAs($this->userWith(['cda:view']))->getJson(self::URI)->assertForbidden();
        $this->actingAs($this->viewer())->getJson('/api/cda/periods/2031/pesos')->assertNotFound();
    }

    public function test_the_pesos_result_source_gives_the_template_fields(): void
    {
        $period = app(PeriodOpener::class)->ensure(2026);
        $registry = app(SourceRegistry::class);
        $context = new SourceContext($period);

        $indicators = $registry->resolve('pesos.result', ['field' => 'indicators'], $context);
        $this->assertSame('table', $indicators->unit);
        $this->assertCount(6, $indicators->value);
        $this->assertSame(['key', 'component', 'label', 'value', 'score', 'weight', 'status'], array_keys($indicators->value[0]));
        $this->assertFalse($indicators->meta['verified']);

        $rating = $registry->resolve('pesos.result', ['field' => 'rating'], $context);
        $this->assertSame('text', $rating->unit);
        $this->assertTrue($rating->provisional, 'Unverified, so it may still change.');

        $this->expectException(SourceUnavailable::class);
        $registry->resolve('pesos.result', ['field' => 'nope'], $context);
    }
}
