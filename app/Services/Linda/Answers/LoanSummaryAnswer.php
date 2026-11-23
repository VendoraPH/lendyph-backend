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
 * `active_loan_count` (ReportService::loanBalanceSummary(), no dates), and,
 * from the same call, Outstanding (`total_outstanding`): the principal,
 * interest, penalty and insurance premium still owed on collectible loans
 * (released, ongoing and defaulted), as the Portfolio Summary labels it.
 *
 * A question about "outstanding" (or a balance or what is still owed) leads
 * with that amount; any other leads with the count.
 *
 * It mirrors the Loans page's Active tab, so it needs `loans:view`; the Loans
 * list totals the same Outstanding (GET /loans `meta.totals`).
 */
final class LoanSummaryAnswer extends Answer
{
    /** Words that ask for the amount still owed rather than the count. */
    private const ASKS_OUTSTANDING = '/(?<![\pL\pN])(outstanding|balance|balanse|utang|natitira|nabilin|nahabilin)(?![\pL\pN])/u';

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
        $owed = W::money($summary['total_outstanding']).' is outstanding';
        $what = 'the principal, interest, penalty and insurance premium still owed on collectible loans, defaulted ones included.';

        if ($question !== null && preg_match(self::ASKS_OUTSTANDING, mb_strtolower($question)) === 1) {
            $answer = W::asOfToday().', '.$owed.' '.W::forScope($scope).': '.$what.' '
                .($active === 0 ? 'There are no active loans.' : 'There '.($active === 1 ? 'is ' : 'are ').W::count($active, 'active loan').'.');
        } else {
            $answer = ($active === 0
                ? 'There are no active loans '.W::forScope($scope).' as of today, '.W::today().'.'
                : W::asOfToday().', there '.($active === 1 ? 'is ' : 'are ').W::count($active, 'active loan').' '.W::forScope($scope).'.')
                .' '.$owed.': '.$what;
        }

        if ($period !== null) {
            $answer .= ' '.W::todayOnly();
        }

        $blocks = [LindaReply::stats([
            LindaReply::number('Active Loans', $active),
            LindaReply::money('Outstanding', $summary['total_outstanding']),
        ], W::asOfToday())];

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
