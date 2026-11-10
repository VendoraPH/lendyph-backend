<?php

namespace App\Services\Cda\Sources;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use Closure;
use InvalidArgumentException;

/**
 * What a source is resolved for (CDA design §2): the reporting period, and
 * the report when there is one. Date params resolve here from tokens, and
 * results are memoized per context, so one refresh asks each source once.
 */
final class SourceContext
{
    /** @var list<string> */
    public const DATE_TOKENS = [
        'period_start', 'period_end', 'h1_start', 'h1_end', 'h2_start', 'h2_end',
        'half_start', 'half_end', 'prior_period_end',
    ];

    /** @var array<string, SourceResult> */
    private array $memo = [];

    public function __construct(
        public readonly CdaReportingPeriod $period,
        public readonly ?CdaReport $report = null,
    ) {}

    public function year(): int
    {
        return (int) $this->period->year;
    }

    /** The report's part (annual, h1, h2); annual without a report. */
    public function part(): string
    {
        return $this->report?->part ?? 'annual';
    }

    /**
     * A date param as YYYY-MM-DD. `half_start`/`half_end` follow the report's
     * part (the whole year for an annual report).
     */
    public function date(string $tokenOrDate): string
    {
        $year = $this->year();

        return match ($tokenOrDate) {
            'period_start' => $this->period->period_start->toDateString(),
            'period_end' => $this->period->period_end->toDateString(),
            'h1_start' => "{$year}-01-01",
            'h1_end' => "{$year}-06-30",
            'h2_start' => "{$year}-07-01",
            'h2_end' => "{$year}-12-31",
            'half_start' => $this->part() === 'h2' ? "{$year}-07-01" : "{$year}-01-01",
            'half_end' => $this->part() === 'h1' ? "{$year}-06-30" : "{$year}-12-31",
            'prior_period_end' => ($year - 1).'-12-31',
            default => preg_match('/^\d{4}-\d{2}-\d{2}$/', $tokenOrDate) === 1
                ? $tokenOrDate
                : throw new InvalidArgumentException("Not a date or date token: {$tokenOrDate}."),
        };
    }

    /** @param  Closure(): SourceResult  $resolve */
    public function remember(string $key, Closure $resolve): SourceResult
    {
        return $this->memo[$key] ??= $resolve();
    }
}
