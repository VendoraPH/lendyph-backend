<?php

namespace App\Services\LoanOrigination\Assessment;

use App\Models\Borrower;
use App\Models\OriginationAssessment;
use App\Models\User;
use App\Services\ContactVisibility;

/**
 * The assessment figures that count a linked member's Lendy loans, and who
 * may read them. They are the member's loan data, so a viewer needs
 * `loans:view`, `borrowers:view` and the member's branch (the round fz
 * personal-data rule); anyone else reads them as null, with an explanation
 * that names each figure without giving it (the way Credit Scoring withholds
 * a member's capacity, `details_hidden`).
 *
 * Every figure worked out from the debts goes with the Lendy debt payments,
 * since each would give them back by subtraction: the total debt payments,
 * the disposable and remaining income, and the two ratios. The applicant's
 * own income, expenses and outside debts, the instalment, the checks and the
 * result stay. An assessment with no linked member counted no Lendy loans and
 * withholds nothing.
 */
final class MemberLoanFigures
{
    public const FIGURES = ['lendy_debt_payments', 'monthly_debt_payments', 'disposable_income', 'dti_ratio', 'coverage_ratio', 'remaining_income'];

    /** @var array<int, ?int> member id => branch id, read once per member */
    private array $branches = [];

    public function __construct(private readonly ContactVisibility $contacts) {}

    public function hiddenFrom(User $viewer, OriginationAssessment $assessment): bool
    {
        if ($assessment->borrower_id === null) {
            return false;
        }

        if (! $viewer->can('loans:view')) {
            return true;
        }

        $memberId = (int) $assessment->borrower_id;

        if (! array_key_exists($memberId, $this->branches)) {
            $branchId = Borrower::query()->whereKey($memberId)->value('branch_id');
            $this->branches[$memberId] = $branchId === null ? null : (int) $branchId;
        }

        return ! $this->contacts->allows($viewer, $this->branches[$memberId]);
    }

    /**
     * The revision's figures with the member's loan figures set to null.
     *
     * @param  array<string, ?string>  $figures
     * @return array<string, ?string>
     */
    public static function withheld(array $figures): array
    {
        foreach (self::FIGURES as $figure) {
            if (array_key_exists($figure, $figures)) {
                $figures[$figure] = null;
            }
        }

        return $figures;
    }
}
