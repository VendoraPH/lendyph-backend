<?php

namespace App\Services\LoanOrigination\Assessment;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\OriginationApplication;
use App\Models\OriginationAssessment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * An assessment revision in the shape of #615's `Assessment` type. Money and
 * ratios go out as JSON numbers worked out from the exact stored strings, as
 * the online application settings do; a figure that couldn't be worked out
 * is null. Every figure is the server's: the screen adds nothing up.
 *
 * The figures that count a linked member's Lendy loans read null, with an
 * explanation that doesn't give them, for a viewer who may not see that
 * member's loans (MemberLoanFigures); `member_figures_hidden` says so.
 *
 * `verified_inputs` are the verified monthly income and debt payments staff
 * entered for the run, as two-decimal strings (null: not verified), so the
 * run dialog can prefill them. They go to every viewer: they are the
 * applicant's own figures, the same as `figures.verified_monthly_income` and
 * `figures.outside_debt_payments` (which equals the verified debt payments
 * when they were entered), and neither counts nor gives back the member's
 * Lendy loans.
 *
 * Public so the application detail (LO-B3) can show the current revision the
 * same way.
 */
final class AssessmentPresenter
{
    public function __construct(
        private readonly RequiredDocuments $documents,
        private readonly MemberLoanFigures $memberLoans,
    ) {}

    /** @return array<string, mixed> */
    public function present(OriginationAssessment $assessment, OriginationApplication $application, User $viewer): array
    {
        $assessment->loadMissing(['ruleRevision', 'creator', 'loanProduct']);
        $hidden = $this->memberLoans->hiddenFrom($viewer, $assessment);

        return [
            'id' => $assessment->id,
            'version' => $assessment->version,
            'kind' => $assessment->kind,
            'application_id' => $application->id,
            'reference_number' => $application->reference_number,
            'applicant_name' => (string) $application->applicant_name,
            'loan_product_id' => $assessment->loan_product_id,
            'loan_product_name' => $assessment->loanProduct?->name,
            'requested_amount' => self::number((string) $assessment->requested_amount),
            'requested_term' => $assessment->requested_term,
            'frequency' => $assessment->frequency,
            'start_date' => $assessment->start_date?->toDateString(),
            'result' => $assessment->result,
            'result_label' => OriginationAssessment::RESULT_LABELS[$assessment->result],
            'provisional' => $assessment->provisional,
            'policy_exception' => $assessment->goesToPolicyException(),
            'figures' => array_map(self::number(...), $hidden ? MemberLoanFigures::withheld($assessment->figures) : $assessment->figures),
            'member_figures_hidden' => $hidden,
            'verified_inputs' => [
                'monthly_income' => self::pesos($assessment->inputs['verified_monthly_income'] ?? null),
                'debt_payments' => self::pesos($assessment->inputs['verified_debt_payments'] ?? null),
            ],
            'thresholds' => array_map(self::number(...), $assessment->thresholds),
            'checks' => $assessment->checks,
            'instalment_basis' => $assessment->instalment_basis,
            'explanation' => $hidden ? CapacityAssessor::explanationWithoutFigures($assessment) : (string) $assessment->explanation,
            'missing_information' => $assessment->missing_information,
            'conflicts' => $assessment->conflicts,
            'document_status' => $this->documents->status($application),
            'officer_findings' => $assessment->officer_findings,
            'recommendations' => $assessment->recommendations,
            'rules_version' => (string) $assessment->ruleRevision->version,
            'assessed_by' => SavingsUserRef::from($assessment->creator),
            'assessed_at' => $assessment->created_at,
        ];
    }

    /** A verified figure as entered for the run: a two-decimal string, or null when not verified. */
    private static function pesos(mixed $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
    }

    private static function number(?string $value): int|float|null
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return $value + 0;
    }
}
