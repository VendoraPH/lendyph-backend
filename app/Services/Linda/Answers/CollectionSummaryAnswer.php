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
 * "How much have we collected?": the Daily Collection Report's figures for
 * the period (ReportService::dailyCollection()): Total Received, Collected
 * (Principal + Interest), Penalty Collected and the payments Total Received
 * is made of.
 */
final class CollectionSummaryAnswer extends Answer
{
    public function __construct(private readonly ReportService $reports) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::CollectionSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('reports:view');
    }

    protected function subject(): string
    {
        return 'collection figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');

        $report = $this->reports->dailyCollection([
            ...$scope->filters(),
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ]);

        $count = (int) $report['payment_count'];

        $answer = match (true) {
            $count === 0 && (float) $report['total_collected'] > 0 => 'No payments were received '.W::during($period).'; '
                .W::money($report['total_collected']).' of principal and interest due in that period was paid earlier. This covers '.$scope->label.'.',
            $count === 0 => 'No payments were received '.W::during($period).'. This covers '.$scope->label.'.',
            default => self::sentence(W::during($period)).', we received '.W::money($report['total_received'])
                .' from '.W::count($count, 'payment').' '.W::forScope($scope).'. '
                .'Collected (Principal + Interest) on the periods due in those dates is '.W::money($report['total_collected'])
                .', and Penalty Collected is '.W::money($report['penalty_collected']).'.',
        };

        return $this->reply($answer, [
            LindaReply::stats([
                LindaReply::money('Total Received', $report['total_received']),
                LindaReply::money('Collected (Principal + Interest)', $report['total_collected']),
                LindaReply::money('Penalty Collected', $report['penalty_collected']),
                LindaReply::number('Payments', $count),
            ], self::sentence(W::during($period))),
        ], LindaLinks::for($user, 'daily_collection'));
    }
}
