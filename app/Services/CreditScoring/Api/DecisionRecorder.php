<?php

namespace App\Services\CreditScoring\Api;

use App\Models\Borrower;
use App\Models\CreditDecision;
use App\Models\CreditScore;
use App\Models\Loan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Records a person's credit decision beside the system's advice. Append-only
 * (the model refuses updates and deletes), with snapshots of the score and
 * recommendation it was made against. It never approves, declines or changes
 * a loan: it writes one credit_decisions row (and its audit row) only.
 */
final class DecisionRecorder
{
    /**
     * @param  array{credit_score_id: int|string, loan_id?: int|string|null, decision: string, reason: string, remarks?: string|null}  $input
     */
    public function record(User $user, Borrower $member, array $input): CreditDecision
    {
        $score = CreditScore::query()
            ->whereKey((int) $input['credit_score_id'])
            ->where('borrower_id', $member->id)
            ->first();

        if ($score === null) {
            throw ValidationException::withMessages(['credit_score_id' => 'The score must be one of this member\'s own scores.']);
        }

        $loanId = isset($input['loan_id']) ? (int) $input['loan_id'] : null;

        if ($score->context === CreditScore::CONTEXT_ASSESSMENT && $loanId !== (int) $score->loan_id) {
            throw ValidationException::withMessages(['loan_id' => 'A decision on a loan assessment must name that assessment\'s loan.']);
        }

        if ($loanId !== null && ! Loan::query()->visibleTo($user)->whereKey($loanId)->where('borrower_id', $member->id)->exists()) {
            throw ValidationException::withMessages(['loan_id' => 'The loan must be one of this member\'s loans.']);
        }

        return CreditDecision::query()->create([
            'borrower_id' => $member->id,
            'loan_id' => $loanId,
            'credit_score_id' => $score->id,
            'decision' => $input['decision'],
            'reason' => $input['reason'],
            'remarks' => $input['remarks'] ?? null,
            'score_snapshot' => [
                'status' => $score->status,
                'score' => $score->score,
                'risk_level' => $score->risk_level,
                'confidence' => $score->confidence,
                'confidence_reason' => $score->confidence_reason,
                'score_type' => $score->score_type,
                'context' => $score->context,
                'loan_id' => $score->loan_id,
                'limited_history' => $score->limited_history,
                'calculated_at' => $score->calculated_at?->toIso8601String(),
                'capacity' => $score->capacity,
            ],
            'recommendation_snapshot' => $score->recommendation,
            'revision_version' => $score->revision_version,
            'decided_by' => $user->id,
            'decided_at' => Carbon::now(),
        ])->setRelation('decidedByUser', $user);
    }
}
