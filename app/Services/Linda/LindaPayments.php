<?php

namespace App\Services\Linda;

use App\Models\Loan;
use App\Models\Repayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Payment History's figures for Linda (GET /api/repayments, the same query
 * semantics as RepaymentController::listAll()): posted payments on loans in
 * the given branches, by payment date, with `meta.total` (the count with
 * `status=posted`) and `meta.totals.collected` (SUM of `amount_paid`, summed
 * in SQL and rounded once). A named branch narrows it by the loan's branch,
 * as the list's own branch scope does; a member, by the loan's member, as
 * its `borrower_id` filter does. The latest payments come in the list's own
 * order: payment date, then id, newest first.
 */
final class LindaPayments
{
    /**
     * @param  list<int>|null  $branchIds  BranchAccess::for(), null for every branch
     * @return array{count: int, total: float, latest: Collection<int, Repayment>}
     */
    public function summary(?array $branchIds, ?int $branchId, ?LindaPeriod $period, ?int $borrowerId, int $limit): array
    {
        $posted = fn (): Builder => Repayment::query()
            ->where('repayments.status', 'posted')
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn(
                'repayments.loan_id',
                Loan::query()->whereIn('loans.branch_id', $branchIds)->select('loans.id'),
            ))
            ->when($branchId !== null, fn (Builder $q) => $q->whereIn(
                'repayments.loan_id',
                Loan::query()->where('loans.branch_id', $branchId)->select('loans.id'),
            ))
            ->when($borrowerId !== null, fn (Builder $q) => $q->whereHas('loan', fn ($lq) => $lq->where('borrower_id', $borrowerId)))
            ->when($period !== null, fn (Builder $q) => $q
                ->whereDate('payment_date', '>=', $period->from->toDateString())
                ->whereDate('payment_date', '<=', $period->to->toDateString()));

        $totals = $posted()->selectRaw('COUNT(*) as payment_count, COALESCE(SUM(amount_paid), 0) as total')->toBase()->first();

        return [
            'count' => (int) ($totals->payment_count ?? 0),
            'total' => round((float) ($totals->total ?? 0), 2),
            'latest' => $posted()
                ->with('loan:id,borrower_id,loan_account_number', 'loan.borrower:id,first_name,middle_name,last_name,suffix,borrower_code,branch_id')
                ->latest('payment_date')
                ->orderByDesc('id')
                ->limit($limit)
                ->get(),
        ];
    }
}
