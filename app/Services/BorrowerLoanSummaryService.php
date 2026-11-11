<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;

/**
 * The Member Overview's loan totals (GET /borrowers/{borrower}/loan-summary),
 * so the browser never adds a member's loans up itself.
 *
 * - `total_borrowed`: the principal of every loan that went out the door
 *   (Loan::EVER_RELEASED_STATUSES) except `restructured` ones. A restructure's
 *   successor loan already carries the rolled-over balance as its principal,
 *   so counting the source too would count that money twice.
 * - `total_outstanding`: everything the loans still owed
 *   (Loan::COLLECTIBLE_STATUSES) owe: the periods' principal, interest and
 *   penalty plus the insurance premium owed, the parts of each loan's
 *   GET /loans/{id}/summary `outstanding_balance` (LoanListTotals).
 * - `outstanding_principal`: the principal still owed on those loans, without
 *   the premium.
 * - `overdue_amount`: what those loans owe on periods that are late (past the
 *   due date and the loan's grace period), not their whole balance.
 * - `total_paid`: every posted (not voided) payment on the member's loans,
 *   insurance premium included; `insurance_paid` is the premium inside it.
 * - `counts`: the member's loans per status, every status present, and
 *   `current_loans`, the loans in Loan::ACTIVE_STATUSES.
 *
 * Every figure covers only the loans the viewer can see (Loan::visibleTo), the
 * same as the loan pages: a member's loans can sit in more than one branch, and
 * a branch user gets nothing from another branch's loan.
 *
 * Applications that never released (draft, for_review, approved, rejected,
 * void) count in `counts` only. Money is added in whole centavos.
 */
class BorrowerLoanSummaryService
{
    public function __construct(private LoanListTotals $totals) {}

    /**
     * Every value `loans.status` can hold.
     */
    public const STATUSES = [
        'draft', 'for_review', 'approved', 'rejected',
        'released', 'ongoing', 'completed', 'defaulted', 'restructured', 'void',
    ];

    /**
     * @return array{total_borrowed: float|int, total_outstanding: float, outstanding_principal: float, overdue_amount: float, total_paid: float|int, insurance_paid: float|int, current_loans: int, counts: array<string, int>}
     */
    public function summarize(Borrower $borrower, User $viewer): array
    {
        return $this->build($borrower, $viewer);
    }

    /**
     * The same figures for the member themself (the Member Portal): every one
     * of the member's own loans, whichever branch holds it, since a member
     * always sees their own loans. Equal to summarize() for a viewer who sees
     * every branch.
     *
     * @return array{total_borrowed: float|int, total_outstanding: float, outstanding_principal: float, overdue_amount: float, total_paid: float|int, insurance_paid: float|int, current_loans: int, counts: array<string, int>}
     */
    public function forMember(Borrower $borrower): array
    {
        return $this->build($borrower, null);
    }

    /**
     * Without a viewer, every loan of the member's counts (forMember()).
     *
     * @return array{total_borrowed: float|int, total_outstanding: float, outstanding_principal: float, overdue_amount: float, total_paid: float|int, insurance_paid: float|int, current_loans: int, counts: array<string, int>}
     */
    private function build(Borrower $borrower, ?User $viewer): array
    {
        $visible = fn ($query) => $viewer === null ? $query : $query->visibleTo($viewer);
        $counts = array_fill_keys(self::STATUSES, 0);
        $borrowed = 0;

        $rows = $visible($borrower->loans())
            ->reorder()
            ->selectRaw('status, COUNT(*) as loan_count, COALESCE(SUM(principal_amount), 0) as principal')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->loan_count;

            if (in_array($row->status, Loan::EVER_RELEASED_STATUSES, true) && $row->status !== 'restructured') {
                $borrowed += LoanService::toCentavos((float) $row->principal);
            }
        }

        $owed = $this->totals->for(
            $visible(Loan::query())
                ->where('loans.borrower_id', $borrower->id)
                ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES),
        );

        $paid = Repayment::query()
            ->whereIn('loan_id', $visible($borrower->loans())->reorder()->select('loans.id'))
            ->where('status', 'posted')
            ->selectRaw('COALESCE(SUM(amount_paid), 0) as amount_paid, COALESCE(SUM(insurance_applied), 0) as insurance_applied')
            ->first();

        return [
            'total_borrowed' => $borrowed / 100,
            'total_outstanding' => $owed['total_outstanding'],
            'outstanding_principal' => $owed['outstanding_principal'],
            'overdue_amount' => $owed['overdue_amount'],
            'total_paid' => LoanService::toCentavos((float) $paid->amount_paid) / 100,
            'insurance_paid' => LoanService::toCentavos((float) $paid->insurance_applied) / 100,
            'current_loans' => array_sum(array_intersect_key($counts, array_flip(Loan::ACTIVE_STATUSES))),
            'counts' => $counts,
        ];
    }
}
