<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaReport;
use App\Models\CdaReportValue;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cda\Sources\SourceCatalog;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use App\Services\Cda\Sources\SourceUnavailable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;

/**
 * The report engine (design §2, §4): fills a report's fields from its
 * template, keeps them, and validates them.
 *
 * - refresh(): reads every sourced field from its source, recomputes the
 *   calculations and re-validates. Stored source values change only here:
 *   on create, on the `refresh` action, on submit_for_review and in the
 *   nightly run, never on a GET. An overridden field keeps its value; its
 *   source figure is still updated beside it. One audit row when values
 *   changed.
 * - recompute(): after a save, the calculations, issues and readiness from
 *   the stored values, without reading sources again.
 * - freshValues(): what a refresh would give now, without writing; the
 *   finalize compares it with the approved values.
 *
 * Each field's source result is kept in `source_value` (value, label, unit,
 * as of, provisional, meta), or `{error}` when the source couldn't be read,
 * so the screen and the snapshot show where each figure came from.
 */
final class ReportEngine
{
    public const CALCULATED = 'Calculated';

    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly CalcEngine $calc,
        private readonly ReportValidator $validator,
    ) {}

    public function definition(CdaReport $report): ReportDefinition
    {
        $template = $report->relationLoaded('template') ? $report->template : $report->template()->first();

        return new ReportDefinition($template?->definition ?? ['sections' => []]);
    }

    public function context(CdaReport $report): SourceContext
    {
        return new SourceContext($report->relationLoaded('period') ? $report->period : $report->period()->firstOrFail(), $report);
    }

    /**
     * Read the sources, store the figures, recompute and re-validate.
     *
     * @return array<string, array{0: mixed, 1: mixed}> the figures that changed, full key => [old, new]
     */
    public function refresh(CdaReport $report, ?User $user = null, bool $audit = true): array
    {
        return DB::transaction(function () use ($report, $user, $audit): array {
            $locked = CdaReport::query()->with(['template', 'period'])->whereKey($report->id)->lockForUpdate()->firstOrFail();
            $definition = $this->definition($locked);
            $context = $this->context($locked);
            $stored = $this->storedRows($locked);
            [$values, $info] = $this->compute($definition, $context, $stored);

            $changed = [];
            $now = now();

            foreach ($definition->fields() as $key => $field) {
                if ($definition->isManual($field)) {
                    continue;
                }

                $row = $stored[$key] ?? new CdaReportValue(['report_id' => $locked->id, 'field_key' => $key]);
                $old = $row->exists ? $row->value : null;
                $isOverride = $row->exists && $row->is_override;
                $new = $isOverride ? $old : $values[$key];

                if (! self::same($old, $new)) {
                    $changed[$key] = [$old, $new];
                }

                $row->fill([
                    'value' => $new,
                    'source_key' => $field['source']['key'] ?? null,
                    'source_label' => isset($field['calc']) ? self::CALCULATED : ($info[$key]['label'] ?? null),
                    'source_params' => $field['source']['params'] ?? null,
                    'source_value' => $info[$key] ?? null,
                    'refreshed_at' => $now,
                ]);

                if (! $row->exists || $row->isDirty()) {
                    $row->save();
                }
            }

            $this->validate($locked, $definition, $values, $info, $context);
            $locked->forceFill(['refreshed_at' => $now, 'stale_at' => null])->save();

            if ($changed !== [] && $audit) {
                AuditLogService::log(
                    'cda_report_refreshed',
                    $locked,
                    ['values' => array_map(fn (array $pair) => $pair[0], $changed)],
                    ['values' => array_map(fn (array $pair) => $pair[1], $changed)],
                    sprintf('Refreshed %s %d: %d %s changed.', ReportValidator::reportName($locked), $locked->period->year, count($changed), count($changed) === 1 ? 'figure' : 'figures'),
                    $user?->id,
                );
            }

            $report->setRawAttributes($locked->getAttributes(), true);

            return $changed;
        });
    }

    /** After a save: the calculations, issues and readiness from the stored values. */
    public function recompute(CdaReport $report): void
    {
        $report->loadMissing(['template', 'period']);
        $definition = $this->definition($report);
        $context = $this->context($report);
        $stored = $this->storedRows($report);
        $values = array_map(fn (CdaReportValue $row) => $row->value, $stored);
        $info = array_map(fn (CdaReportValue $row) => is_array($row->source_value) ? $row->source_value : null, $stored);

        foreach ($definition->calculated() as $key => $field) {
            [$value, $error] = $this->calculate($field, $definition, $values);
            $values[$key] = $value;
            $info[$key] = $error === null ? null : ['error' => $error];
            $row = $stored[$key] ?? new CdaReportValue(['report_id' => $report->id, 'field_key' => $key, 'source_label' => self::CALCULATED]);

            if (! $row->exists || ! self::same($row->value, $value) || $row->source_value !== $info[$key]) {
                $row->fill(['value' => $value, 'source_value' => $info[$key], 'source_label' => self::CALCULATED, 'refreshed_at' => now()])->save();
            }
        }

        $this->validate($report, $definition, $values, $info, $context);
    }

    /**
     * What a refresh would store now, without writing: the sourced and
     * calculated figures (overrides kept).
     *
     * @return array<string, mixed>
     */
    public function freshValues(CdaReport $report): array
    {
        $report->loadMissing(['template', 'period']);
        $definition = $this->definition($report);
        $stored = $this->storedRows($report);
        [$values] = $this->compute($definition, $this->context($report), $stored);

        foreach ($stored as $key => $row) {
            if ($row->is_override) {
                $values[$key] = $row->value;
            }
        }

        return $values;
    }

    /** @return array<string, mixed> full key => stored value */
    public function storedValues(CdaReport $report): array
    {
        return array_map(fn (CdaReportValue $row) => $row->value, $this->storedRows($report));
    }

    /**
     * Re-run the validation from stored values (approve and finalize re-check
     * documents and named checks without touching the figures).
     */
    public function revalidate(CdaReport $report): void
    {
        $report->loadMissing(['template', 'period']);
        $stored = $this->storedRows($report);
        $this->validate(
            $report,
            $this->definition($report),
            array_map(fn (CdaReportValue $row) => $row->value, $stored),
            array_map(fn (CdaReportValue $row) => is_array($row->source_value) ? $row->source_value : null, $stored),
            $this->context($report),
        );
    }

    /** Loose equality for stored values: same JSON. */
    public static function same(mixed $a, mixed $b): bool
    {
        return CanonicalJson::encode($a) === CanonicalJson::encode($b);
    }

    /**
     * Every field's value: sources read, manual values kept, calculations run.
     *
     * @param  array<string, CdaReportValue>  $stored
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>|null>}
     */
    private function compute(ReportDefinition $definition, SourceContext $context, array $stored): array
    {
        $values = [];
        $info = [];

        foreach ($definition->fields() as $key => $field) {
            if (! isset($field['source'])) {
                $values[$key] = $stored[$key]->value ?? null;
                $info[$key] = null;

                continue;
            }

            $sourceKey = (string) $field['source']['key'];

            try {
                $result = $this->sources->resolve($sourceKey, (array) ($field['source']['params'] ?? []), $context);
                $info[$key] = $result->toArray();
                $values[$key] = FieldValue::fromSource($field, $result->value);
            } catch (SourceUnavailable|InvalidArgumentException $e) {
                $info[$key] = ['error' => $e->getMessage(), 'label' => SourceCatalog::has($sourceKey) ? SourceCatalog::get($sourceKey)['label'] : $sourceKey];
                $values[$key] = null;
            }

            if (($stored[$key] ?? null)?->is_override) {
                $values[$key] = $stored[$key]->value;
            }
        }

        foreach ($definition->calculated() as $key => $field) {
            [$values[$key], $error] = $this->calculate($field, $definition, $values);
            $info[$key] = $error === null ? null : ['error' => $error];
        }

        return [$values, $info];
    }

    /** @return array{0: mixed, 1: string|null} the value, and what went wrong */
    private function calculate(array $field, ReportDefinition $definition, array $values): array
    {
        try {
            $result = $this->calc->evaluate($field['calc'], function (string $reference) use ($definition, $values): mixed {
                $key = $definition->resolve($reference);

                return $key === null ? null : ($values[$key] ?? null);
            });

            return [FieldValue::fromRational($field, $result), null];
        } catch (OverflowException) {
            return [null, ReportValidator::label($field).': the figures are too large to calculate exactly.'];
        } catch (InvalidArgumentException $e) {
            return [null, ReportValidator::label($field).' can\'t be calculated: '.$e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>|null>  $info
     */
    private function validate(CdaReport $report, ReportDefinition $definition, array $values, array $info, SourceContext $context): void
    {
        $result = $this->validator->evaluate($report, $definition, $values, array_filter($info), $context);
        $readiness = Readiness::of($result['checks']);
        $issues = $result['issues'];

        $report->forceFill([
            'issues' => $issues,
            'critical_count' => count(array_filter($issues, fn (array $issue) => $issue['severity'] === 'critical')),
            'warning_count' => count(array_filter($issues, fn (array $issue) => $issue['severity'] === 'warning')),
            'readiness' => $readiness['readiness'],
            'readiness_breakdown' => $readiness['breakdown'],
            'validated_at' => now(),
        ])->save();
    }

    /** @return array<string, CdaReportValue> */
    private function storedRows(CdaReport $report): array
    {
        return CdaReportValue::query()->where('report_id', $report->id)->get()->keyBy('field_key')->all();
    }
}
