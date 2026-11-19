<?php

namespace App\Services\Amla\Reports;

use Carbon\CarbonImmutable;

/**
 * A report's filters: the date range (this month to today by default, in
 * Manila) and an optional branch. A report whose range has not ended yet is
 * marked DRAFT on its exports: its figures can still change.
 */
final class ReportFilters
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $branchId,
    ) {}

    /** @param  array{from?: string|null, to?: string|null, branch_id?: int|string|null}  $input */
    public static function from(array $input): self
    {
        $today = CarbonImmutable::now('Asia/Manila');

        return new self(
            (string) ($input['from'] ?? $today->startOfMonth()->toDateString()),
            (string) ($input['to'] ?? $today->toDateString()),
            isset($input['branch_id']) ? (int) $input['branch_id'] : null,
        );
    }

    public function isOpen(): bool
    {
        return $this->to >= CarbonImmutable::now('Asia/Manila')->toDateString();
    }

    public function label(bool $usesPeriod): string
    {
        $today = CarbonImmutable::now('Asia/Manila');

        return $usesPeriod
            ? CarbonImmutable::parse($this->from)->format('M j, Y').' to '.CarbonImmutable::parse($this->to)->format('M j, Y')
            : 'As of '.$today->format('M j, Y');
    }

    /** The range as Manila timestamps, for created_at columns. @return array{0: string, 1: string} */
    public function moments(): array
    {
        return [$this->from.' 00:00:00', $this->to.' 23:59:59'];
    }

    /** @return array{from: string, to: string, branch_id: int|null} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'branch_id' => $this->branchId];
    }
}
