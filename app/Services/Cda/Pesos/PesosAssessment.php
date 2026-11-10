<?php

namespace App\Services\Cda\Pesos;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportTemplate;
use App\Services\Cda\Exports\SnapshotReader;
use App\Services\Cda\Sources\SourceCatalog;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use App\Services\Cda\Sources\SourceResult;
use App\Services\Cda\Sources\SourceUnavailable;
use InvalidArgumentException;

/**
 * A year's Enhanced PESOS (CDA design §8): the year's PESOS report template
 * (else the active PESOS template), each indicator's numerator and
 * denominator resolved through the source registry, and PesosEngine's scores.
 *
 * A source that isn't available (not built, unknown, bad params) or gives no
 * figure is missing data, never an error. `ratio_avg` reads the denominator's
 * opening balance with `as_of: prior_period_end`. The result is provisional
 * when the template isn't verified or any figure used is provisional.
 */
final class PesosAssessment
{
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly PesosEngine $engine,
    ) {}

    /** @return array<string, mixed> the engine's result plus year, as_of and provisional */
    public function for(SourceContext $context): array
    {
        return $context->remember('pesos:assessment', fn (): SourceResult => new SourceResult(
            $this->assess($context),
            'Enhanced PESOS',
            'any',
        ))->value;
    }

    /** The PESOS report of a period, when there is one. */
    public function reportFor(CdaReportingPeriod $period): ?CdaReport
    {
        return CdaReport::query()->where('period_id', $period->id)->where('report_type', 'pesos')->where('part', 'annual')->first();
    }

    /**
     * The score of each earlier year with a finalized PESOS snapshot, then
     * this year's.
     *
     * @return list<array{year: int, score: string|null}>
     */
    public function trend(CdaReportingPeriod $period, ?string $score): array
    {
        $earlier = CdaReport::query()
            ->join('cda_reporting_periods', 'cda_reporting_periods.id', '=', 'cda_reports.period_id')
            ->where('cda_reporting_periods.year', '<', $period->year)
            ->where('cda_reports.report_type', 'pesos')
            ->whereNotNull('cda_reports.current_snapshot_id')
            ->orderBy('cda_reporting_periods.year')
            ->get(['cda_reports.current_snapshot_id', 'cda_reporting_periods.year']);

        $snapshots = CdaReportSnapshot::query()->whereIn('id', $earlier->pluck('current_snapshot_id'))->get()->keyBy('id');

        $trend = $earlier->map(function ($row) use ($snapshots): array {
            $snapshot = $snapshots->get($row->current_snapshot_id);
            $value = $snapshot === null ? null : (SnapshotReader::values(SnapshotReader::payload($snapshot))['result.score'] ?? null);

            return ['year' => (int) $row->year, 'score' => $value === null ? null : (string) $value];
        })->values()->all();

        $trend[] = ['year' => (int) $period->year, 'score' => $score];

        return $trend;
    }

    /** @return array<string, mixed> */
    private function assess(SourceContext $context): array
    {
        $template = $this->template($context);
        $pesos = $template?->definition['pesos'] ?? ['components' => [], 'indicators' => [], 'rating_bands' => []];
        $inputs = [];
        $provisional = false;

        foreach ($pesos['indicators'] as $indicator) {
            $sources = [];
            $missing = [];
            $figures = [];

            foreach (['numerator', 'denominator'] as $side) {
                $result = $this->figure($indicator[$side] ?? null, $context, $missing);
                $figures[$side] = $result?->value;
                $sources[$side] = $result?->label ?? (SourceCatalog::KEYS[$indicator[$side]['key'] ?? '']['label'] ?? $side);
                $provisional = $provisional || (bool) $result?->provisional;
            }

            if ($indicator['formula_key'] === 'ratio_avg') {
                $spec = $indicator['denominator'] ?? [];
                $spec['params'] = array_merge((array) ($spec['params'] ?? []), ['as_of' => 'prior_period_end']);
                $result = $this->figure($spec, $context, $missing);
                $figures['denominator_opening'] = $result?->value;
                $sources['denominator_opening'] = $result?->label ?? 'Opening balance';
            }

            $inputs[$indicator['key']] = [...$figures, 'sources' => $sources, 'missing' => $missing];
        }

        $verified = (bool) ($template?->isVerified() ?? false);
        $result = $this->engine->evaluate($pesos, $inputs, $verified);

        return [
            'year' => $context->year(),
            'as_of' => $context->date('period_end'),
            'template' => $template === null ? null : ['id' => (int) $template->id, 'version' => $template->version, 'name' => $template->name],
            'provisional' => ! $verified || $provisional,
            ...$result,
        ];
    }

    private function template(SourceContext $context): ?CdaReportTemplate
    {
        $report = $context->report?->report_type === 'pesos' ? $context->report : $this->reportFor($context->period);

        return $report?->template()->first()
            ?? CdaReportTemplate::query()->where('report_type', 'pesos')->where('status', CdaReportTemplate::STATUS_ACTIVE)
                ->orderByDesc('effective_from')->first();
    }

    /**
     * A figure as a decimal string or int, or null (with the reason added to
     * `$missing`) when it isn't available.
     *
     * @param  array<string, mixed>|null  $spec
     * @param  list<string>  $missing
     */
    private function figure(?array $spec, SourceContext $context, array &$missing): ?SourceResult
    {
        $key = (string) ($spec['key'] ?? '');
        $label = SourceCatalog::KEYS[$key]['label'] ?? $key;

        if (! SourceCatalog::has($key) || SourceCatalog::family($key) === 'pesos') {
            $missing[] = "{$label} is not a figure PESOS can use";

            return null;
        }

        try {
            $result = $this->registry->resolve($key, (array) ($spec['params'] ?? []), $context);
        } catch (SourceUnavailable|InvalidArgumentException $e) {
            $missing[] = $e->getMessage();

            return null;
        }

        if ($result->value === null || (! is_int($result->value) && ! is_numeric($result->value))) {
            $missing[] = "{$result->label} has no figure";

            return null;
        }

        return $result;
    }
}
