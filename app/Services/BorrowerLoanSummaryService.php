<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;

/**
 * The Member Overview's loan totals (GET /borrowers/{borrower}/loan-summary),
 * so the browser never adds a member's loans up itself.
 *
 * - `total_borrowed`: the principal of every loan that went out the door
 *   (Loan::EVER_RELEASED_STATUSES) except `restructured` ones. A restructure's
 *   successor loan already carries the rolled-over balance as its principal,
 *   so counting the source too would count that money twice.
 * - `total_outstanding`: Loan::$outstanding_balance over the loans still owed
 *   (Loan::COLLECTIBLE_STATUSES), the figure the loan page shows for each.
 * - `total_paid`: every posted (not voided) payment on the member's loans.
 * - `counts`: the member's loans per status, every status present, and
 *   `current_loans`, the loans in Loan::ACTIVE_STATUSES.
 *
 * Applications that never released (draft, for_review, approved, rejected,
 * void) count in `counts` only. Money is added in whole centavos.
 */
class BorrowerLoanSummaryService
{
    /**
     * Every value `loans.status` can hold.
     */
    public const STATUSES = [
        'draft', 'for_review', 'approved', 'rejected',
        'released', 'ongoing', 'completed', 'defaulted', 'restructured', 'void',
    ];

    /**
     * @return array{total_borrowed: float|int, total_outstanding: float|int, total_paid: float|int, current_loans: int, counts: array<string, int>}
     */
    public function summarize(Borrower $borrower): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        $borrowed = 0;

        $rows = $borrower->loans()
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

        $outstanding = 0;

        $borrower->loans()
            ->whereIn('status', Loan::COLLECTIBLE_STATUSES)
            ->with('amortizationSchedules')
            ->get()
            ->each(function (Loan $loan) use (&$outstanding): void {
                $outstanding += LoanService::toCentavos($loan->outstanding_balance);
            });

        $paid = (float) Repayment::query()
            ->whereIn('loan_id', $borrower->loans()->select('id'))
            ->where('status', 'posted')
            ->sum('amount_paid');

        return [
            'total_borrowed' => $borrowed / 100,
            'total_outstanding' => $outstanding / 100,
            'total_paid' => LoanService::toCentavos($paid) / 100,
            'current_loans' => array_sum(array_intersect_key($counts, array_flip(Loan::ACTIVE_STATUSES))),
            'counts' => $counts,
        ];
    }
}
