<?php

namespace App\Services\Linda\Answers;

use App\Models\PortfolioDailySnapshot;
use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;
use App\Services\ReportService;

/**
 * "What is our PAR 30?", "How big is our loan portfolio?": the Portfolio
 * Summary report's figures as of today (ReportService::loanBalanceSummary(),
 * no dates): Principal Balance (`outstanding_principal`), Outstanding
 * (`total_outstanding`), PAR 30 (`par_ratio`, `at_risk_amount`) and active
 * loans, with each branch's row. It needs `reports:view`, as the report does.
 *
 * PAR 1 and PAR 90 are kept only in the daily portfolio snapshot
 * (`portfolio:snapshot`), which covers the whole cooperative, so they are
 * shown, as the snapshot's peso amounts and its date, only to a caller who
 * sees every branch and named none: the dashboard's rule for past snapshot
 * points.
 */
final class PortfolioSummaryAnswer extends Answer
{
    public function __construct(private readonly ReportService $reports) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::PortfolioSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('reports:view');
    }

    protected function subject(): string
    {
        return 'portfolio figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $summary = $this->reports->loanBalanceSummary($scope->filters());
        $active = (int) $summary['active_loans'];

        $answer = W::asOfToday().', the loan portfolio '.W::forScope($scope).' has '.W::money($summary['outstanding_principal'])
            .' in principal balance and '.W::money($summary['total_outstanding']).' outstanding in all, on '
            .W::count($active, 'active loan').'. PAR 30 is '.W::rate($summary['par_ratio']).' ('.W::money($summary['at_risk_amount'])
            .' of principal on loans more than 30 days past due).';

        $items = [
            LindaReply::money('Principal Balance', $summary['outstanding_principal']),
            LindaReply::money('Outstanding', $summary['total_outstanding']),
            LindaReply::percent('PAR 30', $summary['par_ratio']),
            LindaReply::money('Principal at Risk (PAR 30)', $summary['at_risk_amount']),
            LindaReply::number('Active Loans', $active),
        ];

        $snapshot = $scope->branchIds === null && $scope->branchId === null
            ? PortfolioDailySnapshot::query()->latest('snapshot_date')->first()
            : null;

        if ($snapshot !== null) {
            $day = $snapshot->snapshot_date->toDateString();
            $answer .= ' At the last daily snapshot ('.W::date($day).'), PAR 1 was '.W::money((string) $snapshot->par_1_amount)
                .' and PAR 90 was '.W::money((string) $snapshot->par_90_amount).' of principal.';
            $items[] = LindaReply::money('PAR 1 (₱, last snapshot)', (string) $snapshot->par_1_amount);
            $items[] = LindaReply::money('PAR 90 (₱, last snapshot)', (string) $snapshot->par_90_amount);
            $items[] = LindaReply::date('Snapshot Date', $day);
        }

        if ($period !== null) {
            $answer .= ' '.W::todayOnly();
        }

        $blocks = [LindaReply::stats($items, W::asOfToday())];

        $branches = collect($summary['by_branch']);
        if ($branches->count() > 1) {
            $blocks[] = LindaReply::list('Portfolio by branch', $branches
                ->sortBy([['outstanding_principal', 'desc'], ['branch_name', 'asc']])
                ->take(max(1, (int) config('linda.list_max', 10)))
                ->map(fn (array $row) => [
                    'title' => (string) $row['branch_name'],
                    'fields' => [
                        LindaReply::money('Principal Balance', $row['outstanding_principal']),
                        LindaReply::money('Outstanding', $row['total_outstanding']),
                        LindaReply::number('Active Loans', (int) $row['active_loan_count']),
                    ],
                ])
                ->values()
                ->all());
        }

        return $this->reply($answer, $blocks, LindaLinks::forPeriod($user, null, $scope->branchId, 'portfolio_summary'));
    }
}
