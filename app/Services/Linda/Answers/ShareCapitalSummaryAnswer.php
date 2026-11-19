<?php

namespace App\Services\Linda\Answers;

use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;
use App\Services\ShareCapitalMemberService;

/**
 * "How much share capital do we have?": the share capital member list's
 * `meta.totals` (GET /share-capital/members, ShareCapitalMemberService::
 * bookTotals()) as of today: the balance, what was paid in (credits) and
 * taken out (debits), and how many members hold share capital, in the
 * caller's branches or the one named. It needs `share_capital:view`, as the
 * list does. Never a member's name or code.
 */
final class ShareCapitalSummaryAnswer extends Answer
{
    public function __construct(private readonly ShareCapitalMemberService $members) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::ShareCapitalSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('share_capital:view');
    }

    protected function subject(): string
    {
        return 'share capital figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $totals = $this->members->bookTotals($scope->branchId === null ? $scope->branchIds : [$scope->branchId]);
        $count = (int) $totals['member_count'];

        $answer = $count === 0
            ? W::asOfToday().', no member holds share capital '.W::forScope($scope).'.'
            : W::asOfToday().', share capital '.W::forScope($scope).' is '.W::money($totals['balance']).', held by '
                .W::count($count, 'member').' ('.W::money($totals['total_credits']).' paid in, '
                .W::money($totals['total_debits']).' taken out).';

        if ($period !== null) {
            $answer .= ' '.W::todayOnly();
        }

        return $this->reply($answer, [
            LindaReply::stats([
                LindaReply::money('Share Capital Balance', $totals['balance']),
                LindaReply::number('Members With Share Capital', $count),
                LindaReply::money('Total Credits', $totals['total_credits']),
                LindaReply::money('Total Debits', $totals['total_debits']),
            ], W::asOfToday()),
        ], LindaLinks::for($user, 'share_capital'));
    }
}
