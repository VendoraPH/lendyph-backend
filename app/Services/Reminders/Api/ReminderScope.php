<?php

namespace App\Services\Reminders\Api;

use App\Models\Loan;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\LikePattern;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a caller may see of the reminder tables, and the list filters.
 *
 * Queue rows, messages and events belong to a loan, so a row is visible
 * exactly when its loan is (Loan::visibleTo): the loan's branch, not the
 * branch copied onto the row when it was planned. Filters are the frontend's
 * `ReminderListFilters`; `search` matches the member's name and the loan
 * number, with `%`, `_` and `\` matched literally.
 */
final class ReminderScope
{
    public function __construct(private readonly BranchAccess $branches) {}

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visible(Builder $query, User $user, string $loanColumn = 'loan_id'): Builder
    {
        if ($this->branches->for($user) === null) {
            return $query;
        }

        return $query->whereIn($loanColumn, Loan::query()->visibleTo($user)->select('loans.id'));
    }

    /**
     * Every filter but `status`, which each list applies itself (its stats
     * leave it out). `$dateColumn` is the day the filters' dates mean.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<TModel>
     */
    public function filter(Builder $query, array $filters, string $dateColumn, bool $withChannel = true): Builder
    {
        $branchId = $filters['branch_id'] ?? null;
        $productId = $filters['loan_product_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->when(filled($filters['date'] ?? null), fn (Builder $q) => $q->where($dateColumn, $filters['date']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $q) => $q->where($dateColumn, '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $q) => $q->where($dateColumn, '<=', $filters['date_to']))
            ->when($withChannel && filled($filters['channel'] ?? null), fn (Builder $q) => $q->where('channel', $filters['channel']))
            ->when(filled($filters['loan_id'] ?? null), fn (Builder $q) => $q->where('loan_id', (int) $filters['loan_id']))
            ->when(filled($filters['borrower_id'] ?? null), fn (Builder $q) => $q->where('borrower_id', (int) $filters['borrower_id']))
            ->when(filled($branchId) || filled($productId), fn (Builder $q) => $q->whereIn('loan_id', Loan::query()
                ->when(filled($branchId), fn ($loans) => $loans->where('loans.branch_id', (int) $branchId))
                ->when(filled($productId), fn ($loans) => $loans->where('loans.loan_product_id', (int) $productId))
                ->select('loans.id')))
            ->when($search !== '', fn (Builder $q) => $q->whereIn('loan_id', $this->searchedLoanIds($search)));
    }

    /**
     * @return Builder<Loan>
     */
    private function searchedLoanIds(string $term): Builder
    {
        $like = LikePattern::contains($term);
        $nameLike = LikePattern::contains((string) preg_replace('/\s+/', ' ', $term));

        return Loan::query()
            ->join('borrowers', 'borrowers.id', '=', 'loans.borrower_id')
            ->where(fn ($match) => $match
                ->where('loans.loan_account_number', 'like', $like)
                ->orWhere('borrowers.first_name', 'like', $like)
                ->orWhere('borrowers.last_name', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', borrowers.first_name, borrowers.last_name) like ?", [$nameLike])
                ->orWhereRaw("CONCAT_WS(' ', borrowers.first_name, borrowers.middle_name, borrowers.last_name) like ?", [$nameLike]))
            ->select('loans.id');
    }
}
