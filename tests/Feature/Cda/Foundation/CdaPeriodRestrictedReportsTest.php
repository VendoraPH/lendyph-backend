<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Services\BranchAccess;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\ReportTypes;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The period endpoints leave restricted reports out entirely, the same
 * standard as the dashboard and calendar: `GET /cda/periods`,
 * `GET /cda/periods/{year}` and the `POST /cda/periods` response show a
 * mediation, audit or tax report only to a holder of its view permission.
 * No row, status, deadline, extension date or reference is left behind,
 * and `report_count` counts only the reports the caller may open.
 */
class CdaPeriodRestrictedReportsTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    /** Each restricted view, the report types it opens, and a deadline override only those reports carry. */
    private const VIEWS = [
        CdaAccess::VIEW_MEDIATION => ['types' => ['mediation'], 'override' => '2027-05-11', 'reference' => 'Mediation extension letter MX-11'],
        CdaAccess::VIEW_AUDIT => ['types' => ['financial_statements', 'performance_audit'], 'override' => '2027-05-12', 'reference' => 'Audit extension letter AX-12'],
        CdaAccess::VIEW_TAX => ['types' => ['atir', 'abr'], 'override' => '2027-05-13', 'reference' => 'Tax extension letter TX-13'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-04-20 09:00:00', 'Asia/Manila'));
        $this->withCte('valid', '2028-12-31');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> */
    private static function allViewsBut(string $view): array
    {
        return array_values(array_diff(CdaAccess::RESTRICTED_VIEWS, [$view]));
    }

    /** Sets each restricted report's own deadline override, so its deadline and reference can be looked for. */
    private function overrideRestrictedDeadlines(int $year): void
    {
        $period = CdaReportingPeriod::query()->where('year', $year)->firstOrFail();

        foreach (self::VIEWS as $spec) {
            CdaReport::query()->where('period_id', $period->id)->whereIn('report_type', $spec['types'])
                ->update(['due_override_on' => $spec['override'], 'due_override_reference' => $spec['reference']]);
        }
    }

    /** @param  list<string>  $types */
    private function assertLeftOut(TestResponse $response, array $types, string $view, string $label): void
    {
        $text = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($types as $type) {
            $this->assertStringNotContainsString(ReportTypes::title($type), $text, "{$label}: the {$type} title is hidden without {$view}.");
            $this->assertStringNotContainsString('"'.$type.'"', $text, "{$label}: the {$type} type is hidden without {$view}.");
        }

        $this->assertStringNotContainsString(self::VIEWS[$view]['override'], $text, "{$label}: the restricted deadline is hidden without {$view}.");
        $this->assertStringNotContainsString(self::VIEWS[$view]['reference'], $text, "{$label}: the restricted deadline reference is hidden without {$view}.");
    }

    /** @param  list<string>  $types */
    private function assertShown(TestResponse $response, array $types, string $view, string $label, bool $withDeadline = true): void
    {
        $text = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($types as $type) {
            $this->assertStringContainsString(ReportTypes::title($type), $text, "{$label}: a holder of {$view} sees the {$type} title.");
            $this->assertStringContainsString('"'.$type.'"', $text, "{$label}: a holder of {$view} sees the {$type} type.");
        }

        if ($withDeadline) {
            $this->assertStringContainsString(self::VIEWS[$view]['override'], $text, "{$label}: a holder of {$view} sees the deadline.");
            $this->assertStringContainsString(self::VIEWS[$view]['reference'], $text, "{$label}: a holder of {$view} sees the reference.");
        }
    }

    private static function restrictedRowCount(string $view): int
    {
        return array_sum(array_map(fn (string $type) => count(ReportTypes::parts($type)), self::VIEWS[$view]['types']));
    }

    public function test_the_period_detail_leaves_each_restricted_report_out_without_its_view(): void
    {
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();
        $this->overrideRestrictedDeadlines(2026);

        foreach (self::VIEWS as $view => $spec) {
            $without = $this->userWith([CdaAccess::VIEW, BranchAccess::VIEW_ALL, ...self::allViewsBut($view)]);
            $response = $this->actingAs($without)->getJson('/api/cda/periods/2026')->assertOk();
            $this->assertLeftOut($response, $spec['types'], $view, 'GET /cda/periods/2026');
            $this->assertCount(11 - self::restrictedRowCount($view), $response->json('data.reports'));
            $this->assertFalse(collect($response->json('data.reports'))->contains('restricted', true), 'No row is left behind marked restricted.');

            $with = $this->userWith([CdaAccess::VIEW, BranchAccess::VIEW_ALL, $view]);
            $response = $this->actingAs($with)->getJson('/api/cda/periods/2026')->assertOk();
            $this->assertShown($response, $spec['types'], $view, 'GET /cda/periods/2026');
        }
    }

    public function test_the_period_list_counts_only_the_reports_the_caller_may_open(): void
    {
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();
        $this->overrideRestrictedDeadlines(2026);

        $none = $this->userWith([CdaAccess::VIEW, BranchAccess::VIEW_ALL]);
        $this->actingAs($none)->getJson('/api/cda/periods')->assertOk()->assertJsonPath('data.0.report_count', 5);

        foreach (self::VIEWS as $view => $spec) {
            $without = $this->userWith([CdaAccess::VIEW, BranchAccess::VIEW_ALL, ...self::allViewsBut($view)]);
            $response = $this->actingAs($without)->getJson('/api/cda/periods')->assertOk();
            $this->assertLeftOut($response, $spec['types'], $view, 'GET /cda/periods');
            $this->assertSame(11 - self::restrictedRowCount($view), $response->json('data.0.report_count'), "Without {$view}, its reports aren't counted.");

            $with = $this->userWith([CdaAccess::VIEW, BranchAccess::VIEW_ALL, $view]);
            $this->assertSame(5 + self::restrictedRowCount($view), $this->actingAs($with)->getJson('/api/cda/periods')->assertOk()->json('data.0.report_count'));
        }

        $all = $this->userWith([CdaAccess::VIEW, BranchAccess::VIEW_ALL, ...CdaAccess::RESTRICTED_VIEWS]);
        $this->actingAs($all)->getJson('/api/cda/periods')->assertOk()->assertJsonPath('data.0.report_count', 11);
    }

    public function test_opening_a_year_answers_with_only_the_reports_the_caller_may_open(): void
    {
        $year = 2020;

        foreach (self::VIEWS as $view => $spec) {
            $without = $this->userWith([CdaAccess::PREPARE, BranchAccess::VIEW_ALL, ...self::allViewsBut($view)]);
            $response = $this->actingAs($without)->postJson('/api/cda/periods', ['year' => $year])->assertCreated();
            $this->assertLeftOut($response, $spec['types'], $view, "POST /cda/periods {$year}");
            $this->assertCount(11 - self::restrictedRowCount($view), $response->json('data.reports'));
            $this->assertSame(11, CdaReport::query()->where('period_id', $response->json('data.id'))->count(), 'Every report is still created.');
            if ($view === CdaAccess::VIEW_MEDIATION) {
                $this->assertStringNotContainsString('-07-30', json_encode($response->json()), 'The H1 mediation deadline is hidden too.');
            }
            $year++;

            $with = $this->userWith([CdaAccess::PREPARE, BranchAccess::VIEW_ALL, $view]);
            $response = $this->actingAs($with)->postJson('/api/cda/periods', ['year' => $year])->assertCreated();
            $this->assertShown($response, $spec['types'], $view, "POST /cda/periods {$year}", false);
            $year++;
        }
    }
}
