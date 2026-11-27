<?php

namespace App\Services\Cda\Registers;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\Sources\SourceCatalog;
use App\Services\Cda\TemplateDefinitionValidator;

/**
 * A register write marks the reports that read it as stale (CDA design §4),
 * so the screen offers Refresh. Stored source values only change on a
 * refresh, never on a register write.
 *
 * A report reads a register when its template names one of the family's
 * source keys, or a named check on it (`officers.*`). Only draft reports that
 * apply are marked, and only those whose period part (the year, or the half)
 * overlaps the dates the write touched. Reports past draft keep their frozen
 * figures. CdaReport isn't audited, so this writes no audit row.
 */
final class RegisterStaleness
{
    public const OFFICERS = 'officers';

    public const SOCIAL = 'social';

    public const MEDIATION = 'mediation';

    private const OPEN_END = '9999-12-31';

    public function __construct(private readonly TemplateDefinitionValidator $validator) {}

    /** Marks the drafts reading the family whose part overlaps [from, to]. */
    public function touch(string $family, string $from, ?string $to = null): void
    {
        $to ??= self::OPEN_END;

        $ids = CdaReport::query()
            ->with(['template', 'period'])
            ->where('status', CdaReport::STATUS_DRAFT)
            ->where('applicable', true)
            ->whereHas('period', fn ($q) => $q->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from))
            ->get()
            ->filter(fn (CdaReport $report) => $this->overlaps($report, $from, $to) && $this->reads($report, $family))
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            CdaReport::query()->whereKey($ids)->update(['stale_at' => now()]);
        }
    }

    /** Marks the drafts of one period that read the family. */
    public function touchPeriod(string $family, int $periodId): void
    {
        $period = CdaReportingPeriod::query()->find($periodId);

        if ($period !== null) {
            $this->touch($family, $period->period_start->toDateString(), $period->period_end->toDateString());
        }
    }

    private function overlaps(CdaReport $report, string $from, string $to): bool
    {
        $year = (int) $report->period->year;
        [$start, $end] = match ($report->part) {
            'h1' => ["{$year}-01-01", "{$year}-06-30"],
            'h2' => ["{$year}-07-01", "{$year}-12-31"],
            default => [$report->period->period_start->toDateString(), $report->period->period_end->toDateString()],
        };

        return $start <= $to && $end >= $from;
    }

    private function reads(CdaReport $report, string $family): bool
    {
        $definition = (array) ($report->template?->definition ?? []);

        foreach ($this->validator->sourceKeysIn($definition) as $key) {
            if (SourceCatalog::has($key) && SourceCatalog::family($key) === $family) {
                return true;
            }
        }

        foreach ((array) ($definition['rules'] ?? []) as $rule) {
            if (($rule['type'] ?? null) === 'check' && is_string($rule['check'] ?? null) && str_starts_with($rule['check'], $family.'.')) {
                return true;
            }
        }

        return false;
    }
}
