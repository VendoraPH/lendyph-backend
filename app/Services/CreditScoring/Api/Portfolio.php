<?php

namespace App\Services\CreditScoring\Api;

use App\Models\CreditPolicyFlag;
use App\Models\CreditRiskAlert;
use App\Models\CreditScore;
use App\Models\CreditScoreMember;
use App\Models\User;
use App\Services\CreditScoring\Recalculator;
use App\Services\LoanListTotals;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Credit Scoring dashboard and risk monitoring, for the members the
 * caller may see. Every count and the average come from the server.
 */
final class Portfolio
{
    public const RISK_LEVELS = ['very_low', 'low', 'moderate', 'elevated', 'high', 'very_high'];

    public const RECENT_CHANGES = 10;

    public const MONITORING_CAP = 100;

    public const WINDOW_DAYS = 30;

    public function __construct(
        private readonly CreditScope $scope,
        private readonly Presenter $presenter,
        private readonly LoanListTotals $totals,
        private readonly ScorecardEditor $editor,
    ) {}

    /**
     * The `CreditScoringDashboardSummary` shape, plus
     * `insufficient_data_count` and the task's section 12 figures. Averages
     * are half up to one decimal.
     *
     * - `branch_risk`: one row per member branch with a scored member the
     *   caller can see (the member's own current branch), by branch name.
     * - `high_risk_outstanding`: self::highRiskOutstanding(); null without
     *   `loans:view`.
     * - `scores_due_for_review`: self::dueForReview().
     *
     * @return array<string, mixed>
     */
    public function dashboard(User $user): array
    {
        $scored = $this->scope->currentScores($user)->where('credit_score_members.status', CreditScore::STATUS_SCORED);
        $count = (clone $scored)->count();
        $sum = (int) (clone $scored)->sum('credit_score_members.score');
        $byLevel = (clone $scored)
            ->select('credit_score_members.risk_level')
            ->selectRaw('COUNT(*) AS members')
            ->groupBy('credit_score_members.risk_level')
            ->pluck('members', 'risk_level');

        return [
            'total_scored_borrowers' => $count,
            'average_score' => self::average($sum, $count),
            'insufficient_data_count' => $this->scope->currentScores($user)->where('credit_score_members.status', CreditScore::STATUS_INSUFFICIENT_DATA)->count(),
            'risk_distribution' => self::distribution($byLevel->all()),
            'branch_risk' => $this->branchRisk($user),
            'high_risk_outstanding' => $this->highRiskOutstanding($user),
            'scores_due_for_review' => $this->dueForReview($user)->count(),
            'recent_score_changes' => $this->scope->history($user)
                ->with('borrower:'.implode(',', CreditScope::MEMBER_COLUMNS))
                ->orderByDesc('calculated_at')
                ->orderByDesc('id')
                ->limit(self::RECENT_CHANGES)
                ->get()
                ->map(fn (CreditScore $score) => $this->presenter->history($score, $user))
                ->all(),
        ];
    }

    /**
     * The `RiskMonitoringData` shape, plus `affected_total`.
     *
     * - Summary: members with a current score; high risk is high plus very
     *   high; declines are score rows that fell, and new hard flags are hard
     *   flags first raised, both in the last 30 days (rolling). A flag closed
     *   because its draft loan was deleted isn't counted (Lead's call 8).
     * - Affected members: in a high band, past due, or holding an open hard
     *   flag, lowest score first, at most 100, with the full count.
     * - Past due is a loan fact: without `loans:view` it lists no member.
     * - Flags are member details (round fz): a caller without
     *   `borrowers:view` gets `new_hard_flags_30d` null and no member listed
     *   for a flag alone. With it, every member they can see is one whose
     *   details they may read (CreditScope::seesMemberDetails()).
     * - Alerts: the last 30 days, newest first, at most 100.
     * - `past_due_borrowers` (task section 22): members the caller can see
     *   with a collectible loan they can see that is past due by the grace
     *   rule (Loan::scopePastDue(), the dashboard's overdue rule), each
     *   counted once, scored or not; null without `loans:view`.
     * - `high_risk_exposure`: the dashboard's `high_risk_outstanding`.
     *
     * @return array<string, mixed>
     */
    public function riskMonitoring(User $user): array
    {
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);
        $readsFlags = $user->can('borrowers:view');
        $affected = $this->scope->currentScores($user)->where(fn (Builder $q) => $q
            ->whereIn('credit_score_members.risk_level', Recalculator::HIGH_RISK_BANDS)
            ->when($user->can('loans:view'), fn (Builder $q) => $q->orWhere('credit_score_members.past_due', true))
            ->when($readsFlags, fn (Builder $q) => $q->orWhereIn('credit_score_members.borrower_id', $this->scope->openFlags($user)->where('type', CreditPolicyFlag::HARD)->select('borrower_id'))));

        return [
            'summary' => [
                'total_borrowers' => $this->scope->currentScores($user)->count(),
                'high_risk_count' => $this->scope->currentScores($user)->whereIn('credit_score_members.risk_level', Recalculator::HIGH_RISK_BANDS)->count(),
                'score_declines_30d' => $this->scope->history($user)
                    ->where('calculated_at', '>=', $since)
                    ->whereNotNull('score')
                    ->whereNotNull('previous_score')
                    ->whereColumn('score', '<', 'previous_score')
                    ->count(),
                'new_hard_flags_30d' => ! $readsFlags ? null : CreditPolicyFlag::query()
                    ->where('type', CreditPolicyFlag::HARD)
                    ->where('first_triggered_at', '>=', $since)
                    // A deleted draft's flags lost their loan; they were never the member's.
                    ->where(fn ($q) => $q->whereNull('cleared_reason')->orWhere('cleared_reason', '!=', CreditPolicyFlag::CLEARED_LOAN_DELETED))
                    ->whereIn('borrower_id', $this->scope->memberIds($user))
                    ->where(fn ($q) => $q->whereNull('loan_id')->when($user->can('loans:view'), fn ($q) => $q->orWhereIn('loan_id', $this->scope->loanIds($user))))
                    ->count(),
                'past_due_borrowers' => ! $user->can('loans:view') ? null : Presenter::collectibleLoans($user)
                    ->pastDue()
                    ->whereIn('loans.borrower_id', $this->scope->memberIds($user))
                    ->distinct()
                    ->count('loans.borrower_id'),
                'high_risk_exposure' => $this->highRiskOutstanding($user),
            ],
            'affected_total' => (clone $affected)->count(),
            'affected_borrowers' => $affected
                ->with(['borrower:'.implode(',', CreditScope::MEMBER_COLUMNS), 'borrower.branch:id,name'])
                ->orderByRaw('credit_score_members.score IS NULL')
                ->orderBy('credit_score_members.score')
                ->orderBy('credit_score_members.borrower_id')
                ->limit(self::MONITORING_CAP)
                ->get()
                ->pipe(function (Collection $rows) use ($user) {
                    $outstanding = $this->presenter->outstanding($rows->pluck('borrower_id'), $user);

                    return $rows->map(fn (CreditScoreMember $current) => $this->presenter->row($current, $user, $outstanding));
                })
                ->all(),
            'alerts' => CreditRiskAlert::query()
                ->whereIn('borrower_id', $this->scope->memberIds($user))
                ->where('created_at', '>=', $since)
                ->with('borrower:'.implode(',', CreditScope::MEMBER_COLUMNS))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::MONITORING_CAP)
                ->get()
                ->map(fn (CreditRiskAlert $alert) => $this->presenter->alert($alert))
                ->all(),
        ];
    }

    /**
     * What the high and very high risk members the caller can see still owe
     * on the collectible loans the caller can see (LoanListTotals
     * `total_outstanding`, premium owed included), as a 2-decimal peso
     * string; null without `loans:view`.
     */
    public function highRiskOutstanding(User $user): ?string
    {
        if (! $user->can('loans:view')) {
            return null;
        }

        $members = $this->scope->currentScores($user)
            ->whereIn('credit_score_members.risk_level', Recalculator::HIGH_RISK_BANDS)
            ->select('credit_score_members.borrower_id');

        return Presenter::pesos($this->totals->for(Presenter::collectibleLoans($user)->whereIn('loans.borrower_id', $members))['total_outstanding']);
    }

    /**
     * The current scores due for review: the member has an open hard flag,
     * or their score falls at least one band below their previous score.
     * Both scores are read through the current revision's bands, never the
     * stored `risk_level` (the bands at the last run), so a band edit alone
     * moves nobody until a score itself changes. Flags are member details
     * (round fz), so without `borrowers:view` only the band drops count, as
     * on Risk Monitoring.
     *
     * @return Builder<CreditScoreMember>
     */
    public function dueForReview(User $user): Builder
    {
        $bands = $this->bandsHighestFirst();
        [$currentBand, $currentBindings] = self::bandRankSql('credit_score_members.score', $bands);
        [$previousBand, $previousBindings] = self::bandRankSql('credit_score_members.previous_score', $bands);

        return $this->scope->currentScores($user)->where(fn (Builder $q) => $q
            ->where(fn (Builder $drop) => $drop
                ->where('credit_score_members.status', CreditScore::STATUS_SCORED)
                ->whereNotNull('credit_score_members.score')
                ->whereNotNull('credit_score_members.previous_score')
                ->whereRaw("({$currentBand}) > ({$previousBand})", [...$currentBindings, ...$previousBindings]))
            ->when($user->can('borrowers:view'), fn (Builder $q) => $q->orWhereIn(
                'credit_score_members.borrower_id',
                $this->scope->openFlags($user)->where('type', CreditPolicyFlag::HARD)->select('borrower_id'),
            )));
    }

    /**
     * A score column's band rank (0 very low to 5 very high risk) under the
     * given bands, highest minimum first: the first band whose minimum the
     * score reaches (FactorTable::bandFor()'s rule), as SQL and bindings.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return array{0: string, 1: list<int>}
     */
    private static function bandRankSql(string $column, array $bands): array
    {
        $sql = 'CASE';
        $bindings = [];

        foreach ($bands as $band) {
            $sql .= " WHEN {$column} >= ? THEN ?";
            array_push($bindings, (int) $band['min_score'], self::rank((string) $band['key']));
        }

        $bindings[] = count(self::RISK_LEVELS) - 1;

        return [$sql.' ELSE ? END', $bindings];
    }

    /**
     * Portfolio risk by the member's own branch.
     *
     * @return list<array{branch_id: int|null, branch_name: string|null, members_scored: int, average_score: float|int|null, risk_distribution: list<array{risk_level: string, count: int}>}>
     */
    private function branchRisk(User $user): array
    {
        $rows = $this->scope->currentScores($user)
            ->where('credit_score_members.status', CreditScore::STATUS_SCORED)
            ->leftJoin('branches', 'branches.id', '=', 'borrowers.branch_id')
            ->select('borrowers.branch_id', 'branches.name as branch_name', 'credit_score_members.risk_level')
            ->selectRaw('COUNT(*) AS members, SUM(credit_score_members.score) AS score_sum')
            ->groupBy('borrowers.branch_id', 'branches.name', 'credit_score_members.risk_level')
            ->toBase()
            ->get();

        return $rows->groupBy(fn (object $row) => $row->branch_id === null ? '' : (string) $row->branch_id)
            ->map(function (Collection $levels) {
                $count = (int) $levels->sum('members');
                $first = $levels->first();

                return [
                    'branch_id' => $first->branch_id === null ? null : (int) $first->branch_id,
                    'branch_name' => $first->branch_name,
                    'members_scored' => $count,
                    'average_score' => self::average((int) $levels->sum('score_sum'), $count),
                    'risk_distribution' => self::distribution($levels->pluck('members', 'risk_level')->all()),
                ];
            })
            ->sortBy(fn (array $row) => [$row['branch_name'] === null ? 1 : 0, (string) $row['branch_name'], (int) $row['branch_id']])
            ->values()
            ->all();
    }

    /**
     * The current revision's risk bands, highest minimum first.
     *
     * @return list<array<string, mixed>>
     */
    private function bandsHighestFirst(): array
    {
        $bands = array_values($this->editor->current()->risk_bands ?? []);
        usort($bands, fn (array $a, array $b) => (int) $b['min_score'] <=> (int) $a['min_score']);

        return $bands;
    }

    /**
     * A risk level's place from very low (0) to very high (5).
     */
    private static function rank(string $level): int
    {
        $rank = array_search($level, self::RISK_LEVELS, true);

        return $rank === false ? count(self::RISK_LEVELS) - 1 : $rank;
    }

    /**
     * Every risk level once, in order, with its count (0 when absent).
     *
     * @param  array<string, int|string>  $byLevel
     * @return list<array{risk_level: string, count: int}>
     */
    private static function distribution(array $byLevel): array
    {
        return array_map(fn (string $level) => ['risk_level' => $level, 'count' => (int) ($byLevel[$level] ?? 0)], self::RISK_LEVELS);
    }

    /**
     * The average of `$count` scores summing to `$sum`, half up to one
     * decimal, or null for none.
     */
    private static function average(int $sum, int $count): float|int|null
    {
        return $count === 0 ? null : intdiv(20 * $sum + $count, 2 * $count) / 10;
    }
}
