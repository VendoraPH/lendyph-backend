<?php

namespace Tests\Feature\Phase6\ExecutiveFigures;

use App\Services\Dashboard\LoanScope;
use App\Services\Dashboard\MetricCatalog;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 6 EB-A: the metric catalogue, each executive dashboard figure's
 * definition, source, date rule, the filters it honours, the permissions it
 * needs and whether it has history, for tooltips and exports.
 */
class MetricCatalogTest extends TestCase
{
    public function test_every_metric_is_fully_described(): void
    {
        $metrics = MetricCatalog::all();

        $this->assertNotEmpty($metrics);

        foreach ($metrics as $key => $metric) {
            $this->assertSame($key, $metric['key']);
            $this->assertSame(
                ['key', 'label', 'definition', 'formula', 'unit', 'date_rule', 'source', 'filters', 'permissions', 'has_history'],
                array_keys($metric),
                $key,
            );
            foreach (['label', 'definition', 'formula', 'source'] as $text) {
                $this->assertNotSame('', trim($metric[$text]), "{$key}.{$text}");
            }
            $this->assertContains($metric['unit'], ['money', 'percent', 'count'], $key);
            $this->assertContains($metric['date_rule'], ['as_of_today', 'period'], $key);
            $this->assertIsBool($metric['has_history'], $key);
            $this->assertSame([], array_diff($metric['filters'], LoanScope::FILTERS), $key);
            $this->assertContains('branch_id', $metric['filters'], "{$key}: every figure follows the branch scope.");
            $this->assertNotEmpty($metric['permissions'], $key);
        }
    }

    public function test_the_spec_s_figures_are_in_it(): void
    {
        foreach ([
            'gross_loan_portfolio', 'total_outstanding', 'active_loans', 'active_members', 'loans_released',
            'collections', 'collection_efficiency', 'past_due_amount', 'par_1', 'par_30', 'par_60', 'par_90',
            'aging_by_loan', 'product_share', 'required_allowance', 'share_capital', 'collected_insurance', 'interest_income', 'net_income',
        ] as $key) {
            $this->assertArrayHasKey($key, MetricCatalog::all(), $key);
        }
    }

    public function test_each_permission_it_names_exists(): void
    {
        $permissions = collect(MetricCatalog::all())->pluck('permissions')->flatten()->unique()->values();

        $this->assertSame(
            [],
            $permissions->diff(Permission::query()->pluck('name'))->values()->all(),
        );
    }

    public function test_a_metric_honours_only_what_its_report_honours(): void
    {
        foreach (MetricCatalog::all() as $key => $metric) {
            $report = MetricCatalog::REPORTS[$metric['source']] ?? MetricCatalog::OTHER_SOURCES[$metric['source']] ?? null;

            $this->assertNotNull($report, "{$key}: its source is a listed report.");
            $this->assertSame([], array_diff($metric['filters'], [...$report['full'], ...array_keys($report['partial'])]), $key);
        }
    }

    public function test_each_listed_report_is_a_real_route(): void
    {
        $paths = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();

        foreach (array_keys(MetricCatalog::OTHER_SOURCES) as $source) {
            $this->assertContains("api/{$source}", $paths, $source);
        }

        foreach (array_keys(MetricCatalog::REPORTS) as $report) {
            $this->assertContains("api/reports/{$report}", $paths, $report);
        }
    }
}
