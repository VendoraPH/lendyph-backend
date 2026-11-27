<?php

namespace App\Services\Linda\Answers;

use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaText;
use App\Services\Linda\LindaWording as W;
use App\Services\Savings\Dashboard\SavingsDashboard;
use App\Services\Savings\SavingsMath;

/**
 * "How much savings do we have?", "How much was deposited today?", "Which
 * branch has the highest savings?": the Savings dashboard's own figures
 * (SavingsDashboard::build(), GET /savings/dashboard) in the caller's
 * branches. It needs `savings:view`, as the dashboard does.
 *
 * - With no period, or "now" or "today" alone, the balances as of today:
 *   total deposits, the savings balance, time deposits outstanding, accounts
 *   by status and time deposits maturing in the next 30 days.
 * - With a period, or words about money in and out ("deposited", "net
 *   growth"; this month when no period is named, as the dashboard opens):
 *   the period's deposits and withdrawals (closing payouts included) and
 *   deposits less withdrawals, the period's net growth; then the balances at
 *   the period's end.
 * - A question about maturing time deposits keeps the as-of-today answer:
 *   they are only known for the next 30 days.
 * - A question that ranks branches ("which branch", "by branch",
 *   "highest") leads with the top branch of the dashboard's by-branch rows,
 *   by total deposits, or by the savings accounts or time deposits it names,
 *   and lists the branches.
 * - Total deposits by branch, from the same rows, is a stats block whenever
 *   more than one branch is read, so the open-question pack (LindaFacts)
 *   carries it: branch names and figures only.
 *
 * The backend has no savings switch of its own (the menu's is a frontend
 * build flag), so a deployment with no savings product and no account is
 * told plainly that savings isn't set up.
 */
final class SavingsSummaryAnswer extends Answer
{
    /** Words about money in and out over a period. */
    private const FLOWS = '/(?<![\pL\pN])(?:deposited|withdrawals?|withdrawn|withdrew|withdraw|net|growth|grow|grew|grown|increased?|decreased?|movements?|performing|doing|came\s+in|(?:na|nag|gi|ni)\s*deposit|(?:na|gi|ni)deposito|(?:na|nag|gi|ni)\s*withdraw)(?![\pL\pN])/u';

    /** Words about time deposits maturing. */
    private const MATURING = '/(?<![\pL\pN])(?:matur\pL*|mag\s*mature|ma\s*mature)(?![\pL\pN])/u';

    /** A branch word. */
    private const BRANCH = '/(?<![\pL\pN])(?:branch|branches|sanga|sangay)(?![\pL\pN])/u';

    /** With a branch word, a ranking of branches: "which branch", "by branch", "highest", "pinakadaghan". */
    private const RANKING = '/(?<![\pL\pN])(?:(?:which|what|anong|aling|alin|unsang|unsa|asang|hain|by|per|each|every|kada|matag|bawat)\s+(?:(?:na|nga)\s+)?(?:branch|branches|sanga|sangay)|most|highest|biggest|largest|top|best|least|lowest|smallest|compare|rank\pL*|daghan\pL*|marami|malaki\pL*|pinaka\pL*)(?![\pL\pN])/u';

    private const LOWEST_FIRST = '/(?<![\pL\pN])(?:least|lowest|smallest|pinakagamay|pinakamaliit|pinakamababa|pinakaubos)(?![\pL\pN])/u';

    /** What a ranking reads: the by-branch row's column, and its words. */
    private const RANKED_BY = [
        'total_deposits' => ['deposits', 'total deposits'],
        'closing_balance' => ['in savings accounts', 'savings balance'],
        'time_deposits' => ['in time deposits', 'time deposits'],
    ];

    public function __construct(private readonly SavingsDashboard $dashboard) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::SavingsSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('savings:view');
    }

    protected function subject(): string
    {
        return 'savings figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        if (! SavingsProduct::query()->exists() && ! SavingsAccount::query()->exists()) {
            return new LindaReply($this->intent()->value, W::savingsNotSetUp(), 'not_yet', answerFunction: $this->name());
        }

        $text = $question === null ? '' : LindaText::clean($question);
        $today = LindaPeriod::today();
        $flowWords = preg_match(self::FLOWS, $text) === 1;
        $note = '';

        if (preg_match(self::MATURING, $text) === 1) {
            $note = $period !== null && $period->to->toDateString() < $today->toDateString() ? ' '.W::todayOnly() : '';
            $period = null;
        } elseif ($period !== null && $period->from->isSameDay($today) && $period->to->isSameDay($today) && ! $flowWords) {
            $period = null;
        } elseif ($period === null && $flowWords) {
            $period = LindaPeriod::named('this_month');
        }

        $from = $period?->from->toDateString() ?? $today->startOfMonth()->toDateString();
        $to = $period?->to->toDateString() ?? $today->toDateString();
        $data = $this->dashboard->build(['branch_ids' => $scope->branchIds, 'branch_id' => $scope->branchId, 'product_id' => null], $from, $to);
        $kpis = $data['kpis'];
        $rows = $data['by_branch']['rows'];
        $asOf = $to === $today->toDateString() ? W::asOfToday() : 'On '.W::date($to);

        $ranking = preg_match(self::BRANCH, $text) === 1 && preg_match(self::RANKING, $text) === 1
            ? self::ranked($rows, $text)
            : null;

        $parts = [];

        if ($ranking !== null) {
            $parts[] = self::rankingLead($ranking, $asOf).' Total deposits '.W::forScope($scope).' '.($asOf === W::asOfToday() ? 'are ' : 'were ')
                .W::money($kpis['total_deposits']).'.';
        }

        if ($period !== null) {
            $parts[] = self::flows($kpis, $period, $scope);

            if ($ranking === null) {
                $parts[] = self::balances($kpis, $asOf);
            }
        } elseif ($ranking === null) {
            $parts[] = self::asOfToday($kpis, $scope);
        }

        $blocks = $period === null
            ? [self::todayStats($kpis)]
            : [self::flowStats($kpis, $period), self::balanceStats($kpis, $asOf)];

        if ($ranking !== null) {
            $blocks[] = self::rankingList($ranking);
        } elseif (count($rows) > 1) {
            $blocks[] = self::byBranchStats($rows, $asOf);
        }

        return $this->reply(implode(' ', $parts).$note, $blocks, LindaLinks::for($user, 'savings_dashboard'));
    }

    /**
     * The branches ranked by the figure the question names (total deposits
     * by default), "No branch" left out, or null when fewer than two branches
     * are read.
     *
     * @param  list<array<string, mixed>>  $rows  the dashboard's `by_branch` rows
     * @return array{column: string, lowest_first: bool, rows: list<array<string, mixed>>}|null
     */
    private static function ranked(array $rows, string $text): ?array
    {
        $branches = array_values(array_filter($rows, fn (array $row) => $row['group_id'] !== null));

        if (count($branches) < 2) {
            return null;
        }

        $column = match (true) {
            preg_match('/(?<![\pL\pN])time\s+deposits?(?![\pL\pN])/u', $text) === 1 => 'time_deposits',
            preg_match('/(?<![\pL\pN])(?:savings\s+(?:accounts?|balances?)|regular\s+savings)(?![\pL\pN])/u', $text) === 1 => 'closing_balance',
            default => 'total_deposits',
        };
        $lowestFirst = preg_match(self::LOWEST_FIRST, $text) === 1;

        usort($branches, fn (array $a, array $b): int => ($lowestFirst
            ? self::centavos($a[$column]) <=> self::centavos($b[$column])
            : self::centavos($b[$column]) <=> self::centavos($a[$column]))
            ?: strcmp((string) $a['group_name'], (string) $b['group_name']));

        return ['column' => $column, 'lowest_first' => $lowestFirst, 'rows' => $branches];
    }

    /**
     * "As of today, …, Main Branch holds the most deposits: ₱33,259.87
     * (₱3,059.87 in savings accounts and ₱30,200.00 in time deposits)."
     *
     * @param  array{column: string, lowest_first: bool, rows: list<array<string, mixed>>}  $ranking
     */
    private static function rankingLead(array $ranking, string $asOf): string
    {
        $column = $ranking['column'];
        $first = $ranking['rows'][0];
        $amount = self::centavos($first[$column]);
        [$what] = self::RANKED_BY[$column];
        $most = $ranking['lowest_first'] ? 'the least' : 'the most';

        if (! $ranking['lowest_first'] && $amount === 0) {
            return $asOf.', no branch holds any '.['total_deposits' => 'deposits', 'closing_balance' => 'savings', 'time_deposits' => 'time deposits'][$column].' yet.';
        }

        $tied = array_values(array_filter($ranking['rows'], fn (array $row) => self::centavos($row[$column]) === $amount));

        if (count($tied) > 1) {
            return $asOf.', '.W::listOf(array_column($tied, 'group_name')).' hold '.$most.' '.$what.', '.W::money($first[$column]).' each.';
        }

        $detail = $column === 'total_deposits'
            ? ' ('.W::money($first['closing_balance']).' in savings accounts and '.W::money($first['time_deposits']).' in time deposits)'
            : '';

        return $asOf.', '.$first['group_name'].' holds '.$most.' '.$what.': '.W::money($first[$column]).$detail.'.';
    }

    /**
     * "From November 1 to 10, 2026, members deposited ₱X (N deposits) and
     * withdrew ₱Y (M withdrawals) across all branches, so deposits less
     * withdrawals came to ₱Z."
     *
     * @param  array<string, mixed>  $kpis
     */
    private static function flows(array $kpis, LindaPeriod $period, LindaScope $scope): string
    {
        $deposits = (int) $kpis['deposits']['count'];
        $withdrawals = (int) $kpis['withdrawals']['count'];

        if ($deposits === 0 && $withdrawals === 0) {
            return self::sentence(W::during($period)).', no deposits or withdrawals were made '.W::forScope($scope).'.';
        }

        return self::sentence(W::during($period)).', members deposited '.W::money($kpis['deposits']['amount']).' ('.W::count($deposits, 'deposit').') and withdrew '
            .W::money($kpis['withdrawals']['amount']).' ('.W::count($withdrawals, 'withdrawal').') '.W::forScope($scope)
            .', so deposits less withdrawals came to '.W::money($kpis['net_deposit_movement']).'.';
    }

    /**
     * The balances at the period's end: "As of today, …, total deposits are
     * ₱T: ₱S in savings accounts and ₱D in N time deposits."
     *
     * @param  array<string, mixed>  $kpis
     */
    private static function balances(array $kpis, string $asOf): string
    {
        $today = $asOf === W::asOfToday();

        return $asOf.($today ? '' : ', the end of the period').', total deposits '.($today ? 'are ' : 'were ').W::money($kpis['total_deposits']).': '
            .W::money($kpis['savings_balance']).' in savings accounts and '.W::money($kpis['time_deposits']['amount']).' in '
            .W::count((int) $kpis['time_deposits']['count'], 'time deposit').'.';
    }

    /**
     * The balances as of today, with the accounts by status and the time
     * deposits maturing in the next 30 days.
     *
     * @param  array<string, mixed>  $kpis
     */
    private static function asOfToday(array $kpis, LindaScope $scope): string
    {
        $accounts = $kpis['accounts'];
        $maturing = $kpis['maturing_30_days'];

        return W::asOfToday().', total deposits '.W::forScope($scope).' are '.W::money($kpis['total_deposits']).': '
            .W::money($kpis['savings_balance']).' in savings accounts and '.W::money($kpis['time_deposits']['amount']).' in '
            .W::count((int) $kpis['time_deposits']['count'], 'time deposit').'. There '.((int) $accounts['active'] === 1 ? 'is ' : 'are ')
            .W::listOf([$accounts['active'].' active', $accounts['dormant'].' dormant', $accounts['frozen'].' frozen'])
            .' savings '.((int) $accounts['active'] === 1 ? 'account' : 'accounts').'. '
            .((int) $maturing['count'] === 0
                ? 'No time deposit matures in the next 30 days.'
                : W::count((int) $maturing['count'], 'time deposit').' ('.W::money($maturing['principal']).') '
                    .((int) $maturing['count'] === 1 ? 'matures' : 'mature').' in the next 30 days, paying '.W::money($maturing['maturity_amount']).'.');
    }

    /**
     * @param  array<string, mixed>  $kpis
     * @return array<string, mixed>
     */
    private static function todayStats(array $kpis): array
    {
        $accounts = $kpis['accounts'];
        $maturing = $kpis['maturing_30_days'];

        return LindaReply::stats([
            LindaReply::money('Total Deposits', $kpis['total_deposits']),
            LindaReply::money('Savings Balance', $kpis['savings_balance']),
            LindaReply::number('Time Deposits', (int) $kpis['time_deposits']['count']),
            LindaReply::money('Time Deposits (₱)', $kpis['time_deposits']['amount']),
            LindaReply::number('Active Accounts', (int) $accounts['active']),
            LindaReply::number('Dormant Accounts', (int) $accounts['dormant']),
            LindaReply::number('Frozen Accounts', (int) $accounts['frozen']),
            LindaReply::number('Maturing in 30 Days', (int) $maturing['count']),
            LindaReply::money('Maturity Amount (30 Days)', $maturing['maturity_amount']),
        ], W::asOfToday());
    }

    /**
     * The period's flows, as the dashboard's Deposits, Withdrawals and
     * Deposits less Withdrawals cards show them.
     *
     * @param  array<string, mixed>  $kpis
     * @return array<string, mixed>
     */
    private static function flowStats(array $kpis, LindaPeriod $period): array
    {
        return LindaReply::stats([
            LindaReply::money('Deposits', $kpis['deposits']['amount']),
            LindaReply::number('Number of Deposits', (int) $kpis['deposits']['count']),
            LindaReply::money('Withdrawals', $kpis['withdrawals']['amount']),
            LindaReply::number('Number of Withdrawals', (int) $kpis['withdrawals']['count']),
            LindaReply::money('Deposits less Withdrawals', $kpis['net_deposit_movement']),
        ], self::sentence(W::during($period)));
    }

    /**
     * @param  array<string, mixed>  $kpis
     * @return array<string, mixed>
     */
    private static function balanceStats(array $kpis, string $asOf): array
    {
        return LindaReply::stats([
            LindaReply::money('Total Deposits', $kpis['total_deposits']),
            LindaReply::money('Savings Balance', $kpis['savings_balance']),
            LindaReply::number('Time Deposits', (int) $kpis['time_deposits']['count']),
            LindaReply::money('Time Deposits (₱)', $kpis['time_deposits']['amount']),
        ], $asOf);
    }

    /**
     * Each branch's total deposits, highest first, labelled by the branch's
     * name: what the open-question pack reads for savings by branch.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function byBranchStats(array $rows, string $asOf): array
    {
        usort($rows, fn (array $a, array $b): int => self::centavos($b['total_deposits']) <=> self::centavos($a['total_deposits'])
            ?: strcmp((string) $a['group_name'], (string) $b['group_name']));

        return LindaReply::stats(
            array_map(fn (array $row): array => LindaReply::money((string) $row['group_name'], $row['total_deposits']), $rows),
            'Total deposits by branch, '.lcfirst($asOf),
        );
    }

    /**
     * @param  array{column: string, lowest_first: bool, rows: list<array<string, mixed>>}  $ranking
     * @return array<string, mixed>
     */
    private static function rankingList(array $ranking): array
    {
        $max = max(1, (int) config('linda.list_max', 10));

        return LindaReply::list(
            'Branches by '.($ranking['lowest_first'] ? 'least ' : '').self::RANKED_BY[$ranking['column']][1],
            array_map(fn (array $row): array => [
                'title' => (string) $row['group_name'],
                'fields' => [
                    LindaReply::money('Savings Balance', $row['closing_balance']),
                    LindaReply::money('Time Deposits (₱)', $row['time_deposits']),
                    LindaReply::money('Total Deposits', $row['total_deposits']),
                ],
            ], array_slice($ranking['rows'], 0, $max)),
            true,
        );
    }

    /** A dashboard figure (a two-decimal string) in whole centavos, for comparing. */
    private static function centavos(string $pesos): int
    {
        return SavingsMath::centavos($pesos);
    }
}
