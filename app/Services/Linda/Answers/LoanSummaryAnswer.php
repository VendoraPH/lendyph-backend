<?php

namespace App\Services\Linda\Answers;

use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;
use App\Services\ReportService;

/**
 * "How many active loans do we have?": released plus ongoing loans as of
 * today, the Portfolio Summary's `active_loans` with each branch's
 * `active_loan_count` (ReportService::loanBalanceSummary(), no dates).
 *
 * It mirrors the Loans page's Active tab, so it needs `loans:view`.
 */
final class LoanSummaryAnswer extends Answer
{
    public function __construct(private readonly ReportService $reports) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::LoanSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('loans:view');
    }

    protected function subject(): string
    {
        return 'loan figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $summary = $this->reports->loanBalanceSummary($scope->filters());
        $active = (int) $summary['active_loans'];

        $answer = $active === 0
            ? 'There are no active loans '.W::forScope($scope).' as of today, '.W::today().'.'
            : W::asOfToday().', there '.($active === 1 ? 'is ' : 'are ').W::count($active, 'active loan').' '.W::forScope($scope).'.';

        if ($period !== null) {
            $answer .= ' '.W::todayOnly();
        }

        $blocks = [LindaReply::stats([LindaReply::number('Active Loans', $active)], W::asOfToday())];

        $branches = collect($summary['by_branch']);
        if ($branches->count() > 1) {
            $rows = $branches
                ->sortBy([['active_loan_count', 'desc'], ['branch_name', 'asc']])
                ->take(max(1, (int) config('linda.list_max', 10)))
                ->map(fn (array $row) => [
                    'title' => (string) $row['branch_name'],
                    'fields' => [LindaReply::number('Active Loans', (int) $row['active_loan_count'])],
                ])
                ->values()
                ->all();

            $blocks[] = LindaReply::list('Active loans by branch', $rows);
        }

        return $this->reply($answer, $blocks, LindaLinks::for($user, 'active_loans'));
    }
}
