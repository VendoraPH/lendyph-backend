<?php

namespace App\Services\Linda\Answers;

use App\Models\CreditScore;
use App\Models\User;
use App\Services\CreditScoring\Api\CreditScope;
use App\Services\CreditScoring\Api\Portfolio;
use App\Services\CreditScoring\Api\ScorecardEditor;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaText;
use App\Services\Linda\LindaWording as W;

/**
 * "How many members are high risk?", "Which members are due for review?",
 * "What are the score bands?" (finish run round 2, decision 12): the Credit
 * Scoring dashboard's own figures as of today, for the caller's branches or
 * the one named.
 *
 * - Members by risk level: current scores with a score, by their stored risk
 *   level (GET /credit-scoring/dashboard `risk_distribution`, and a branch's
 *   `branch_risk` row), plus the members with too little history to score
 *   (`insufficient_data_count`).
 * - Members due for review: Portfolio::dueForReview(), the dashboard's
 *   "Scores Due for Review", narrowed to a named branch. Without
 *   `borrowers:view` open hard flags are left out, as on the dashboard, and
 *   the answer says so.
 * - The score bands: the current scorecard revision's (ScorecardEditor),
 *   only with `credit_scoring:settings` as well, which the scorecard API
 *   needs (GET /credit-scoring/scorecard-config).
 * - For head office, each branch's counts, by the member's own branch, as
 *   the dashboard's branch table.
 *
 * Counts only: never a member's name, code or score, and no average (one
 * member's average is their score). Only the counts are a stats block, so an
 * open question's pack (LindaFacts) carries nothing else. It needs
 * `credit_scoring:view`, as the dashboard does.
 */
final class CreditScoringSummaryAnswer extends Answer
{
    /** @var array<string, string> each risk level in the dashboard's words, very low to very high */
    private const LEVELS = [
        'very_low' => 'Very Low Risk',
        'low' => 'Low Risk',
        'moderate' => 'Moderate Risk',
        'elevated' => 'Elevated Risk',
        'high' => 'High Risk',
        'very_high' => 'Very High Risk',
    ];

    /** The highest score. */
    private const MAX_SCORE = 100;

    /** Words that ask about the bands themselves. */
    private const BAND_WORDS = ['band', 'bands', 'range', 'ranges', 'threshold', 'thresholds', 'cutoff', 'cutoffs', 'mean', 'means', 'meaning', 'sabihin', 'pasabot', 'kahulugan'];

    /** Words that ask about the members due for review. */
    private const REVIEW_WORDS = ['review', 'reviewed', 'ireview'];

    public function __construct(
        private readonly CreditScope $scores,
        private readonly Portfolio $portfolio,
        private readonly ScorecardEditor $scorecard,
    ) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::CreditScoringSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('credit_scoring:view');
    }

    protected function subject(): string
    {
        return 'credit scoring figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $levels = $this->levels($user, $scope->branchId);
        $scored = $this->scores->currentScores($user, $scope->branchId)
            ->where('credit_score_members.status', CreditScore::STATUS_SCORED)
            ->count();
        $insufficient = $this->scores->currentScores($user, $scope->branchId)
            ->where('credit_score_members.status', CreditScore::STATUS_INSUFFICIENT_DATA)
            ->count();
        $due = $this->portfolio->dueForReview($user)
            ->when($scope->branchId !== null, fn ($q) => $q->where('borrowers.branch_id', $scope->branchId))
            ->count();
        $bands = $user->can('credit_scoring:settings') ? $this->bands() : [];
        $byBranch = $scope->branchId === null ? $this->byBranch($user) : [];

        $words = LindaText::words(LindaText::clean((string) $question));
        $parts = [self::levelsText($levels, $scored, $insufficient, $scope), self::reviewText($due, $user->can('borrowers:view'))];

        // A question about the review leads with it; the counts follow.
        if (array_intersect($words, self::REVIEW_WORDS) !== []) {
            $parts = array_reverse($parts);
        }

        $answer = W::asOfToday().', '.$parts[0].' '.self::sentence($parts[1]);

        if ($bands !== [] && array_intersect($words, self::BAND_WORDS) !== []) {
            $answer = self::bandsSentence($bands).' '.$answer;
        }

        if (count($byBranch) > 1) {
            $answer .= ' Each branch\'s counts are listed below.';
        }

        if ($period !== null) {
            $answer .= ' '.W::todayOnly();
        }

        $blocks = [LindaReply::stats([
            LindaReply::number('Scored Members', $scored),
            ...array_map(fn (string $level) => LindaReply::number(self::LEVELS[$level], $levels[$level]), array_keys(self::LEVELS)),
            LindaReply::number('Insufficient Data', $insufficient),
            LindaReply::number('Scores Due for Review', $due),
        ], W::asOfToday())];

        if ($bands !== []) {
            $blocks[] = LindaReply::list('Score bands', array_map(fn (array $band): array => [
                'title' => $band['label'],
                'subtitle' => $band['range'],
                'fields' => [],
            ], $bands));
        }

        if (count($byBranch) > 1) {
            $blocks[] = LindaReply::list('By branch', $byBranch);
        }

        return $this->reply($answer, $blocks, LindaLinks::for($user, 'credit_scoring'));
    }

    /**
     * The scored members by risk level, every level once (the dashboard's
     * `risk_distribution`).
     *
     * @return array<string, int>
     */
    private function levels(User $user, ?int $branchId): array
    {
        $counts = $this->scores->currentScores($user, $branchId)
            ->where('credit_score_members.status', CreditScore::STATUS_SCORED)
            ->reorder()
            ->select('credit_score_members.risk_level')
            ->selectRaw('COUNT(*) AS members')
            ->groupBy('credit_score_members.risk_level')
            ->toBase()
            ->pluck('members', 'risk_level');

        return array_map(fn (string $level) => (int) ($counts[$level] ?? 0), array_combine(array_keys(self::LEVELS), array_keys(self::LEVELS)));
    }

    /**
     * Each branch's scored members by risk level, by the member's own branch
     * and branch name (the dashboard's `branch_risk`), as list rows.
     *
     * @return list<array{title: string, fields: list<array<string, mixed>>}>
     */
    private function byBranch(User $user): array
    {
        $rows = $this->scores->currentScores($user)
            ->where('credit_score_members.status', CreditScore::STATUS_SCORED)
            ->leftJoin('branches', 'branches.id', '=', 'borrowers.branch_id')
            ->select('borrowers.branch_id', 'branches.name as branch_name', 'credit_score_members.risk_level')
            ->selectRaw('COUNT(*) AS members')
            ->groupBy('borrowers.branch_id', 'branches.name', 'credit_score_members.risk_level')
            ->toBase()
            ->get();

        return $rows->groupBy(fn (object $row) => (string) $row->branch_id)
            ->map(function ($levels): array {
                $counts = $levels->pluck('members', 'risk_level');

                return [
                    'title' => $levels->first()->branch_name ?? 'No branch',
                    'fields' => [
                        LindaReply::number('Scored Members', (int) $levels->sum('members')),
                        ...array_map(fn (string $level) => LindaReply::number(self::LEVELS[$level], (int) ($counts[$level] ?? 0)), array_keys(self::LEVELS)),
                    ],
                ];
            })
            ->sortBy(fn (array $row) => [$row['title'] === 'No branch' ? 1 : 0, $row['title']])
            ->values()
            ->all();
    }

    /**
     * The current revision's bands, highest first, each with its score range
     * ("90 to 100"): from its minimum to one below the next band's.
     *
     * @return list<array{label: string, range: string}>
     */
    private function bands(): array
    {
        $bands = $this->scorecard->config()['risk_bands'];
        usort($bands, fn (array $a, array $b) => $b['min_score'] <=> $a['min_score']);

        $top = self::MAX_SCORE;
        $out = [];
        foreach ($bands as $band) {
            $min = (int) $band['min_score'];
            $out[] = ['label' => (string) $band['label'], 'range' => $min >= $top ? (string) $min : $min.' to '.$top];
            $top = $min - 1;
        }

        return $out;
    }

    /**
     * "5 members have a credit score across all branches: 1 Very Low Risk, …
     * and 1 Very High Risk. 1 more member has too little history to score."
     *
     * @param  array<string, int>  $levels
     */
    private static function levelsText(array $levels, int $scored, int $insufficient, LindaScope $scope): string
    {
        $text = $scored === 0
            ? 'no member has a credit score yet '.W::forScope($scope).'.'
            : W::count($scored, 'member').' '.($scored === 1 ? 'has' : 'have').' a credit score '.W::forScope($scope).': '
                .W::listOf(array_map(fn (string $level) => number_format($levels[$level]).' '.self::LEVELS[$level], array_keys(self::LEVELS))).'.';

        if ($insufficient > 0) {
            $text .= ' '.W::count($insufficient, 'more member', 'more members').' '.($insufficient === 1 ? 'has' : 'have').' too little history to score.';
        }

        return $text;
    }

    /** "2 members are due for review: an open hard flag, or …" */
    private static function reviewText(int $due, bool $seesFlags): string
    {
        $text = W::count($due, 'member').' '.($due === 1 ? 'is' : 'are').' due for review: '
            .($seesFlags ? 'an open hard flag, or a score at least one band below the previous one.' : 'a score at least one band below the previous one.');

        return $seesFlags ? $text : $text.' Open hard flags aren\'t counted, since they are member details you can\'t view.';
    }

    /** @param  list<array{label: string, range: string}>  $bands */
    private static function bandsSentence(array $bands): string
    {
        return 'The score bands are '.W::listOf(array_map(fn (array $band) => $band['label'].' '.$band['range'], $bands)).'.';
    }
}
