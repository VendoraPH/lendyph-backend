<?php

namespace App\Services\Linda\Answers;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\User;
use App\Services\Accounting\Money;
use App\Services\BorrowerLoanSummaryService;
use App\Services\ContactVisibility;
use App\Services\Linda\LindaFinder;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording as W;
use App\Services\RepaymentService;

/**
 * "What does Juan Dela Cruz owe?": one member, by code or by name, with the
 * member page's own figures for this user (BorrowerLoanSummaryService::
 * summarize(), never forMember(), so a branch user counts only the loans
 * they can see): Outstanding, Past Due (after grace) and Current Loans. The
 * next due date is the earliest across the member's loans that still owe
 * (released, ongoing, defaulted), and its amount the loan summaries'
 * `next_due_amount` for that date, added in centavos. Each of those loans is
 * listed with its Outstanding (GET /loans/{id}/summary) and its status in
 * words, at most `linda.list_max` of them.
 *
 * It mirrors the member page, so it needs `borrowers:view` and `loans:view`.
 * Only the name and code are shown, never contact details or an address.
 */
final class MemberLookupAnswer extends LookupAnswer
{
    public function __construct(
        LindaFinder $finder,
        ContactVisibility $visibility,
        private readonly BorrowerLoanSummaryService $summaries,
        private readonly RepaymentService $repayments,
    ) {
        parent::__construct($finder, $visibility);
    }

    public function intent(): LindaIntent
    {
        return LindaIntent::BorrowerLookup;
    }

    public function allows(User $user): bool
    {
        return $user->can('borrowers:view') && $user->can('loans:view');
    }

    protected function subject(): string
    {
        return 'member details';
    }

    public function find(User $user, LindaLookupRequest $request): LindaReply
    {
        $member = $this->member($user, $request);

        return $member instanceof Borrower ? $this->about($user, $member) : $member;
    }

    private function about(User $user, Borrower $member): LindaReply
    {
        $summary = $this->summaries->summarize($member, $user);

        $loans = Loan::query()
            ->visibleTo($user)
            ->where('loans.borrower_id', $member->id)
            ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
            ->with('loanProduct:id,name')
            ->orderBy('loans.released_at')
            ->orderBy('loans.id')
            ->get();

        $loanSummaries = $loans->mapWithKeys(fn (Loan $loan) => [$loan->id => $this->repayments->getLoanSummary($loan)]);
        [$nextDate, $nextCentavos] = self::nextDue($loanSummaries->all());

        $current = (int) $summary['current_loans'];
        $who = $member->full_name.' ('.$member->borrower_code.'), '.($member->branch?->name ?? 'no branch');

        if ($loans->isEmpty()) {
            $answer = $who.', has no loans that still owe anything as of today, '.W::today().'.';
        } else {
            $answer = $who.', has '.W::count($loans->count(), 'loan').' still owing. '
                .W::asOfToday().', Outstanding is '.W::money($summary['total_outstanding'])
                .((float) $summary['overdue_amount'] > 0
                    ? ', of which '.W::money($summary['overdue_amount']).' is past due.'
                    : ', and nothing is past due.')
                .($nextDate === null
                    ? ''
                    : ' '.W::nextDue(Money::fromCentavos($nextCentavos), $nextDate, (float) $summary['overdue_amount'] <= 0));
        }

        $stats = [
            LindaReply::money('Outstanding', $summary['total_outstanding']),
            LindaReply::money('Past Due', $summary['overdue_amount']),
            LindaReply::number('Current Loans', $current),
        ];

        if ($nextDate !== null) {
            array_push($stats, ...self::nextDueStats(Money::fromCentavos($nextCentavos), $nextDate));
        }

        $listed = $loans->take(self::listMax());
        $blocks = [LindaReply::stats($stats, W::asOfToday())];

        if ($listed->isNotEmpty()) {
            $blocks[] = LindaReply::list('Loans that still owe', $listed->map(fn (Loan $loan): array => [
                'title' => (string) $loan->displayNumber(),
                'subtitle' => (string) ($loan->loanProduct?->name ?? ''),
                'fields' => [
                    LindaReply::money('Outstanding', $loanSummaries[$loan->id]['total_outstanding']),
                    LindaReply::text('Status', W::loanStatus($loan->displayStatus())),
                ],
                'url' => '/loans/'.$loan->id,
            ])->values()->all());
        }

        return $this->reply(
            $answer,
            'answered',
            $blocks,
            [['label' => 'Open Member', 'url' => '/borrowers/'.$member->id]],
            [$member->id],
            $listed->pluck('id')->all(),
        );
    }

    /**
     * The earliest next due date across the loan summaries, and what falls
     * due on it over every loan, in centavos.
     *
     * @param  array<int, array<string, mixed>>  $summaries
     * @return array{0: ?string, 1: int}
     */
    private static function nextDue(array $summaries): array
    {
        $dates = array_filter(array_column($summaries, 'next_due_date'));

        if ($dates === []) {
            return [null, 0];
        }

        $earliest = min($dates);
        $centavos = 0;

        foreach ($summaries as $summary) {
            if ($summary['next_due_date'] === $earliest) {
                $centavos += (int) Money::toCentavos((string) $summary['next_due_amount']);
            }
        }

        return [$earliest, $centavos];
    }
}
