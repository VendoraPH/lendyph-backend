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
 * "How much income did we earn this month?": the Income report's cards for
 * the period (ReportService::incomeReport()): interest, penalties,
 * processing, service and other fees, and Total Income, as the report
 * counts them (cash basis). This month by default; needs `reports:view`.
 */
final class IncomeSummaryAnswer extends Answer
{
    public function __construct(private readonly ReportService $reports) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::IncomeSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('reports:view');
    }

    protected function subject(): string
    {
        return 'income figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');

        $report = $this->reports->incomeReport([
            ...$scope->filters(),
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ]);

        $answer = (float) $report['total'] <= 0
            ? self::sentence(W::during($period)).', no income was recorded '.W::forScope($scope).'.'
            : self::sentence(W::during($period)).', income '.W::forScope($scope).' was '.W::money($report['total'])
                .': '.W::money($report['interest_income']).' interest, '.W::money($report['penalty_income']).' penalties, '
                .W::money($report['processing_fees']).' processing fees, '.W::money($report['service_fee']).' service fees and '
                .W::money($report['other_fees']).' other fees.';

        return $this->reply($answer, [
            LindaReply::stats([
                LindaReply::money('Total Income', $report['total']),
                LindaReply::money('Interest', $report['interest_income']),
                LindaReply::money('Penalties', $report['penalty_income']),
                LindaReply::money('Processing Fees', $report['processing_fees']),
                LindaReply::money('Service Fees', $report['service_fee']),
                LindaReply::money('Other Fees', $report['other_fees']),
            ], self::sentence(W::during($period))),
        ], LindaLinks::forPeriod($user, $period, $scope->branchId, 'income_report'));
    }
}
