<?php

namespace App\Services\Accounting;

use App\Models\Loan;
use App\Services\LoanListTotals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The Loan Accounting summary: the receivable aging as of a date, the books,
 * and the books against the loan records.
 *
 * - `aging` is GET /accounting/loans/aging for the same date and scope.
 * - `books` is {@see ReceivablePosition} at `as_of`, plus
 *   `adjustments_clearing_needs_review` (1180 isn't zero, either way), the
 *   dashboard's flag.
 * - `records` are the loans {@see LoanAgingBuilder} lists (collectible, with
 *   something unpaid), totalled by {@see LoanListTotals::for()}. Loan records
 *   keep no history, so they are always as of today, and their own `as_of`
 *   says so.
 * - `books_vs_records` sets the books' gross receivable today against the
 *   records' outstanding principal (both today): difference = books − records.
 *   A loan released before the books existed posted no release journal, so it
 *   shows as minus its principal.
 *
 * Integer centavos. Reads only.
 */
final class LoanAccountingSummary
{
    public function __construct(
        private ReceivableAgingBuilder $aging,
        private LoanAgingBuilder $loanAging,
        private LoanListTotals $totals,
    ) {}

    /**
     * @param  int|list<int>|null  $branchId  the branch scope (null: every branch)
     * @param  Builder<Loan>|null  $loans  a starting query, such as Loan::visibleTo($user)
     * @return array{as_of: string, aging: array<string, mixed>, books: array{gross: int, allowance: int, unearned_income: int, adjustments_clearing: int, adjustments_clearing_needs_review: bool, net: int}, records: array{as_of: string, loan_count: int, outstanding_principal: int, total_outstanding: int, past_due_amount: int, past_due_count: int}, books_vs_records: array{as_of: string, books_principal: int, records_principal: int, difference: int, is_reconciled: bool}}
     */
    public function build(string $asOf, int|array|null $branchId = null, ?Builder $loans = null): array
    {
        $today = Carbon::today()->toDateString();
        $books = ReceivablePosition::build($asOf, $branchId);
        $booksToday = $asOf === $today ? $books : ReceivablePosition::build($today, $branchId);
        $records = $this->records($branchId, $loans);
        $difference = $booksToday['gross'] - $records['outstanding_principal'];

        return [
            'as_of' => $asOf,
            'aging' => $this->aging->build($asOf, $branchId),
            'books' => [
                'gross' => $books['gross'],
                'allowance' => $books['allowance'],
                'unearned_income' => $books['unearned_income'],
                'adjustments_clearing' => $books['adjustments_clearing'],
                'adjustments_clearing_needs_review' => $books['adjustments_clearing'] !== 0,
                'net' => $books['net'],
            ],
            'records' => $records,
            'books_vs_records' => [
                'as_of' => $today,
                'books_principal' => $booksToday['gross'],
                'records_principal' => $records['outstanding_principal'],
                'difference' => $difference,
                'is_reconciled' => $difference === 0,
            ],
        ];
    }

    /**
     * @param  int|list<int>|null  $branchId
     * @param  Builder<Loan>|null  $loans
     * @return array{as_of: string, loan_count: int, outstanding_principal: int, total_outstanding: int, past_due_amount: int, past_due_count: int}
     */
    private function records(int|array|null $branchId, ?Builder $loans): array
    {
        $today = Carbon::today();
        $collectible = $this->loanAging->query($today->toDateString(), $branchId, [], $loans);
        $totals = $this->totals->for($collectible, $today);

        return [
            'as_of' => $today->toDateString(),
            'loan_count' => (clone $collectible)->count(),
            'outstanding_principal' => $this->centavos($totals['outstanding_principal']),
            'total_outstanding' => $this->centavos($totals['total_outstanding']),
            'past_due_amount' => $this->centavos($totals['overdue_amount']),
            'past_due_count' => $totals['overdue_count'],
        ];
    }

    private function centavos(float $pesos): int
    {
        $centavos = Money::toCentavos($pesos);

        if ($centavos === null) {
            throw new \RuntimeException("Loan accounting summary: '{$pesos}' is not a convertible peso amount.");
        }

        return $centavos;
    }
}
