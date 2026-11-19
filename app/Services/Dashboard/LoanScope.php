<?php

namespace App\Services\Dashboard;

use Closure;

/**
 * The executive dashboard's product, officer and collector filters, applied
 * by one tap beside a report's branch scope (ReportService::inBranches()).
 *
 * - `product_id` narrows loans to `loans.loan_product_id`.
 * - `officer_id` narrows loans to `loans.account_officer_id`.
 * - `collector_id` narrows receipts to `repayments.received_by`, the user who
 *   recorded the payment (no separate collector is stored). It narrows
 *   receipts only: balances, dues, releases, the books, share capital and
 *   member counts have no such field.
 *
 * The branch filter stays with the report's own fail-closed branch scope.
 * Every report follows it, so `filters_applied` never lists it; `branch_id`
 * is in self::FILTERS so MetricCatalog can say each metric follows it.
 *
 * A report states what it honours (MetricCatalog::REPORTS), and self::applied()
 * turns that into the response's `filters_applied` (filters every figure
 * follows) and `filters_applied_to` (filters only some figures follow, by
 * figure), each limited to the filters the caller sent, so a card never
 * quietly ignores one.
 */
final class LoanScope
{
    /** Every filter the executive dashboard sends, in its order. */
    public const FILTERS = ['branch_id', 'product_id', 'officer_id', 'collector_id'];

    /**
     * The filters `filters_applied` reports on. Every report follows the
     * branch filter (and a branch user's own branches without one), so it is
     * never listed: a branch user's report and an admin's report of the same
     * branch stay identical.
     */
    public const LISTED_FILTERS = ['product_id', 'officer_id', 'collector_id'];

    /**
     * @param  list<string>  $sent  the filters the caller sent
     */
    private function __construct(
        public readonly ?int $productId,
        public readonly ?int $officerId,
        public readonly ?int $collectorId,
        private readonly array $sent,
    ) {}

    /**
     * From validated report filters (ReportController::reportFilters()).
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fromFilters(array $filters): self
    {
        $id = fn (string $key): ?int => filled($filters[$key] ?? null) ? (int) $filters[$key] : null;

        return new self(
            $id('product_id'),
            $id('officer_id'),
            $id('collector_id'),
            array_values(array_filter(self::LISTED_FILTERS, fn (string $key): bool => filled($filters[$key] ?? null))),
        );
    }

    /** No product, officer or collector filter. */
    public static function none(): self
    {
        return new self(null, null, null, []);
    }

    /**
     * Narrow a query on `$table` (the loans table or its alias) by product and
     * officer, as a closure for `tap()`. Unchanged without either filter.
     */
    public function loans(string $table = 'loans'): Closure
    {
        return fn ($query) => $query
            ->when($this->productId !== null, fn ($q) => $q->where("{$table}.loan_product_id", $this->productId))
            ->when($this->officerId !== null, fn ($q) => $q->where("{$table}.account_officer_id", $this->officerId));
    }

    /**
     * Narrow a query on `$table` (the repayments table) by the user who
     * recorded the payment, as a closure for `tap()`. Unchanged without the
     * collector filter.
     */
    public function receipts(string $table = 'repayments'): Closure
    {
        return fn ($query) => $query
            ->when($this->collectorId !== null, fn ($q) => $q->where("{$table}.received_by", $this->collectorId));
    }

    /**
     * What a report did with the filters the caller sent.
     *
     * @param  list<string>  $full  the filters every figure of the report follows
     * @param  array<string, list<string>>  $partial  filter => the figures that follow it
     * @return array{filters_applied: list<string>, filters_applied_to: array<string, list<string>>}
     */
    public function applied(array $full, array $partial = []): array
    {
        $appliedTo = [];
        foreach ($partial as $filter => $figures) {
            if (in_array($filter, $this->sent, true) && ! in_array($filter, $full, true)) {
                $appliedTo[$filter] = array_values($figures);
            }
        }

        return [
            'filters_applied' => array_values(array_intersect($this->sent, $full)),
            'filters_applied_to' => $appliedTo,
        ];
    }
}
