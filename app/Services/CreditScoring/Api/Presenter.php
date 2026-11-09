<?php

namespace App\Services\CreditScoring\Api;

use App\Models\Borrower;
use App\Models\CreditDecision;
use App\Models\CreditPolicyFlag;
use App\Models\CreditRiskAlert;
use App\Models\CreditScore;
use App\Models\CreditScoreMember;
use App\Models\CreditScoringSetting;
use App\Models\User;
use App\Services\CreditScoring\FactorTable;
use Carbon\CarbonInterface;

/**
 * The Credit Scoring API's shapes, exactly the frontend's
 * src/types/credit-scoring.ts plus the additions this API makes. Members are
 * named by id, name and code only (round fz); times are ISO 8601 with their
 * offset; arrays are arrays, never null.
 *
 * Built for one caller: a score's data drops what they can't see
 * (CreditScope::dataUsed()), and a possible-duplicate flag reads its rule's
 * description for a caller without `branches:view_all`, since its detail
 * names the other member.
 */
final class Presenter
{
    public const NOT_SCORED = 'not_scored';

    private const GENERIC_FLAG_RULES = ['possible_duplicate'];

    private ?FactorTable $currentTable = null;

    public function __construct(private readonly CreditScope $scope) {}

    /**
     * The `CreditScore` shape, for a member's current score or a loan's
     * assessment, or `not_scored` before the member's first score.
     *
     * @return array<string, mixed>
     */
    public function score(User $viewer, Borrower $member, ?CreditScore $score): array
    {
        if ($score === null) {
            return $this->notScored($member);
        }

        $categories = $score->category_scores ?? [];
        $labels = array_column($categories, 'label', 'key');
        $factors = $score->relationLoaded('factors') ? $score->factors : $score->factors()->get();

        return [
            'id' => (int) $score->id,
            'borrower_id' => (int) $member->id,
            'borrower_name' => $member->full_name,
            'borrower_code' => $member->borrower_code,
            'score_type' => $score->score_type,
            'score' => $score->score,
            'risk_level' => $score->risk_level,
            'confidence' => $score->confidence,
            'model_version' => $score->revision_version,
            'category_breakdown' => array_map(fn (array $category) => [
                'key' => $category['key'],
                'category' => $category['label'],
                'weight_percent' => $category['weight_percent'],
                'points_earned' => (int) $category['points_earned'],
                'points_possible' => (int) $category['points_possible'],
                'has_data' => (bool) $category['has_data'],
            ], array_values($categories)),
            'factors' => $factors->where('has_data', true)->map(fn ($factor) => [
                'id' => (int) $factor->id,
                'label' => $factor->label,
                'detail' => $factor->detail,
                'impact' => $factor->impact,
                'category' => $labels[$factor->category_key] ?? $factor->category_key,
            ])->values()->all(),
            'recommendation' => $score->recommendation,
            'calculated_at' => self::time($score->calculated_at),
            'status' => $score->status,
            'limited_history' => (bool) $score->limited_history,
            'confidence_reason' => $score->confidence_reason,
            'validated' => (bool) $score->revision?->validated,
            'previous_score' => $score->previous_score,
            'context' => $score->context,
            'loan_id' => $score->loan_id === null ? null : (int) $score->loan_id,
            'factors_without_data' => $factors->where('has_data', false)->map(fn ($factor) => [
                'label' => $factor->label,
                'category' => $labels[$factor->category_key] ?? $factor->category_key,
            ])->values()->all(),
            'capacity' => $score->capacity,
            'data_used' => $this->scope->dataUsed($viewer, $score->data_used),
        ];
    }

    /**
     * The `BorrowerScoreRow` shape, from a member's current score with the
     * member's own (current) name and branch loaded.
     *
     * @return array<string, mixed>
     */
    public function row(CreditScoreMember $current): array
    {
        $member = $current->borrower;

        return [
            'borrower_id' => (int) $current->borrower_id,
            'borrower_name' => $member?->full_name,
            'borrower_code' => $member?->borrower_code,
            'branch_id' => $member?->branch_id === null ? null : (int) $member->branch_id,
            'branch_name' => $member?->branch?->name,
            'score' => $current->score,
            'risk_level' => $current->risk_level,
            'score_trend' => $current->trend,
            'has_active_loan' => (bool) $current->has_active_loan,
            'past_due' => (bool) $current->past_due,
            'calculated_at' => self::time($current->calculated_at),
            'status' => $current->status,
        ];
    }

    /**
     * The `CreditScoreHistoryEntry` shape, with the member loaded.
     *
     * @return array<string, mixed>
     */
    public function history(CreditScore $score): array
    {
        return [
            'id' => (int) $score->id,
            'borrower_id' => (int) $score->borrower_id,
            'borrower_name' => $score->borrower?->full_name,
            'borrower_code' => $score->borrower?->borrower_code,
            'score' => $score->score,
            'risk_level' => $score->risk_level,
            'score_type' => $score->score_type,
            'model_version' => $score->revision_version,
            'calculated_at' => self::time($score->calculated_at),
            'reason' => $score->recalculation_reason,
            'status' => $score->status,
            'previous_score' => $score->previous_score,
        ];
    }

    /**
     * The `PolicyFlag` shape.
     *
     * @return array<string, mixed>
     */
    public function flag(User $viewer, CreditPolicyFlag $flag): array
    {
        return [
            'id' => (int) $flag->id,
            'type' => $flag->type,
            'label' => $flag->label,
            'detail' => $this->flagDetail($viewer, $flag),
            'triggered_at' => self::time($flag->first_triggered_at),
            'loan_id' => $flag->loan_id === null ? null : (int) $flag->loan_id,
        ];
    }

    /**
     * The `RiskAlert` shape, with the member loaded.
     *
     * @return array<string, mixed>
     */
    public function alert(CreditRiskAlert $alert): array
    {
        return [
            'id' => (int) $alert->id,
            'borrower_id' => (int) $alert->borrower_id,
            'borrower_name' => $alert->borrower?->full_name,
            'borrower_code' => $alert->borrower?->borrower_code,
            'message' => $alert->message,
            'previous_score' => $alert->previous_score,
            'current_score' => $alert->current_score,
            'severity' => $alert->severity,
            'kind' => $alert->kind,
            'created_at' => self::time($alert->created_at),
        ];
    }

    /**
     * The `CreditDecision` shape; `decided_by` is the person's name.
     *
     * @return array<string, mixed>
     */
    public function decision(CreditDecision $decision): array
    {
        return [
            'id' => (int) $decision->id,
            'borrower_id' => (int) $decision->borrower_id,
            'loan_id' => $decision->loan_id === null ? null : (int) $decision->loan_id,
            'credit_score_id' => (int) $decision->credit_score_id,
            'decision' => $decision->decision,
            'reason' => $decision->reason,
            'remarks' => $decision->remarks,
            'decided_by' => $decision->decidedByUser?->full_name ?? 'A former user',
            'decided_at' => self::time($decision->decided_at),
            'revision_version' => $decision->revision_version,
        ];
    }

    public static function time(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    private function notScored(Borrower $member): array
    {
        return [
            'id' => null,
            'borrower_id' => (int) $member->id,
            'borrower_name' => $member->full_name,
            'borrower_code' => $member->borrower_code,
            'score_type' => null,
            'score' => null,
            'risk_level' => null,
            'confidence' => null,
            'model_version' => null,
            'category_breakdown' => [],
            'factors' => [],
            'recommendation' => 'Not scored yet. Members are scored every night and when their data changes.',
            'calculated_at' => null,
            'status' => self::NOT_SCORED,
            'limited_history' => false,
            'confidence_reason' => null,
            'validated' => null,
            'previous_score' => null,
            'context' => null,
            'loan_id' => null,
            'factors_without_data' => [],
            'capacity' => null,
            'data_used' => null,
        ];
    }

    private function flagDetail(User $viewer, CreditPolicyFlag $flag): string
    {
        if (! in_array($flag->rule_key, self::GENERIC_FLAG_RULES, true) || $this->scope->seesEveryBranch($viewer)) {
            return $flag->detail;
        }

        $this->currentTable ??= FactorTable::fromRevision(CreditScoringSetting::current()->currentRevision()->firstOrFail());

        return (string) ($this->currentTable->policyRule($flag->rule_key)['description'] ?? $flag->label);
    }
}
