<?php

namespace App\Services\Linda\Answers;

use App\Models\Borrower;
use App\Models\User;
use App\Services\ContactVisibility;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;
use App\Services\ReportService;

/**
 * "Which members have overdue payments?": the members with a period past
 * its grace as of today (ReportService::overdueMembers(), the Past Due Loans
 * page's grouping), with the highest past due first, at most
 * `linda.list_max` of them.
 *
 * It mirrors /loans/past-due, so it needs `reports:view` and `loans:view`.
 * A member links to their page only for a user with `borrowers:view` who
 * can see the member's branch.
 */
final class OverdueAccountsAnswer extends Answer
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ContactVisibility $visibility,
    ) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::OverdueAccounts;
    }

    public function allows(User $user): bool
    {
        return self::holdsAll($user, ['reports:view', 'loans:view']);
    }

    protected function subject(): string
    {
        return 'overdue figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $figures = $this->reports->overdueMembers($scope->filters());
        $max = max(1, (int) config('linda.list_max', 10));
        $listed = array_slice($figures['members'], 0, $max);
        $count = $figures['member_count'];

        if ($count === 0) {
            $answer = 'No members have past due payments '.W::forScope($scope).' as of today, '.W::today().'.';
        } else {
            $answer = W::asOfToday().', '.W::count($count, 'member').' '.($count === 1 ? 'has' : 'have')
                .' past due payments '.W::forScope($scope).', '.W::money($figures['past_due_amount']).' in all. '
                .($count > $max
                    ? "The {$max} with the most past due are listed below; open Past Due Loans for the rest."
                    : ($count === 1 ? 'The member is listed below.' : 'They are listed below, highest first.'));
        }

        if ($period !== null) {
            $answer .= ' '.W::todayOnly();
        }

        $blocks = [LindaReply::stats([
            LindaReply::number('Members With Past Due', $count),
            LindaReply::money('Total Past Due', $figures['past_due_amount']),
        ], W::asOfToday())];

        if ($listed !== []) {
            $blocks[] = LindaReply::list('Members with the most past due', $this->items($user, $listed), true);
        }

        return $this->reply($answer, $blocks, LindaLinks::for($user, 'past_due_loans'), array_column($listed, 'borrower_id'));
    }

    /**
     * @param  list<array{borrower_id: int, name: string, code: string, past_due_amount: string, days_overdue: int, loan_count: int}>  $members
     * @return list<array<string, mixed>>
     */
    private function items(User $user, array $members): array
    {
        $memberBranches = Borrower::query()
            ->whereIn('id', array_column($members, 'borrower_id'))
            ->pluck('branch_id', 'id');

        return array_map(function (array $member) use ($user, $memberBranches): array {
            $branchId = $memberBranches[$member['borrower_id']] ?? null;

            return [
                'title' => $member['name'],
                'subtitle' => $member['code'],
                'fields' => [
                    LindaReply::money('Past Due', $member['past_due_amount']),
                    LindaReply::number('Days Overdue', $member['days_overdue']),
                    LindaReply::number('Loans', $member['loan_count']),
                ],
                ...($this->visibility->allows($user, $branchId === null ? null : (int) $branchId)
                    ? ['url' => '/borrowers/'.$member['borrower_id']]
                    : []),
            ];
        }, $members);
    }
}
