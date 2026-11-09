<?php

namespace App\Services\CreditScoring\Api;

use App\Models\Borrower;
use App\Models\CreditDecision;
use App\Models\CreditPolicyFlag;
use App\Models\CreditRiskAlert;
use App\Models\CreditScore;
use App\Models\CreditScorecardRevision;
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
 * Built for one caller:
 * - A caller who may not read the member's details (`borrowers:view` and the
 *   member's branch, round fz; CreditScope::seesMemberDetails()) gets a
 *   summary only (`details_hidden: true`): the score, band, version, time and
 *   previous score, and one generic recommendation. `category_breakdown`,
 *   `factors` and `factors_without_data` are empty; `confidence`,
 *   `confidence_reason`, `capacity` and `data_used` are left out. Nothing in
 *   it can be read back to a factor, flag or figure.
 * - Everyone else gets the whole score (`details_hidden: false`). A score's
 *   data drops what they can't see (CreditScope::dataUsed()), and a
 *   possible-duplicate flag reads its rule's description without
 *   `branches:view_all`, since its detail names the other member. A caller
 *   who may not read the member's financial figures
 *   (CreditScope::seesFinancials()) gets no capacity block, a `data_used`
 *   cut to its non-money keys, generic lines on the factors and flags that
 *   print income, debt or amounts owed, and a recommendation without the
 *   suggested maximum's amount; `financials_hidden` says so.
 */
final class Presenter
{
    public const NOT_SCORED = 'not_scored';

    private const GENERIC_FLAG_RULES = ['possible_duplicate'];

    /** Flags whose detail prints the member's debt figures. */
    private const FINANCIAL_FLAG_RULES = ['debt_service_above_caution', 'too_many_active_loans'];

    /** The `data_used` keys a caller who may not read the figures still gets: counts and ids, no money. */
    private const NON_MONEY_DATA_KEYS = [
        'as_of', 'engine_version', 'application_loan_id', 'loan_ids', 'branch_ids', 'other_branch_loans',
        'matured_periods', 'known_periods', 'on_time_periods', 'late_periods', 'legacy_periods',
        'worst_days_late_24m', 'days_past_due', 'completed_loans', 'clean_completed_loans',
        'defaulted_loans', 'written_off_loans', 'restructured_loans', 'active_loans', 'has_valid_id',
    ];

    /** Factor lines that print the member's income or debt, and their stand-ins. */
    private const FINANCIAL_FACTOR_DETAILS = [
        'debt_service_ratio' => 'Debt payments against monthly income. The figures are shown to staff who can read the member\'s details and loans.',
        'payment_cover' => 'Disposable income against the proposed payment. The figures are shown to staff who can read the member\'s details and loans.',
        'debt_to_income' => 'Debt against annual income. The figures are shown to staff who can read the member\'s details and loans.',
        'active_loans' => 'Active loans, outside lenders included. The figures are shown to staff who can read the member\'s details and loans.',
        'past_due_amount' => 'Past due amount against the amount outstanding. The figures are shown to staff who can read the member\'s details and loans.',
    ];

    /** The summary's recommendation while the member has an open hard flag. */
    public const MANUAL_REVIEW = 'Manual review required.';

    /** The summary's recommendation for a score without enough data. */
    private const INSUFFICIENT_DATA = 'Not enough data to score. Review the member\'s application and history manually.';

    /** Request attribute prefix for the per-request memos (as BranchAccess::for() keeps its own). */
    private const MEMO_PREFIX = 'credit_scoring.presenter.';

    public function __construct(private readonly CreditScope $scope) {}

    /**
     * The `CreditScore` shape, for a member's current score or a loan's
     * assessment, or `not_scored` before the member's first score.
     *
     * @return array<string, mixed>
     */
    public function score(User $viewer, Borrower $member, ?CreditScore $score): array
    {
        $details = $this->scope->seesMemberDetails($viewer, $member);

        if ($score === null) {
            return $this->notScored($member, $details);
        }

        if (! $details) {
            return $this->summary($member, $score);
        }

        $financial = $this->scope->seesFinancials($viewer, $member, $score);
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
                'detail' => ! $financial && isset(self::FINANCIAL_FACTOR_DETAILS[$factor->factor_key])
                    ? self::FINANCIAL_FACTOR_DETAILS[$factor->factor_key]
                    : $factor->detail,
                'impact' => $factor->impact,
                'category' => $labels[$factor->category_key] ?? $factor->category_key,
            ])->values()->all(),
            'recommendation' => $financial ? $score->recommendation : self::withoutAmounts($score->recommendation),
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
            'capacity' => $financial ? $score->capacity : null,
            'data_used' => $this->dataUsed($viewer, $score, $financial),
            'financials_hidden' => ! $financial,
            'details_hidden' => false,
        ];
    }

    /**
     * The summary for a caller who may not read the member's details.
     *
     * @return array<string, mixed>
     */
    private function summary(Borrower $member, CreditScore $score): array
    {
        return [
            'id' => (int) $score->id,
            'borrower_id' => (int) $member->id,
            'borrower_name' => $member->full_name,
            'borrower_code' => $member->borrower_code,
            'score_type' => $score->score_type,
            'score' => $score->score,
            'risk_level' => $score->risk_level,
            'model_version' => $score->revision_version,
            'category_breakdown' => [],
            'factors' => [],
            'recommendation' => $this->genericRecommendation($score),
            'calculated_at' => self::time($score->calculated_at),
            'status' => $score->status,
            'limited_history' => (bool) $score->limited_history,
            'validated' => (bool) $score->revision?->validated,
            'previous_score' => $score->previous_score,
            'context' => $score->context,
            'loan_id' => $score->loan_id === null ? null : (int) $score->loan_id,
            'factors_without_data' => [],
            'financials_hidden' => true,
            'details_hidden' => true,
        ];
    }

    /**
     * One generic sentence: "Manual review required." while the member (or,
     * for an assessment, its loan) has an open hard flag, else the band's own
     * advice from the score's revision, or the insufficient-data advice.
     */
    private function genericRecommendation(CreditScore $score): string
    {
        $hardFlag = CreditPolicyFlag::query()
            ->open()
            ->where('borrower_id', $score->borrower_id)
            ->where('type', CreditPolicyFlag::HARD)
            ->where(fn ($q) => $q->whereNull('loan_id')->when($score->loan_id !== null, fn ($q) => $q->orWhere('loan_id', $score->loan_id)))
            ->exists();

        if ($hardFlag) {
            return self::MANUAL_REVIEW;
        }

        if ($score->risk_level === null) {
            return self::INSUFFICIENT_DATA;
        }

        $bands = $this->memo(null, "risk_bands.{$score->revision_id}", fn () => collect(
            CreditScorecardRevision::query()->whereKey($score->revision_id)->value('risk_bands') ?? [],
        )->pluck('recommendation', 'key')->all());

        return (string) ($bands[$score->risk_level] ?? self::INSUFFICIENT_DATA);
    }

    /**
     * The score's capacity block, or null for a caller who may not read the
     * member's financial figures.
     *
     * @return array<string, mixed>|null
     */
    public function capacity(User $viewer, Borrower $member, ?CreditScore $score): ?array
    {
        return $score !== null && $this->scope->seesFinancials($viewer, $member, $score) ? $score->capacity : null;
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
    public function flag(User $viewer, CreditPolicyFlag $flag, Borrower $member): array
    {
        return [
            'id' => (int) $flag->id,
            'type' => $flag->type,
            'label' => $flag->label,
            'detail' => $this->flagDetail($viewer, $flag, $member),
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
    private function notScored(Borrower $member, bool $details): array
    {
        $shape = [
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
            'financials_hidden' => ! $details,
            'details_hidden' => ! $details,
        ];

        return $details ? $shape : array_diff_key($shape, array_flip(['confidence', 'confidence_reason', 'capacity', 'data_used']));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dataUsed(User $viewer, CreditScore $score, bool $financial): ?array
    {
        $data = $this->scope->dataUsed($viewer, $score->data_used);

        return $data === null || $financial ? $data : array_intersect_key($data, array_flip(self::NON_MONEY_DATA_KEYS));
    }

    /**
     * The recommendation without the suggested maximum payment's amount.
     */
    private static function withoutAmounts(string $recommendation): string
    {
        return (string) preg_replace('/(The proposed payment is above the suggested maximum payment) of ₱[\d,]+\.\d{2}\./u', '$1.', $recommendation);
    }

    /**
     * A flag's stored detail, or its rule's description when the detail
     * names another member (without `branches:view_all`) or prints debt
     * figures the viewer may not read. A loan's own flag is read against
     * the member gate (its loan is visible, or it isn't listed); a member
     * flag against the details behind the member's current score.
     */
    private function flagDetail(User $viewer, CreditPolicyFlag $flag, Borrower $member): string
    {
        $generic = match (true) {
            in_array($flag->rule_key, self::GENERIC_FLAG_RULES, true) => ! $this->scope->seesEveryBranch($viewer),
            in_array($flag->rule_key, self::FINANCIAL_FLAG_RULES, true) => ! ($flag->loan_id !== null
                ? $this->scope->seesFinancials($viewer, $member, null)
                : $this->seesMemberFinancials($viewer, $member)),
            default => false,
        };

        if (! $generic) {
            return $flag->detail;
        }

        $table = $this->memo(null, 'policy_rules', fn () => FactorTable::fromRevision(CreditScoringSetting::current()->currentRevision()->firstOrFail()));

        return (string) ($table->policyRule($flag->rule_key)['description'] ?? $flag->label);
    }

    private function seesMemberFinancials(User $viewer, Borrower $member): bool
    {
        return $this->memo($viewer, "member_financials.{$member->getKey()}", function () use ($viewer, $member) {
            $profileId = CreditScoreMember::query()->where('borrower_id', $member->id)->value('flags_profile_id');

            return $this->scope->seesFinancialsFrom($viewer, $member, $profileId === null ? null : (int) $profileId);
        });
    }

    /**
     * A value kept for the current HTTP request only, so a long-lived process
     * never answers one request, or one caller, from another's memo. Keyed by
     * the viewer when there is one, and kept only for a saved user, as
     * BranchAccess::for() does. In the console (where artisan binds a
     * request of its own) it is worked out each time, except under tests.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $resolve
     * @return TValue
     */
    private function memo(?User $viewer, string $key, callable $resolve): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        $consoleOnly = app()->runningInConsole() && ! app()->runningUnitTests();

        if ($request === null || $consoleOnly || ($viewer !== null && ! $viewer->exists)) {
            return $resolve();
        }

        $key = self::MEMO_PREFIX.($viewer === null ? '' : "viewer.{$viewer->getKey()}.").$key;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $resolve());
        }

        return $request->attributes->get($key);
    }
}
