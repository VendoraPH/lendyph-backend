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
 * "What's our collection efficiency?": the Collection Efficiency report's
 * figures for the period (ReportService::collectionEfficiency()): the
 * collection rate, Total Due, Collected (Principal + Interest) and
 * Uncollected, counted by period as the report counts them. This month by
 * default.
 */
final class CollectionEfficiencyAnswer extends Answer
{
    public function __construct(private readonly ReportService $reports) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::CollectionEfficiency;
    }

    public function allows(User $user): bool
    {
        return $user->can('reports:view');
    }

    protected function subject(): string
    {
        return 'collection efficiency figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');

        $report = $this->reports->collectionEfficiency([
            ...$scope->filters(),
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ]);

        $answer = (float) $report['total_due'] <= 0
            ? 'Nothing fell due '.W::during($period).' '.W::forScope($scope).', so there is no collection rate for it.'
            : self::sentence(W::during($period)).', the collection rate '.W::forScope($scope).' is '.W::percent($report['collection_rate'])
                .': '.W::money($report['total_collected']).' of principal and interest collected on the periods due, out of '
                .W::money($report['total_due']).' due, leaving '.W::money($report['uncollected']).' uncollected.';

        return $this->reply($answer, [
            LindaReply::stats([
                LindaReply::percent('Collection Rate', $report['collection_rate']),
                LindaReply::money('Total Due', $report['total_due']),
                LindaReply::money('Collected (Principal + Interest)', $report['total_collected']),
                LindaReply::money('Uncollected', $report['uncollected']),
            ], self::sentence(W::during($period))),
        ], LindaLinks::forPeriod($user, $period, $scope->branchId, 'collection_efficiency'));
    }
}
