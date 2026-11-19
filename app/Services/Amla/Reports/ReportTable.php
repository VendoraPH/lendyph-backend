<?php

namespace App\Services\Amla\Reports;

use App\Services\Cda\Exports\ValueFormatter;

/**
 * One built AMLA report: its columns, raw rows and totals, formatted the same
 * way for the screen and every export through the CDA ValueFormatter (₱ with
 * two decimals, negative money in parentheses, "—" for no value).
 */
final class ReportTable
{
    /**
     * @param  list<array{key: string, label: string, format: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     */
    public function __construct(
        public readonly ComplianceReport $report,
        public readonly ReportFilters $filters,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals,
    ) {}

    public function isDraft(): bool
    {
        return $this->report->usesPeriod() && $this->filters->isOpen();
    }

    public function periodLabel(): string
    {
        return $this->filters->label($this->report->usesPeriod());
    }

    /** @return list<array<string, string>> */
    public function displayRows(): array
    {
        return array_map(fn (array $row) => $this->display($row), $this->rows);
    }

    /** @return array<string, string>|null */
    public function displayTotals(): ?array
    {
        return $this->totals === null ? null : $this->display($this->totals);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    public function display(array $row): array
    {
        $shown = [];

        foreach ($this->columns as $column) {
            $value = $row[$column['key']] ?? null;
            $shown[$column['key']] = $column['format'] === 'text' ? (filled($value) ? (string) $value : ValueFormatter::EMPTY) : ValueFormatter::display($value, $column['format']);
        }

        return $shown;
    }
}
