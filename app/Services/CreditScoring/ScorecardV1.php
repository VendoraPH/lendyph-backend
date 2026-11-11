<?php

namespace App\Services\CreditScoring;

/**
 * The first scorecard revision, "1.0", exactly as the migration seeds it.
 *
 * Frozen: revision rows are immutable and every score names the revision it
 * used, so this definition never changes. A new scorecard is a new revision
 * row (C2s saves it from the Scorecard Configuration screen), never an edit
 * here.
 *
 * The weights are the Credit Scoring task's own starting framework (35, 25,
 * 15, 10, 10 and 5 points). The stored row says they are unvalidated (not
 * yet tested against the cooperative's own default and delinquency
 * outcomes), and it stays as written; the owner reviewed the weights and risk
 * levels and kept them on 2026-11-11, recorded beside it as a
 * CreditScorecardReview (ScorecardOwnerReview), so the API reports 1.0 as
 * checked.
 *
 * Each factor maps a raw value to points through `bands`, tried in order:
 * - `at_least`: the first band whose threshold the value reaches;
 * - `at_most`: the first band whose threshold the value does not exceed;
 * - otherwise the factor's `otherwise` points.
 * `default_points`, on the defaults factor only, applies whenever the member
 * has a defaulted or written-off loan, before the bands.
 */
final class ScorecardV1
{
    public const VERSION = '1.0';

    public const ENGINE_VERSION = '1.0.0';

    public const NOTES = 'Starting weights from the Credit Scoring task. Unvalidated: not yet tested against Lendy\'s own loan outcomes.';

    /**
     * @return list<array{key: string, label: string, weight_percent: int, description: string}>
     */
    public static function categories(): array
    {
        return [
            ['key' => 'repayment', 'label' => 'Repayment Behaviour', 'weight_percent' => 35, 'description' => 'How the member has paid Lendy loans: on-time rate, lateness, current arrears, defaults and restructures.'],
            ['key' => 'capacity', 'label' => 'Payment Capacity', 'weight_percent' => 25, 'description' => 'Whether the member can afford the payments: debt service ratio and disposable income cover.'],
            ['key' => 'exposure', 'label' => 'Credit Exposure', 'weight_percent' => 15, 'description' => 'Existing obligations: past due amount, active loans and debt against annual income.'],
            ['key' => 'stability', 'label' => 'Stability', 'weight_percent' => 10, 'description' => 'Job tenure or business age, and years at the current residence.'],
            ['key' => 'relationship', 'label' => 'Lendy Relationship', 'weight_percent' => 10, 'description' => 'Membership tenure and loans completed with Lendy.'],
            ['key' => 'application', 'label' => 'Application Quality', 'weight_percent' => 5, 'description' => 'Whether the lending information on file is complete and verified.'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function factors(): array
    {
        return [
            self::factor(1, 'on_time_rate', 'repayment', 'On-time payment rate', 15, 'at_least', [[95, 15], [90, 12], [80, 8], [70, 4]], 0),
            self::factor(2, 'worst_days_late', 'repayment', 'Worst days late in the last 24 months', 8, 'at_most', [[0, 8], [7, 6], [30, 3], [60, 1]], 0),
            self::factor(3, 'days_past_due', 'repayment', 'Days past due now', 7, 'at_most', [[0, 7], [7, 4], [30, 2]], 0),
            self::factor(4, 'defaults_restructures', 'repayment', 'Defaults and restructures', 5, 'at_most', [[0, 5], [1, 3]], 1) + ['default_points' => 0],
            self::factor(5, 'debt_service_ratio', 'capacity', 'Debt service ratio', 15, 'at_most', [[20, 15], [30, 12], [40, 8], [50, 4]], 0),
            self::factor(6, 'payment_cover', 'capacity', 'Payment cover', 10, 'at_least', [[3, 10], [2, 7], [1.5, 5], [1, 2]], 0),
            self::factor(7, 'past_due_amount', 'exposure', 'Past due amount', 6, 'at_most', [[0, 6], [10, 3]], 0),
            self::factor(8, 'active_loans', 'exposure', 'Active loans', 4, 'at_most', [[1, 4], [2, 2]], 0),
            self::factor(9, 'debt_to_income', 'exposure', 'Debt against annual income', 5, 'at_most', [[25, 5], [50, 3], [100, 1]], 0),
            self::factor(10, 'tenure', 'stability', 'Job tenure or business age', 6, 'at_least', [[5, 6], [2, 4], [1, 2]], 1),
            self::factor(11, 'residence', 'stability', 'Years at residence', 4, 'at_least', [[5, 4], [2, 3], [1, 1]], 0),
            self::factor(12, 'membership_tenure', 'relationship', 'Membership tenure', 4, 'at_least', [[3, 4], [1, 3]], 1),
            self::factor(13, 'completed_loans', 'relationship', 'Completed loans', 4, 'at_least', [[3, 4], [2, 3], [1, 2]], 0),
            self::factor(14, 'clean_completed_loans', 'relationship', 'Completed loans never more than 7 days late', 2, 'at_least', [[2, 2], [1, 1]], 0),
            self::factor(15, 'valid_id', 'application', 'Valid ID on file', 2, 'at_least', [[1, 2]], 0),
            self::factor(16, 'valid_mobile', 'application', 'Valid mobile number', 1, 'at_least', [[1, 1]], 0),
            self::factor(17, 'income_verified', 'application', 'Income verified', 1, 'at_least', [[1, 1]], 0),
            self::factor(18, 'verified_references', 'application', 'Two or more verified references', 1, 'at_least', [[2, 1]], 0),
        ];
    }

    /**
     * Score bands, highest first. A score belongs to the first band whose
     * minimum it reaches. Recommendations are advice for the loan officer,
     * never a decision.
     *
     * @return list<array{key: string, label: string, min_score: int, recommendation: string}>
     */
    public static function riskBands(): array
    {
        return [
            ['key' => 'very_low', 'label' => 'Very Low Risk', 'min_score' => 90, 'recommendation' => 'Suitable for standard loan assessment.'],
            ['key' => 'low', 'label' => 'Low Risk', 'min_score' => 80, 'recommendation' => 'Suitable for standard loan assessment.'],
            ['key' => 'moderate', 'label' => 'Moderate Risk', 'min_score' => 70, 'recommendation' => 'Review the member\'s income and existing obligations before approval.'],
            ['key' => 'elevated', 'label' => 'Elevated Risk', 'min_score' => 60, 'recommendation' => 'Additional assessment needed. Manual review recommended.'],
            ['key' => 'high', 'label' => 'High Risk', 'min_score' => 40, 'recommendation' => 'High repayment risk. Review carefully before approving additional credit.'],
            ['key' => 'very_high', 'label' => 'Very High Risk', 'min_score' => 0, 'recommendation' => 'Serious repayment risk. Review carefully before approving additional credit.'],
        ];
    }

    /**
     * Credit policy rules, kept apart from the score: a flag is a warning for
     * the loan officer and never moves the number.
     *
     * @return list<array{id: int, key: string, type: string, label: string, description: string, is_active: bool}>
     */
    public static function policyRules(): array
    {
        return [
            ['id' => 1, 'key' => 'past_due_over_limit', 'type' => 'hard_flag', 'label' => 'Loan more than 30 days past due', 'description' => 'The member has a loan more than 30 days past due. Manual review required.', 'is_active' => true],
            ['id' => 2, 'key' => 'existing_default', 'type' => 'hard_flag', 'label' => 'Defaulted loan', 'description' => 'The member has a defaulted loan.', 'is_active' => true],
            ['id' => 3, 'key' => 'previous_write_off', 'type' => 'hard_flag', 'label' => 'Previous write-off', 'description' => 'Part of a previous loan of the member was written off.', 'is_active' => true],
            ['id' => 4, 'key' => 'income_verification_failed', 'type' => 'hard_flag', 'label' => 'Income verification failed', 'description' => 'The member\'s declared income could not be verified.', 'is_active' => true],
            ['id' => 5, 'key' => 'past_due', 'type' => 'soft_flag', 'label' => 'Loan past due', 'description' => 'The member has a loan 1 to 30 days past due.', 'is_active' => true],
            ['id' => 6, 'key' => 'too_many_active_loans', 'type' => 'soft_flag', 'label' => 'Active loans above policy limit', 'description' => 'The member\'s active loans, outside lenders included, exceed the policy limit.', 'is_active' => true],
            ['id' => 7, 'key' => 'possible_duplicate', 'type' => 'soft_flag', 'label' => 'Possible duplicate member record', 'description' => 'Another member record has the same name.', 'is_active' => true],
            ['id' => 8, 'key' => 'debt_service_above_caution', 'type' => 'soft_flag', 'label' => 'Debt service ratio above caution', 'description' => 'Debt payments take more of the member\'s income than the caution level.', 'is_active' => true],
            ['id' => 9, 'key' => 'notice_not_recorded', 'type' => 'soft_flag', 'label' => 'Credit assessment notice not recorded', 'description' => 'The member\'s acknowledgement of the credit assessment notice is not recorded for this loan.', 'is_active' => true],
        ];
    }

    /**
     * @param  list<array{0: int|float, 1: int}>  $bands
     * @return array<string, mixed>
     */
    private static function factor(int $id, string $key, string $category, string $label, int $points, string $rule, array $bands, int $otherwise): array
    {
        return [
            'id' => $id,
            'key' => $key,
            'category_key' => $category,
            'label' => $label,
            'points' => $points,
            'rule' => $rule,
            'bands' => array_map(fn (array $band) => ['threshold' => $band[0], 'points' => $band[1]], $bands),
            'otherwise' => $otherwise,
            'is_active' => true,
        ];
    }
}
