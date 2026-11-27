<?php

namespace App\Services\Cda\Pesos;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportTemplate;
use App\Services\Cda\Exports\SnapshotReader;
use App\Services\Cda\Sources\SourceCatalog;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceResult;

/**
 * A year's Enhanced PESOS (CDA design §8): the year's PESOS report template
 * (else the active PESOS template), each indicator's numerator and
 * denominator resolved through the source registry, and PesosEngine's scores.
 *
 * Figures come through PesosFigures: one that isn't available or gives no
 * number is missing data, never an error. `ratio_avg` reads the denominator's
 * opening balance with `as_of: prior_period_end`. The result is provisional
 * when the template isn't verified or any figure used (the opening balance
 * included) is provisional.
 */
final class PesosAssessment
{
    public function __construct(
        private readonly PesosFigures $figures,
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
     * The score of each earlier year whose PESOS report is finalized or later
     * (LOCKED_STATUSES) with a current snapshot that isn't superseded, then
     * this year's. A reopened year is left out until it is finalized again.
     *
     * @return list<array{year: int, score: string|null}>
     */
    public function trend(CdaReportingPeriod $period, ?string $score): array
    {
        $earlier = CdaReport::query()
            ->join('cda_reporting_periods', 'cda_reporting_periods.id', '=', 'cda_reports.period_id')
            ->where('cda_reporting_periods.year', '<', $period->year)
            ->where('cda_reports.report_type', 'pesos')
            ->whereIn('cda_reports.status', CdaReport::LOCKED_STATUSES)
            ->whereNotNull('cda_reports.current_snapshot_id')
            ->orderBy('cda_reporting_periods.year')
            ->get(['cda_reports.current_snapshot_id', 'cda_reporting_periods.year']);

        $snapshots = CdaReportSnapshot::query()->whereIn('id', $earlier->pluck('current_snapshot_id'))->whereNull('superseded_at')->get()->keyBy('id');

        $trend = $earlier->filter(fn ($row) => $snapshots->has($row->current_snapshot_id))->map(function ($row) use ($snapshots): array {
            $value = SnapshotReader::values(SnapshotReader::payload($snapshots->get($row->current_snapshot_id)))['result.score'] ?? null;

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
                $result = $this->figures->resolve($indicator[$side] ?? null, $context, $missing);
                $figures[$side] = $result?->value;
                $sources[$side] = $result?->label ?? (SourceCatalog::KEYS[$indicator[$side]['key'] ?? '']['label'] ?? $side);
                $provisional = $provisional || (bool) $result?->provisional;
            }

            if ($indicator['formula_key'] === 'ratio_avg') {
                $spec = $indicator['denominator'] ?? [];
                $spec['params'] = array_merge((array) ($spec['params'] ?? []), ['as_of' => 'prior_period_end']);
                $result = $this->figures->resolve($spec, $context, $missing);
                $figures['denominator_opening'] = $result?->value;
                $sources['denominator_opening'] = $result?->label ?? 'Opening balance';
                $provisional = $provisional || (bool) $result?->provisional;
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
}
