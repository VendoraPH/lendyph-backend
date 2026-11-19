<?php

namespace App\Services\Amla\Reports;

use App\Models\User;
use App\Services\Amla\Events\PesoAmount;
use App\Services\BranchAccess;
use Illuminate\Validation\ValidationException;

/**
 * One of the ten AMLA reports (task §16; AMLA-B7). It reads AMLA's own
 * tables (and, for names, the member and walk-in records), never writes, and
 * returns raw values per column; ReportTable formats them the same way for
 * the screen and every export (₱ with two decimals, negative money in
 * parentheses). A report states its own keys; the confidential ones also need
 * head-office access.
 */
abstract class ComplianceReport
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** @return list<string> any one of these opens the report */
    abstract public function permissions(): array;

    /**
     * Each column: its key, label and format (text, number, money, date).
     *
     * @return list<array{key: string, label: string, format: string}>
     */
    abstract public function columns(): array;

    /** @return list<array<string, mixed>> raw values keyed by column key */
    abstract public function rows(User $viewer, ReportFilters $filters): array;

    /** Whether the report also needs `branches:view_all`. */
    public function headOffice(): bool
    {
        return false;
    }

    /** Whether the report reads a date range (else it is as of today). */
    public function usesPeriod(): bool
    {
        return true;
    }

    /**
     * Server totals for the rows, keyed by column key, or null for none.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public function totals(array $rows): ?array
    {
        return null;
    }

    /**
     * Refuse, in words, a request too big for one report (422) before it is
     * built. Nothing by default.
     *
     * @throws ValidationException
     */
    public function assertFits(ReportFilters $filters): void {}

    public function canSee(User $user): bool
    {
        return $user->canAny($this->permissions()) && (! $this->headOffice() || $user->can(BranchAccess::VIEW_ALL));
    }

    /**
     * Sum the listed number and money columns of the rows, in whole centavos
     * for money, for totals().
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $numbers
     * @param  list<string>  $money
     * @return array<string, mixed>
     */
    protected static function summed(array $rows, array $numbers, array $money, string $labelKey, string $label = 'Total'): array
    {
        $totals = [$labelKey => $label];

        foreach ($numbers as $key) {
            $totals[$key] = array_sum(array_map(fn (array $row) => (int) ($row[$key] ?? 0), $rows));
        }

        foreach ($money as $key) {
            $centavos = array_sum(array_map(fn (array $row) => PesoAmount::centavos((string) ($row[$key] ?? '0')), $rows));
            $totals[$key] = PesoAmount::fromCentavos($centavos);
        }

        return $totals;
    }
}
