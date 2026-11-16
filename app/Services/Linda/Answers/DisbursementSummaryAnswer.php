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
 * "How much have we disbursed?": the Disbursement Summary's net disbursed
 * and loans released (ReportService::disbursementReport()), with the
 * principal released from the Releases List's totals
 * (ReportService::listOfReleasesTotals()), for the period.
 */
final class DisbursementSummaryAnswer extends Answer
{
    public function __construct(private readonly ReportService $reports) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::DisbursementSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('reports:view');
    }

    protected function subject(): string
    {
        return 'disbursement figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');

        $filters = [
            ...$scope->filters(),
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ];
        $report = $this->reports->disbursementReport($filters);
        $principal = $this->reports->listOfReleasesTotals($filters)['total_principal'];
        $released = (int) $report['loans_released'];

        $answer = $released === 0
            ? 'No loans were released '.W::during($period).'. This covers '.$scope->label.'.'
            : self::sentence(W::during($period)).', '.W::count($released, 'loan').' '.($released === 1 ? 'was' : 'were')
                .' released '.W::forScope($scope).': '.W::money($principal).' in principal, or '
                .W::money($report['total_disbursed']).' net disbursed after deductions.';

        return $this->reply($answer, [
            LindaReply::stats([
                LindaReply::money('Net Disbursed', $report['total_disbursed']),
                LindaReply::number('Loans Released', $released),
                LindaReply::money('Principal Released', $principal),
            ], self::sentence(W::during($period))),
        ], LindaLinks::forPeriod($user, $period, $scope->branchId, 'disbursement_report'));
    }
}
