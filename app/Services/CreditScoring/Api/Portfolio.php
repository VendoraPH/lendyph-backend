<?php

namespace App\Services\CreditScoring\Api;

use App\Models\CreditPolicyFlag;
use App\Models\CreditRiskAlert;
use App\Models\CreditScore;
use App\Models\CreditScoreMember;
use App\Models\User;
use App\Services\CreditScoring\Recalculator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

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
    ) {}

    /**
     * The `CreditScoringDashboardSummary` shape, plus
     * `insufficient_data_count`. The average is half up to one decimal.
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
            'average_score' => $count === 0 ? null : intdiv(20 * $sum + $count, 2 * $count) / 10,
            'insufficient_data_count' => $this->scope->currentScores($user)->where('credit_score_members.status', CreditScore::STATUS_INSUFFICIENT_DATA)->count(),
            'risk_distribution' => array_map(fn (string $level) => ['risk_level' => $level, 'count' => (int) ($byLevel[$level] ?? 0)], self::RISK_LEVELS),
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
     *   flags first raised, both in the last 30 days (rolling).
     * - Affected members: in a high band, past due, or holding an open hard
     *   flag, lowest score first, at most 100, with the full count.
     * - Past due is a loan fact: without `loans:view` it lists no member.
     * - Flags are member details (round fz): a caller without
     *   `borrowers:view` gets `new_hard_flags_30d` null and no member listed
     *   for a flag alone. With it, every member they can see is one whose
     *   details they may read (CreditScope::seesMemberDetails()).
     * - Alerts: the last 30 days, newest first, at most 100.
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
                    ->whereIn('borrower_id', $this->scope->memberIds($user))
                    ->where(fn ($q) => $q->whereNull('loan_id')->orWhereIn('loan_id', $this->scope->loanIds($user)))
                    ->count(),
            ],
            'affected_total' => (clone $affected)->count(),
            'affected_borrowers' => $affected
                ->with(['borrower:'.implode(',', CreditScope::MEMBER_COLUMNS), 'borrower.branch:id,name'])
                ->orderByRaw('credit_score_members.score IS NULL')
                ->orderBy('credit_score_members.score')
                ->orderBy('credit_score_members.borrower_id')
                ->limit(self::MONITORING_CAP)
                ->get()
                ->map(fn (CreditScoreMember $current) => $this->presenter->row($current, $user))
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
}
