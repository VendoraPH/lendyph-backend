<?php

namespace App\Services\Linda\Answers;

use App\Models\User;
use App\Services\Accounting\Money;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;
use App\Services\ReportService;

/**
 * "Give me a summary of our lending operation": for the period, Total
 * Received (Daily Collection) and Net Disbursed (Disbursement Summary); as of
 * today, active loans and Outstanding (`total_outstanding`, Portfolio
 * Summary), and the past due total and members (ReportService::overdueMembers()).
 *
 * It needs `reports:view`. The past due lines also need `loans:view`, as the
 * Past Due Loans page does, and are left out without it.
 */
final class GeneralLendingSummaryAnswer extends Answer
{
    public function __construct(private readonly ReportService $reports) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::GeneralLendingSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('reports:view');
    }

    protected function subject(): string
    {
        return 'lending summary';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');

        $dated = [
            ...$scope->filters(),
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ];
        $received = $this->reports->dailyCollection($dated)['total_received'];
        $disbursed = $this->reports->disbursementReport($dated)['total_disbursed'];
        $summary = $this->reports->loanBalanceSummary($scope->filters());
        $active = (int) $summary['active_loans'];
        $outstanding = $summary['total_outstanding'];
        $overdue = $user->can('loans:view') ? $this->reports->overdueMembers($scope->filters()) : null;

        $today = [
            LindaReply::number('Active Loans', $active),
            LindaReply::money('Outstanding', $outstanding),
        ];
        if ($overdue !== null) {
            $today[] = LindaReply::money('Total Past Due', $overdue['past_due_amount']);
            $today[] = LindaReply::number('Members With Past Due', $overdue['member_count']);
        }

        $blocks = [
            LindaReply::stats([
                LindaReply::money('Total Received', $received),
                LindaReply::money('Net Disbursed', $disbursed),
            ], self::sentence(W::during($period))),
            LindaReply::stats($today, W::asOfToday()),
        ];

        $quiet = $active === 0 && array_filter(
            [$received, $disbursed, $outstanding],
            fn (int|float $pesos): bool => Money::toCentavos($pesos) !== 0,
        ) === [];

        if ($quiet) {
            $answer = 'There was no lending activity '.W::during($period).'. This covers '.$scope->label.'.';
        } else {
            $answer = 'Here is the lending summary '.W::forScope($scope).'. '
                .self::sentence(W::during($period)).', we received '.W::money($received).' in loan payments and disbursed '
                .W::money($disbursed).' net. '
                .W::asOfToday().', there '.($active === 1 ? 'is ' : 'are ').W::count($active, 'active loan')
                .' with '.W::money($outstanding).' Outstanding'
                .($overdue === null || $overdue['member_count'] === 0
                    ? '.'
                    : ', and '.W::count($overdue['member_count'], 'member').' '.($overdue['member_count'] === 1 ? 'has ' : 'have ')
                        .W::money($overdue['past_due_amount']).' past due.');
        }

        return $this->reply($answer, $blocks, LindaLinks::forPeriod($user, null, $scope->branchId, 'portfolio_summary'));
    }
}
