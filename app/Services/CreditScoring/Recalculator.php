<?php

namespace App\Services\CreditScoring;

use App\Models\Borrower;
use App\Models\CreditApplicationProfile;
use App\Models\CreditRiskAlert;
use App\Models\CreditScore;
use App\Models\CreditScoreDirtyMember;
use App\Models\CreditScoreMember;
use App\Models\CreditScoringSetting;
use App\Models\Loan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Scores members and loan applications, and keeps the history.
 *
 * A history row (`credit_scores`) is written only when the status, score,
 * band, confidence or revision differs from the last one; otherwise only the
 * member's `checked_at` moves. A new score that dropped by the settings'
 * decline points (10) or more, or moved into a high band, writes an alert.
 * Policy flags are kept in step on every run.
 *
 * Advisory only: it writes the credit scoring tables and nothing else, and
 * never a loan. A dry run calculates and compares, and writes nothing.
 *
 * Each member is scored under a per-member cache lock, so the nightly run
 * and the minute job never both write the same change.
 */
class Recalculator
{
    public const HIGH_RISK_BANDS = ['high', 'very_high'];

    /** Loan statuses whose application is still being decided. */
    public const OPEN_APPLICATION_STATUSES = ['draft', 'for_review', 'approved'];

    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 30;

    public function __construct(
        private readonly Scorer $scorer,
        private readonly PolicyFlags $policyFlags,
    ) {}

    /**
     * The member's own score.
     *
     * @return array{borrower_id: int, changed: bool, status: string, score: int|null, previous_score: int|null, risk_level: string|null, confidence: string, credit_score_id: int|null}
     */
    public function scoreMember(int $borrowerId, string $reason, bool $dryRun = false, ?Carbon $asOf = null): array
    {
        $run = function () use ($borrowerId, $reason, $dryRun, $asOf) {
            [$settings, $table] = $this->rules();
            $data = MemberCreditData::load($borrowerId, null, $asOf);
            $result = $this->scorer->score($data, $settings, $table);
            $current = CreditScoreMember::query()->with('creditScore:id,revision_id')->where('borrower_id', $borrowerId)->first();
            $changed = $this->differs($result, $current?->creditScore, $current);

            if ($dryRun) {
                return $this->outcome($borrowerId, $changed, $result, $current?->score, $current?->credit_score_id);
            }

            return DB::transaction(function () use ($borrowerId, $reason, $data, $result, $current, $changed, $settings) {
                $now = Carbon::now();
                $scoreId = $current?->credit_score_id;

                if ($changed) {
                    $score = $this->writeScore($borrowerId, null, CreditScore::CONTEXT_NIGHTLY, $result, $reason, $current?->score, $now);
                    $scoreId = $score->id;
                    $this->writeAlert($data, $score, $current, $settings);
                }

                CreditScoreMember::query()->updateOrCreate(['borrower_id' => $borrowerId], array_merge([
                    'branch_id' => $data->member->branch_id,
                    'has_active_loan' => $result['has_active_loan'],
                    'past_due' => $result['past_due'],
                    'checked_at' => $now,
                ], $changed ? [
                    'credit_score_id' => $scoreId,
                    'previous_credit_score_id' => $current?->credit_score_id,
                    'status' => $result['status'],
                    'score_type' => $result['score_type'],
                    'score' => $result['score'],
                    'risk_level' => $result['risk_level'],
                    'confidence' => $result['confidence'],
                    'previous_score' => $current?->score,
                    'trend' => $this->trend($current?->score, $result['score']),
                    'calculated_at' => $now,
                ] : []));

                $this->policyFlags->sync($borrowerId, null, $result['flags'], $now);

                return $this->outcome($borrowerId, $changed, $result, $current?->score, $scoreId);
            });
        };

        return $dryRun ? $run() : Cache::lock($this->lockKey($borrowerId), self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $run);
    }

    /**
     * A score for one loan application, with that loan's proposed payment and
     * application details. A new row only when it differs from the loan's
     * last assessment.
     *
     * @return array{borrower_id: int, changed: bool, status: string, score: int|null, previous_score: int|null, risk_level: string|null, confidence: string, credit_score_id: int|null}
     */
    public function assessLoan(Loan $loan, string $reason, bool $dryRun = false, ?Carbon $asOf = null): array
    {
        $borrowerId = (int) $loan->borrower_id;

        $run = function () use ($loan, $borrowerId, $reason, $dryRun, $asOf) {
            [$settings, $table] = $this->rules();
            $data = MemberCreditData::load($borrowerId, $loan, $asOf);
            $result = $this->scorer->score($data, $settings, $table);
            $last = CreditScore::query()
                ->where('loan_id', $loan->id)
                ->where('context', CreditScore::CONTEXT_ASSESSMENT)
                ->orderByDesc('id')
                ->first();
            $changed = $this->differs($result, $last, $last);

            if ($dryRun) {
                return $this->outcome($borrowerId, $changed, $result, $last?->score, $last?->id);
            }

            return DB::transaction(function () use ($loan, $borrowerId, $reason, $result, $last, $changed) {
                $now = Carbon::now();
                $scoreId = $last?->id;

                if ($changed) {
                    $scoreId = $this->writeScore($borrowerId, (int) $loan->id, CreditScore::CONTEXT_ASSESSMENT, $result, $reason, $last?->score, $now)->id;
                }

                $this->policyFlags->sync($borrowerId, (int) $loan->id, $result['flags'], $now);

                return $this->outcome($borrowerId, $changed, $result, $last?->score, $scoreId);
            });
        };

        return $dryRun ? $run() : Cache::lock($this->lockKey($borrowerId), self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $run);
    }

    /**
     * The member's applications still being decided that have been assessed
     * or carry application details: these are re-assessed when the member
     * changes and every night.
     *
     * @return list<Loan>
     */
    public function openAssessedLoans(int $borrowerId): array
    {
        return Loan::query()
            ->where('borrower_id', $borrowerId)
            ->whereIn('status', self::OPEN_APPLICATION_STATUSES)
            ->where(fn ($q) => $q
                ->whereIn('id', CreditScore::query()->where('context', CreditScore::CONTEXT_ASSESSMENT)->whereNotNull('loan_id')->select('loan_id'))
                ->orWhereIn('id', CreditApplicationProfile::query()->select('loan_id')))
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Whether the member is someone the engine scores: a member, not a
     * pending or rejected registration.
     */
    public function isScorable(int $borrowerId): bool
    {
        return DB::table('borrowers')
            ->where('id', $borrowerId)
            ->whereNotIn('status', Borrower::NON_MEMBER_STATUSES)
            ->exists();
    }

    /**
     * Drop the member's change flag if nothing flagged it again after
     * `$before`.
     */
    public function clearDirty(int $borrowerId, Carbon $before): void
    {
        CreditScoreDirtyMember::query()
            ->where('borrower_id', $borrowerId)
            ->where('flagged_at', '<=', $before)
            ->delete();
    }

    /**
     * @return array{0: CreditScoringSetting, 1: FactorTable}
     */
    private function rules(): array
    {
        $settings = CreditScoringSetting::current();

        return [$settings, FactorTable::fromRevision($settings->currentRevision()->firstOrFail())];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function differs(array $result, ?CreditScore $lastScore, CreditScore|CreditScoreMember|null $last): bool
    {
        if ($last === null || $lastScore === null) {
            return true;
        }

        return $last->status !== $result['status']
            || $last->score !== $result['score']
            || $last->risk_level !== $result['risk_level']
            || $last->confidence !== $result['confidence']
            || (int) $lastScore->revision_id !== $result['revision_id'];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeScore(int $borrowerId, ?int $loanId, string $context, array $result, string $reason, ?int $previousScore, Carbon $now): CreditScore
    {
        $score = CreditScore::create([
            'borrower_id' => $borrowerId,
            'loan_id' => $loanId,
            'revision_id' => $result['revision_id'],
            'revision_version' => $result['revision_version'],
            'context' => $context,
            'score_type' => $result['score_type'],
            'status' => $result['status'],
            'score' => $result['score'],
            'risk_level' => $result['risk_level'],
            'confidence' => $result['confidence'],
            'confidence_reason' => mb_substr($result['confidence_reason'], 0, 500),
            'limited_history' => $result['limited_history'],
            'category_scores' => $result['categories'],
            'capacity' => $result['capacity'],
            'data_used' => $result['data_used'],
            'recommendation' => $result['recommendation'],
            'recalculation_reason' => mb_substr($reason, 0, 255),
            'previous_score' => $previousScore,
            'calculated_at' => $now,
        ]);

        foreach ($result['factors'] as $factor) {
            $score->factors()->create($factor);
        }

        return $score;
    }

    private function writeAlert(MemberCreditData $data, CreditScore $score, ?CreditScoreMember $previous, CreditScoringSetting $settings): void
    {
        if ($score->score === null) {
            return;
        }

        $previousScore = $previous?->score;
        $drop = $previousScore === null ? 0 : $previousScore - $score->score;
        $intoHighBand = in_array($score->risk_level, self::HIGH_RISK_BANDS, true)
            && ! in_array($previous?->risk_level, self::HIGH_RISK_BANDS, true);
        $name = $data->fullName();

        if ($previousScore !== null && $drop >= $settings->decline_alert_points) {
            CreditRiskAlert::create([
                'borrower_id' => $score->borrower_id,
                'credit_score_id' => $score->id,
                'kind' => 'score_decline',
                'previous_score' => $previousScore,
                'current_score' => $score->score,
                'previous_risk_level' => $previous->risk_level,
                'current_risk_level' => $score->risk_level,
                'severity' => in_array($score->risk_level, self::HIGH_RISK_BANDS, true) || $drop >= 2 * $settings->decline_alert_points ? 'critical' : 'warning',
                'message' => mb_substr("{$name}'s credit score decreased from {$previousScore} to {$score->score}.", 0, 255),
            ]);

            return;
        }

        if ($intoHighBand) {
            CreditRiskAlert::create([
                'borrower_id' => $score->borrower_id,
                'credit_score_id' => $score->id,
                'kind' => 'high_risk',
                'previous_score' => $previousScore,
                'current_score' => $score->score,
                'previous_risk_level' => $previous?->risk_level,
                'current_risk_level' => $score->risk_level,
                'severity' => 'critical',
                'message' => mb_substr($previousScore === null
                    ? "{$name}'s credit score, {$score->score}, is in a high risk band."
                    : "{$name}'s credit score moved into a high risk band, from {$previousScore} to {$score->score}.", 0, 255),
            ]);
        }
    }

    private function trend(?int $previous, ?int $current): string
    {
        if ($previous === null || $current === null || $previous === $current) {
            return 'flat';
        }

        return $current > $previous ? 'up' : 'down';
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{borrower_id: int, changed: bool, status: string, score: int|null, previous_score: int|null, risk_level: string|null, confidence: string, credit_score_id: int|null}
     */
    private function outcome(int $borrowerId, bool $changed, array $result, ?int $previousScore, ?int $scoreId): array
    {
        return [
            'borrower_id' => $borrowerId,
            'changed' => $changed,
            'status' => $result['status'],
            'score' => $result['score'],
            'previous_score' => $previousScore,
            'risk_level' => $result['risk_level'],
            'confidence' => $result['confidence'],
            'credit_score_id' => $scoreId,
        ];
    }

    private function lockKey(int $borrowerId): string
    {
        return "credit-scoring:member:{$borrowerId}";
    }
}
