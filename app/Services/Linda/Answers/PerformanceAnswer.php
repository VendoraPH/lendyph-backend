<?php

namespace App\Services\Linda\Answers;

use App\Models\Loan;
use App\Models\User;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;
use App\Services\LoanListTotals;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Branch or officer performance: the Officer / Branch Performance report's
 * rows (ReportService::performance()) for the user's branches, or the one
 * named: Released (count and amount) and Collected for the period, and
 * Outstanding as of today.
 *
 * Past Due is never the report's own `overdue_amount`, which counts a period
 * the day after it falls due. Each row's is worked out by the Past Due rule
 * (LoanListTotals: unpaid and past the due date plus the loan's grace) over
 * the row's collectible loans, so the rows add up to the Past Due Loans
 * page's total.
 *
 * Rows are ranked by what the question asks ("most collected", "released",
 * "overdue" or "atraso", "outstanding"; collected when it says none), highest
 * first, or lowest first for "least" or "lowest", at most `linda.list_max`
 * of them. This month by default.
 */
abstract class PerformanceAnswer extends Answer
{
    /**
     * What a ranking can read, in the order the question's words are tried:
     * the row key, its words in a reply and the words that ask for it.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const METRICS = [
        'past_due' => ['past due', '/(?<![\pL\pN])(overdue|past\s*due|atraso|naatraso|delinquen\pL*|late|arrears|delayed)(?![\pL\pN])/u'],
        'released_amount' => ['amount released', '/(?<![\pL\pN])(releas\pL*|na-?release|gi-?release|disburs\pL*|nailabas)(?![\pL\pN])/u'],
        'outstanding' => ['outstanding', '/(?<![\pL\pN])(outstanding|balance|balanse|portfolio)(?![\pL\pN])/u'],
        'collected' => ['collected', '/(?<![\pL\pN])(collect\pL*|nakolekta|koleksyon|nasingil)(?![\pL\pN])/u'],
    ];

    private const LOWEST_FIRST = '/(?<![\pL\pN])(least|lowest|fewest|smallest|pinakamababa|pinakakaunti|pinakamaliit|pinakagamay|kinagamyan|labing\s+gamay)(?![\pL\pN])/u';

    public function __construct(
        private readonly ReportService $reports,
        private readonly LoanListTotals $totals,
    ) {}

    /** 'branch' or 'officer'. */
    abstract protected function dimension(): string;

    /** "Branches", "Account officers": the list's title. */
    abstract protected function plural(): string;

    /**
     * Narrow the collectible loans to one row's: its branch or its officer.
     *
     * @param  Builder<Loan>  $loans
     * @param  array<string, mixed>  $row  a performance() row
     */
    abstract protected function narrowToRow(Builder $loans, array $row): void;

    /** A row's name, as the report shows it. */
    abstract protected function rowName(array $row): string;

    /**
     * A row's name at the start of a sentence about that row alone.
     *
     * @param  array<string, mixed>  $row  a ranked row (`name`, `source`, …)
     */
    protected function sentenceName(array $row): string
    {
        return $row['name'];
    }

    /**
     * The rows the question names, when it names any (officers only).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function named(array $rows, #[\SensitiveParameter] string $question): array
    {
        return [];
    }

    public function allows(User $user): bool
    {
        return $user->can('reports:view');
    }

    protected function subject(): string
    {
        return 'performance figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');
        $filters = [
            ...$scope->filters(),
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ];
        $report = $this->reports->performance($filters);
        $key = $this->dimension() === 'officer' ? 'by_officer' : 'by_branch';

        $rows = array_map(fn (array $row): array => [
            'name' => $this->rowName($row),
            'released_count' => (int) $row['released_count'],
            'released_amount' => (float) $row['released_amount'],
            'collected' => (float) $row['collected'],
            'outstanding' => (float) $row['total_outstanding'],
            'past_due' => $this->pastDue($scope, $row),
            'source' => $row,
        ], $report[$key]);

        $named = $question === null ? [] : $this->named($rows, $question);
        [$metric, $lowestFirst] = self::ranking((string) $question);

        $ranked = $named !== [] ? $named : $rows;
        // A tie on the asked figure puts the busier row first (more
        // outstanding), then goes by name.
        usort($ranked, fn (array $a, array $b): int => ($lowestFirst ? $a[$metric] <=> $b[$metric] : $b[$metric] <=> $a[$metric])
            ?: $b['outstanding'] <=> $a['outstanding']
            ?: strcmp($a['name'], $b['name']));

        $max = max(1, (int) config('linda.list_max', 10));
        $listed = array_slice($ranked, 0, $max);
        $links = LindaLinks::forPeriod($user, $period, $scope->branchId, 'performance');

        if (count($named) === 1) {
            return $this->reply($this->aboutOne($named[0], $period), [
                LindaReply::list(self::sentence(W::during($period)), [['title' => $named[0]['name'], 'fields' => self::fields($named[0])]]),
            ], $links);
        }

        $totals = $report[$key.'_totals'];
        $blocks = [LindaReply::stats([
            LindaReply::number('Released', (int) $totals['released_count']),
            LindaReply::money('Released Amount', $totals['released_amount']),
            LindaReply::money('Collected', $totals['collected']),
            LindaReply::money('Outstanding', $totals['total_outstanding']),
            LindaReply::money('Past Due', $this->pastDue($scope, null)),
        ], self::sentence(W::during($period)))];

        if ($listed !== []) {
            $blocks[] = LindaReply::list(
                $this->plural().' by '.($lowestFirst ? 'least ' : '').self::METRICS[$metric][0],
                array_map(fn (array $row): array => ['title' => $row['name'], 'fields' => self::fields($row)], $listed),
                true,
            );
        }

        return $this->reply($this->aboutAll($ranked, $listed, $metric, $lowestFirst, $scope, $period), $blocks, $links);
    }

    /**
     * Past due by the grace rule over the collectible loans in the scope,
     * narrowed to one row when $row is given.
     *
     * @param  array<string, mixed>|null  $row
     */
    private function pastDue(LindaScope $scope, ?array $row): float
    {
        $filters = $scope->filters();
        $branchIds = isset($filters['branch_id']) ? [$filters['branch_id']] : $filters['branch_ids'];

        $loans = Loan::query()
            ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('loans.branch_id', $branchIds));

        if ($row !== null) {
            $this->narrowToRow($loans, $row);
        }

        return (float) $this->totals->for($loans)['overdue_amount'];
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @param  list<array<string, mixed>>  $listed
     */
    private function aboutAll(array $ranked, array $listed, string $metric, bool $lowestFirst, LindaScope $scope, LindaPeriod $period): string
    {
        if ($listed === []) {
            return 'There are no '.mb_strtolower($this->plural()).' with loans '.W::forScope($scope).'.';
        }

        if (count($ranked) === 1) {
            return $this->aboutOne($ranked[0], $period);
        }

        $words = self::METRICS[$metric][0];
        $top = W::money($listed[0][$metric]);
        $tied = array_values(array_filter($ranked, fn (array $row): bool => W::money($row[$metric]) === $top));
        $rows = mb_strtolower($this->plural());
        $ranking = 'ranked by '.($lowestFirst ? 'least ' : '').$words.' '.W::forScope($scope).', ';

        $standing = match (true) {
            count($tied) === count($ranked) => (count($ranked) === 2 ? 'both '.$rows : 'all '.count($ranked).' '.$rows)
                .' have '.$top.' '.$words.'.',
            count($tied) > 3 => $ranking.count($tied).' '.$rows.' are tied first with '.$top.'.',
            count($tied) > 1 => $ranking.self::joined(array_column($tied, 'name')).' are tied first with '.$top.'.',
            default => $ranking.$listed[0]['name'].' comes first with '.$top.'.',
        };

        return self::sentence(W::during($period)).', '.$standing
            .(count($ranked) > count($listed) ? ' The top '.count($listed).' of '.count($ranked).' are listed below.' : '')
            .' Released and collected cover those dates; Outstanding and past due are as of today, and past due counts only payments late beyond their grace period.';
    }

    /**
     * "Far Branch and Main Branch", "A, B and C".
     *
     * @param  list<string>  $names
     */
    private static function joined(array $names): string
    {
        $last = (string) array_pop($names);

        return $names === [] ? $last : implode(', ', $names).' and '.$last;
    }

    /** @param  array<string, mixed>  $row */
    private function aboutOne(array $row, LindaPeriod $period): string
    {
        return $this->sentenceName($row).', '.W::during($period).': released '.W::count($row['released_count'], 'loan').' for '
            .W::money($row['released_amount']).' and collected '.W::money($row['collected']).'. '
            .W::asOfToday().', Outstanding is '.W::money($row['outstanding']).' and '.W::money($row['past_due'])
            .' is past due beyond the grace period.';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private static function fields(array $row): array
    {
        return [
            LindaReply::number('Released', $row['released_count']),
            LindaReply::money('Released Amount', $row['released_amount']),
            LindaReply::money('Collected', $row['collected']),
            LindaReply::money('Outstanding', $row['outstanding']),
            LindaReply::money('Past Due', $row['past_due']),
        ];
    }

    /**
     * The row key the question ranks by and whether lowest comes first.
     *
     * @return array{0: string, 1: bool}
     */
    private static function ranking(#[\SensitiveParameter] string $question): array
    {
        $text = mb_strtolower($question);
        $metric = 'collected';

        foreach (self::METRICS as $key => [, $pattern]) {
            if (preg_match($pattern, $text) === 1) {
                $metric = $key;
                break;
            }
        }

        return [$metric, preg_match(self::LOWEST_FIRST, $text) === 1];
    }
}
