<?php

namespace App\Services\LoanOrigination\Assessment;

use App\Models\OriginationAssessment;
use App\Models\OriginationRuleRevision;

/**
 * What CapacityAssessor worked out for one application, before it is stored
 * as a revision. Money is two-decimal peso strings, ratios are two-decimal
 * strings, and a figure that couldn't be worked out is null.
 */
final class AssessmentOutcome
{
    /**
     * @param  array<string, ?string>  $figures
     * @param  array{max_dti: string, min_coverage: string, min_remaining_income: string}  $thresholds
     * @param  array{dti: ?bool, coverage: ?bool, remaining_income: ?bool}  $checks
     * @param  list<string>  $missingInformation
     * @param  list<string>  $conflicts
     * @param  array{loan_product_id: ?int, requested_amount: ?string, requested_term: ?int, frequency: string, start_date: string}  $request
     * @param  array{verified_monthly_income: ?string, verified_debt_payments: ?string}  $inputs
     */
    public function __construct(
        public readonly string $result,
        public readonly bool $provisional,
        public readonly array $figures,
        public readonly array $thresholds,
        public readonly array $checks,
        public readonly string $explanation,
        public readonly array $missingInformation,
        public readonly array $conflicts,
        public readonly ?int $borrowerId,
        public readonly array $request,
        public readonly array $inputs,
        public readonly OriginationRuleRevision $ruleRevision,
        public readonly ?string $instalmentBasis,
    ) {}

    /** Above capacity or insufficient information (decision 12). */
    public function goesToPolicyException(): bool
    {
        return in_array($this->result, OriginationAssessment::POLICY_EXCEPTION_RESULTS, true);
    }
}
