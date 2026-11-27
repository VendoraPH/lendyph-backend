<?php

namespace App\Services\Linda\Answers;

use App\Models\Borrower;
use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaText;
use App\Services\Linda\LindaWording as W;
use App\Services\ReportService;

/**
 * "How many active members do we have?", "How many new members this month?":
 * member counts, from the screens' own code.
 *
 * - Status counts, as of today: the Members page's `meta.stats`
 *   (Borrower::statusCounts(), GET /borrowers), needing `borrowers:view`.
 *   Members only: walk-ins and co-makers aren't members, and pending or
 *   rejected registrations aren't counted.
 * - "With a loan being repaid", new and repeat members: the Member Report's
 *   cards (ReportService::memberCounts(), GET /reports/borrowers), needing
 *   `reports:view`. New members are those created in the period (this month
 *   by default); the status counts ignore periods.
 *
 * The question's own words choose the lead: new or repeat members (or a
 * period with no other word, as a follow-up "how about last month?" gives)
 * read the Member Report for the period; otherwise the status counts. A
 * figure the caller's permissions don't open is left out, and a question
 * whose main figure they don't open is refused.
 */
final class MemberSummaryAnswer extends Answer
{
    private const NEW_WORDS = ['new', 'bago', 'bagong', 'joined', 'join', 'sumali', 'miapil', 'nag join', 'bag ong', 'bag o'];

    private const REPEAT_WORDS = ['repeat', 'ulit', 'umuulit', 'balik'];

    private const ACTIVE_WORDS = ['active', 'aktibo', 'aktibong'];

    public function __construct(private readonly ReportService $reports) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::BorrowerSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('borrowers:view') || $user->can('reports:view');
    }

    protected function subject(): string
    {
        return 'member figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $focus = self::focus((string) $question, $period);

        if (in_array($focus, ['new', 'repeat'], true)) {
            return $user->can('reports:view') ? $this->periodFigures($user, $scope, $period, $focus) : $this->denied();
        }

        return $user->can('borrowers:view') ? $this->statusFigures($user, $scope, $period, $focus) : $this->denied();
    }

    /** The Members page's counts, plus the Member Report's "with a loan being repaid". */
    private function statusFigures(User $user, LindaScope $scope, ?LindaPeriod $period, string $focus): LindaReply
    {
        $counts = Borrower::statusCounts($user, $scope->branchId);
        $total = $counts['active'] + $counts['inactive'] + $counts['blacklisted'] + $counts['withdrawn'] + $counts['deceased'];
        $repaying = $user->can('reports:view') ? $this->reports->memberCounts($scope->filters())['active'] : null;

        $parts = [];
        foreach (['active', 'inactive', 'blacklisted', 'withdrawn', 'deceased'] as $status) {
            if ($counts[$status] > 0 || in_array($status, ['active', 'inactive', 'blacklisted'], true)) {
                $parts[] = number_format($counts[$status]).' '.$status;
            }
        }

        $answer = match (true) {
            $focus === 'active' => W::asOfToday().', you have '.W::count($counts['active'], 'active member').' '.W::forScope($scope).'.',
            $total === 0 => W::asOfToday().', there are no members '.W::forScope($scope).'.',
            default => W::asOfToday().', you have '.W::count($total, 'member').' '.W::forScope($scope).': '.W::listOf($parts).'.',
        };

        if ($repaying !== null) {
            $answer .= ' '.W::count($repaying, 'member').' '.($repaying === 1 ? 'has' : 'have').' a loan being repaid.';
        }

        if ($period !== null && $period->key !== 'today') {
            $answer .= ' '.W::todayOnly();
        }

        $items = [
            LindaReply::number('Members', $total),
            LindaReply::number('Active', $counts['active']),
            LindaReply::number('Inactive', $counts['inactive']),
            LindaReply::number('Blacklisted', $counts['blacklisted']),
        ];
        foreach (['withdrawn' => 'Withdrawn', 'deceased' => 'Deceased'] as $status => $label) {
            if ($counts[$status] > 0) {
                $items[] = LindaReply::number($label, $counts[$status]);
            }
        }
        if ($repaying !== null) {
            $items[] = LindaReply::number('With a Loan Being Repaid', $repaying);
        }

        return $this->reply(
            $answer,
            [LindaReply::stats($items, W::asOfToday())],
            LindaLinks::forPeriod($user, null, $scope->branchId, 'members', 'member_report'),
        );
    }

    /** The Member Report's new and repeat members for the period. */
    private function periodFigures(User $user, LindaScope $scope, ?LindaPeriod $period, string $focus): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');

        $cards = $this->reports->memberCounts([
            ...$scope->filters(),
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ]);

        $new = self::sentence(W::during($period)).', '.($cards['new'] === 0 ? 'no new members' : W::count($cards['new'], 'new member'))
            .' joined '.W::forScope($scope).'.';
        $repeat = W::count($cards['repeat'], 'member').' '.($cards['repeat'] === 1 ? 'has' : 'have')
            .' had 2 or more loans released (repeat members) '.W::forScope($scope).'.';

        return $this->reply(
            $focus === 'repeat' ? $repeat.' '.$new : $new.' '.$repeat,
            [LindaReply::stats([
                LindaReply::number('New Members', $cards['new']),
                LindaReply::number('Repeat Members', $cards['repeat']),
            ], self::sentence(W::during($period)))],
            LindaLinks::forPeriod($user, $period, $scope->branchId, 'member_report'),
        );
    }

    /**
     * What the question asks about: 'repeat', 'new', 'active' or 'counts'. A
     * period with none of those words means new members, except "now" or
     * "today", which mean the counts as of today.
     */
    private static function focus(#[\SensitiveParameter] string $question, ?LindaPeriod $period): string
    {
        $clean = ' '.implode(' ', LindaText::words(LindaText::clean($question))).' ';
        $has = fn (array $phrases): bool => array_filter($phrases, fn (string $phrase) => str_contains($clean, ' '.$phrase.' ')) !== [];

        return match (true) {
            $has(self::REPEAT_WORDS) => 'repeat',
            $has(self::NEW_WORDS) => 'new',
            $has(self::ACTIVE_WORDS) => 'active',
            $period !== null && $period->key !== 'today' => 'new',
            default => 'counts',
        };
    }
}
