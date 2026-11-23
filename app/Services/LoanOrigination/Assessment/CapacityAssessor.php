<?php

namespace App\Services\LoanOrigination\Assessment;

use App\Enums\TermUnit;
use App\Models\Borrower;
use App\Models\CreditScoringSetting;
use App\Models\LoanProduct;
use App\Models\OriginationApplication;
use App\Models\OriginationAssessment;
use App\Models\OriginationRuleRevision;
use App\Services\Accounting\Money;
use App\Services\CreditScoring\Capacity;
use App\Services\CreditScoring\Centavos;
use App\Services\CreditScoring\Exposure;
use App\Services\CreditScoring\MemberCreditData;
use App\Services\LoanListTotals;
use App\Services\LoanOrigination\DraftRules;
use App\Services\LoanService;
use App\Support\PhilippineMobile;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * An online application's payment capacity, on the server (origination
 * decisions 14 and 15). Every figure comes from code the rest of Lendy uses,
 * so it equals theirs:
 *
 * - declared income, expenses and debts: DraftRules::declaredTotals(), each
 *   line a month, rounded half up;
 * - eligible income: the verified monthly income when staff entered it, else
 *   each declared income line; either way × the recognition % of its income
 *   source in the current rule revision (other regular income counts as
 *   "other"), half up to the centavo;
 * - debt: the verified monthly debt payments when entered, else the declared
 *   ones, plus the linked member's Lendy loans by Credit Scoring's Exposure;
 * - instalment: Credit Scoring's rule (Capacity::proposedPaymentFromRows())
 *   on the schedule of the loan form's own preview (LoanService::formPreview()),
 *   at the product's current rate and the start date given (today by
 *   default). The busiest calendar month of a weekly, bi-weekly or daily loan
 *   moves with its start date, so the hand-off assesses again at the loan's;
 * - disposable income and the debt-to-income ratio: Capacity::calculate().
 *
 * The limits (judge()), with the product's override where one is set, each
 * compared exactly and never on a rounded figure:
 * - DTI at most the maximum: (debt + instalment) × 100 ≤ maximum × income.
 *   It is shown rounded up to two decimals, so a failing DTI never reads as
 *   within the limit (40.004% shows as 40.01%). It is the ratio Credit
 *   Scoring works out for the same loan, which Credit Scoring shows half up
 *   for its own levels;
 * - coverage (disposable income ÷ instalment) at least the minimum; it is
 *   shown to two decimals rounded down, so a shortfall never reads as the
 *   minimum;
 * - remaining income (disposable income less the instalment) at least the
 *   minimum in pesos.
 *
 * The result: insufficient information without income or an instalment;
 * above capacity when a limit fails; further review when the limits pass but
 * on unverified figures, with conflicts, a short history or information the
 * rules require (insufficient information instead when the rules' missing
 * information rule says so); within capacity otherwise. It never reads
 * gender, civil status or other personal traits (the Credit Scoring rule).
 */
final class CapacityAssessor
{
    public const NO_VERIFIED_INCOME = 'The verified monthly income hasn\'t been entered yet.';

    public const NO_VERIFIED_DEBT = 'The verified monthly debt payments haven\'t been entered yet.';

    public const NO_INCOME = 'No monthly income was given.';

    public const NO_INSTALMENT = 'The monthly instalment couldn\'t be worked out from the product\'s schedule.';

    public function __construct(
        private readonly DraftRules $draftRules,
        private readonly LoanService $loans,
        private readonly LoanListTotals $totals,
    ) {}

    /**
     * @param  ?string  $startDate  the loan's start date (Y-m-d), today when null
     */
    public function assess(OriginationApplication $application, ?string $verifiedIncome = null, ?string $verifiedDebt = null, ?string $startDate = null): AssessmentOutcome
    {
        $startDate ??= Carbon::now()->toDateString();
        $revision = OriginationRuleRevision::current();
        $rules = $revision->rules;
        $draft = $this->draftRules->normalize($application->draft ?? []);
        $declared = $this->draftRules->declaredTotals($draft, isset($rules['periods_per_year']) ? array_map('intval', $rules['periods_per_year']) : null);
        $product = $application->loan_product_id === null ? null : LoanProduct::query()->find($application->loan_product_id);
        $thresholds = self::thresholds($rules, $product?->id);

        $verifiedIncome = self::pesos($verifiedIncome);
        $verifiedDebt = self::pesos($verifiedDebt);
        $source = $draft['employment']['income_source'] !== '' ? $draft['employment']['income_source'] : 'other';

        $eligible = $this->eligibleIncome($declared['income'], $verifiedIncome, $source, $rules['income_recognition'] ?? []);
        $expenses = Centavos::of($declared['monthly_expenses']);
        $outsideDebt = Centavos::of($verifiedDebt ?? $declared['monthly_debt_payments']);
        $lendyDebt = $application->borrower_id === null ? 0 : Exposure::from(
            MemberCreditData::load((int) $application->borrower_id, null, Carbon::now()),
            $this->totals,
        )->monthlyDebtCentavos;

        $frequency = $draft['loan']['frequency'] !== '' ? $draft['loan']['frequency'] : (string) ($product?->frequency instanceof \BackedEnum ? $product->frequency->value : $product?->frequency);
        $request = [
            'loan_product_id' => $product?->id,
            'requested_amount' => $application->requested_amount === null ? null : (string) $application->requested_amount,
            'requested_term' => $application->requested_term,
            'frequency' => $frequency,
            'start_date' => $startDate,
        ];
        $instalment = $this->instalment($product, $request);

        $judged = self::judge($eligible, $expenses, $outsideDebt + $lendyDebt, $instalment['centavos'] ?? null, $thresholds);
        $capacity = $judged['capacity'];

        $years = $this->yearsInWork($draft['employment']);
        $minYears = $rules['min_employment_years'] ?? null;
        $hardMissing = [];

        if ($eligible === null) {
            $hardMissing[] = self::NO_INCOME;
        }

        if ($instalment === null) {
            $hardMissing[] = self::NO_INSTALMENT;
        }

        $missing = $hardMissing;

        if ($verifiedIncome === null) {
            $missing[] = self::NO_VERIFIED_INCOME;
        }

        if ($verifiedDebt === null) {
            $missing[] = self::NO_VERIFIED_DEBT;
        }

        if ($minYears !== null && $years === null) {
            $missing[] = 'The years employed or in business weren\'t given.';
        }

        $conflicts = $this->memberConflicts($application, $draft['personal']);

        if ($minYears !== null && $years !== null && $years < (float) $minYears) {
            $conflicts[] = sprintf('Employed or in business for %s years, below the %d-year minimum.', self::trimmed((string) $years), (int) $minYears);
        }

        $provisional = $verifiedIncome === null || $verifiedDebt === null;
        $requiredMissing = ((bool) ($rules['require_verified_income'] ?? false) && $verifiedIncome === null) || ($minYears !== null && $years === null);
        $failed = array_keys(array_filter($judged['checks'], fn (?bool $passed) => $passed === false));

        $result = match (true) {
            $hardMissing !== [] => 'insufficient_information',
            $failed !== [] => 'above_capacity',
            $requiredMissing && ($rules['missing_information_rule'] ?? 'provisional') === 'insufficient' => 'insufficient_information',
            $provisional || $conflicts !== [] || $requiredMissing => 'further_review',
            default => 'within_capacity',
        };

        $remaining = $judged['remaining_centavos'];

        return new AssessmentOutcome(
            result: $result,
            provisional: $provisional,
            figures: [
                'declared_monthly_income' => $declared['monthly_income'],
                'verified_monthly_income' => $verifiedIncome,
                'eligible_monthly_income' => Centavos::toPesos($eligible),
                'monthly_expenses' => Centavos::toPesos($expenses),
                'outside_debt_payments' => Centavos::toPesos($outsideDebt),
                'lendy_debt_payments' => Centavos::toPesos($lendyDebt),
                'monthly_debt_payments' => Centavos::toPesos($outsideDebt + $lendyDebt),
                'disposable_income' => Centavos::toPesos($capacity->disposableCentavos),
                'proposed_monthly_installment' => Centavos::toPesos($instalment['centavos'] ?? null),
                'dti_ratio' => $judged['dti'],
                'coverage_ratio' => $judged['coverage'],
                'remaining_income' => Centavos::toPesos($remaining),
            ],
            thresholds: $thresholds,
            checks: $judged['checks'],
            explanation: self::explain($result, $judged, $judged['checks'], $thresholds, $hardMissing, $missing, $conflicts, $provisional),
            missingInformation: $missing,
            conflicts: $conflicts,
            borrowerId: $application->borrower_id === null ? null : (int) $application->borrower_id,
            request: $request,
            inputs: ['verified_monthly_income' => $verifiedIncome, 'verified_debt_payments' => $verifiedDebt],
            ruleRevision: $revision,
            instalmentBasis: $instalment['basis'] ?? null,
        );
    }

    /**
     * The limits on figures already in centavos.
     *
     * @param  array{max_dti: string, min_coverage: string, min_remaining_income: string}  $thresholds
     * @return array{capacity: Capacity, dti: ?string, coverage: ?string, remaining_centavos: ?int, checks: array{dti: ?bool, coverage: ?bool, remaining_income: ?bool}}
     */
    public static function judge(?int $incomeCentavos, int $expensesCentavos, int $existingDebtCentavos, ?int $instalmentCentavos, array $thresholds): array
    {
        $capacity = Capacity::calculate($incomeCentavos, $expensesCentavos, $existingDebtCentavos, $instalmentCentavos, CreditScoringSetting::current());
        $hasInstalment = $instalmentCentavos !== null && $instalmentCentavos > 0;
        $disposable = $capacity->disposableCentavos;
        $income = $capacity->incomeCentavos;

        $dti = $hasInstalment && $income !== null
            ? (string) BigDecimal::of($existingDebtCentavos + $instalmentCentavos)->multipliedBy(100)->dividedBy($income, 2, RoundingMode::Up)
            : null;
        $coverage = $hasInstalment && $disposable !== null
            ? (string) BigDecimal::of($disposable)->dividedBy($instalmentCentavos, 2, RoundingMode::Down)
            : null;
        $remaining = $hasInstalment && $disposable !== null ? $disposable - $instalmentCentavos : null;

        return [
            'capacity' => $capacity,
            'dti' => $dti,
            'coverage' => $coverage,
            'remaining_centavos' => $remaining,
            'checks' => [
                'dti' => $dti === null ? null : BigDecimal::of($existingDebtCentavos + $instalmentCentavos)->multipliedBy(100)
                    ->isLessThanOrEqualTo(BigDecimal::of($thresholds['max_dti'])->multipliedBy($income)),
                'coverage' => $coverage === null ? null : BigDecimal::of($disposable)->isGreaterThanOrEqualTo(BigDecimal::of($thresholds['min_coverage'])->multipliedBy($instalmentCentavos)),
                'remaining_income' => $remaining === null ? null : BigDecimal::of($remaining)->isGreaterThanOrEqualTo(BigDecimal::of($thresholds['min_remaining_income'])->multipliedBy(100)),
            ],
        ];
    }

    /**
     * The rule revision's limits, with the product's own where it sets one.
     *
     * @param  array<string, mixed>  $rules
     * @return array{max_dti: string, min_coverage: string, min_remaining_income: string}
     */
    public static function thresholds(array $rules, ?int $productId): array
    {
        $limits = [
            'max_dti' => (string) $rules['max_dti'],
            'min_coverage' => (string) $rules['min_coverage'],
            'min_remaining_income' => (string) $rules['min_remaining_income'],
        ];

        foreach ($rules['product_overrides'] ?? [] as $override) {
            if ($productId !== null && (int) ($override['loan_product_id'] ?? 0) === $productId) {
                foreach (array_keys($limits) as $key) {
                    if (isset($override[$key])) {
                        $limits[$key] = (string) $override[$key];
                    }
                }
            }
        }

        return $limits;
    }

    /**
     * @param  list<array{key: string, label: string, monthly: string}>  $lines
     * @param  array<string, mixed>  $recognition
     */
    private function eligibleIncome(array $lines, ?string $verified, string $source, array $recognition): ?int
    {
        $percent = fn (string $from): string => (string) ($recognition[$from] ?? '100');

        if ($verified !== null) {
            return Centavos::share(Centavos::of($verified), $percent($source));
        }

        if ($lines === []) {
            return null;
        }

        $total = 0;

        foreach ($lines as $line) {
            $total += Centavos::share(Centavos::of($line['monthly']), $percent($line['key'] === 'other_regular_income' ? 'other' : $source));
        }

        return $total;
    }

    /**
     * @param  array{loan_product_id: ?int, requested_amount: ?string, requested_term: ?int, frequency: string, start_date: string}  $request
     * @return array{centavos: int, basis: string}|null
     */
    private function instalment(?LoanProduct $product, array $request): ?array
    {
        if ($product === null || $request['requested_amount'] === null || $request['requested_term'] === null || $request['frequency'] === '') {
            return null;
        }

        try {
            $preview = $this->loans->formPreview([
                'loan_product_id' => $product->id,
                'principal_amount' => $request['requested_amount'],
                'interest_rate' => (float) $product->interest_rate,
                'term' => $request['requested_term'],
                'frequency' => $request['frequency'],
                'start_date' => $request['start_date'],
            ]);
        } catch (ValidationException) {
            return null;
        }

        $rows = $preview['amortization']['rows'] ?? null;

        return is_array($rows) ? Capacity::proposedPaymentFromRows($rows, $request['requested_term'], $product->term_unit ?? TermUnit::Months) : null;
    }

    /** @param  array<string, mixed>  $employment */
    private function yearsInWork(array $employment): ?float
    {
        $value = match ($employment['income_source']) {
            'employed' => $employment['years_employed'],
            'self_employed', 'business_owner' => $employment['years_operating'],
            default => '',
        };
        $value = trim((string) $value);

        return $value !== '' && is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    /**
     * What differs between the linked member's record and the application,
     * in words and never with either value (decision 13). The member's record
     * is never changed.
     *
     * @param  array<string, mixed>  $personal
     * @return list<string>
     */
    private function memberConflicts(OriginationApplication $application, array $personal): array
    {
        if ($application->borrower_id === null) {
            return [];
        }

        $member = Borrower::query()->whereKey($application->borrower_id)->first(['id', 'first_name', 'last_name', 'birthdate', 'contact_number']);

        if ($member === null) {
            return [];
        }

        $same = fn (?string $a, ?string $b): bool => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $a))) === mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $b)));
        $conflicts = [];

        if (! $same($member->first_name, $personal['first_name'])) {
            $conflicts[] = 'The linked member\'s first name differs from the application\'s.';
        }

        if (! $same($member->last_name, $personal['last_name'])) {
            $conflicts[] = 'The linked member\'s last name differs from the application\'s.';
        }

        if ($member->birthdate?->toDateString() !== ($personal['date_of_birth'] !== '' ? $personal['date_of_birth'] : null)) {
            $conflicts[] = 'The linked member\'s date of birth differs from the application\'s.';
        }

        $memberMobile = PhilippineMobile::normalise($member->contact_number);
        $applicantMobile = PhilippineMobile::normalise($application->mobile);

        if ($memberMobile !== null && $applicantMobile !== null && $memberMobile !== $applicantMobile) {
            $conflicts[] = 'The linked member\'s mobile number differs from the application\'s.';
        }

        return $conflicts;
    }

    /**
     * A revision's explanation without any figure worked out from the debts
     * it counted: for a viewer the linked member's loans are withheld from
     * (MemberLoanFigures). The same sentence, with each ratio and amount named
     * but not given.
     */
    public static function explanationWithoutFigures(OriginationAssessment $assessment): string
    {
        $missing = $assessment->missing_information;

        return self::explain(
            $assessment->result,
            null,
            $assessment->checks,
            $assessment->thresholds,
            array_values(array_intersect($missing, [self::NO_INCOME, self::NO_INSTALMENT])),
            $missing,
            $assessment->conflicts,
            $assessment->provisional,
        );
    }

    /**
     * @param  ?array{capacity: Capacity, dti: ?string, coverage: ?string, remaining_centavos: ?int}  $judged  null names each figure without giving it
     * @param  array{dti?: ?bool, coverage?: ?bool, remaining_income?: ?bool}  $checks
     * @param  array{max_dti: string, min_coverage: string, min_remaining_income: string}  $limits
     * @param  list<string>  $hardMissing
     * @param  list<string>  $missing
     * @param  list<string>  $conflicts
     */
    private static function explain(string $result, ?array $judged, array $checks, array $limits, array $hardMissing, array $missing, array $conflicts, bool $provisional): string
    {
        if ($result === 'insufficient_information' && $hardMissing !== []) {
            return 'Insufficient information: '.self::lowerFirst(implode(' ', $hardMissing));
        }

        $dti = $judged === null ? 'the debt-to-income ratio' : ($judged['dti'] === null ? null : "the debt-to-income ratio of {$judged['dti']}%");
        $coverage = $judged === null ? 'the coverage ratio' : ($judged['coverage'] === null ? null : "the coverage ratio of {$judged['coverage']}");
        $remaining = $judged === null ? 'the remaining income' : ($judged['remaining_centavos'] === null ? null : 'the remaining income of '.Money::format($judged['remaining_centavos']));
        $minimumIncome = Money::format(Centavos::of($limits['min_remaining_income']));

        if ($result === 'above_capacity') {
            $reasons = array_values(array_filter([
                ($checks['dti'] ?? null) === false ? "{$dti} is above the {$limits['max_dti']}% maximum" : null,
                ($checks['coverage'] ?? null) === false ? "{$coverage} is below the {$limits['min_coverage']} minimum" : null,
                ($checks['remaining_income'] ?? null) === false ? "{$remaining} is below the {$minimumIncome} minimum" : null,
            ]));

            return 'Above capacity: '.self::sentence($reasons).'.';
        }

        $passed = "{$dti} is within the {$limits['max_dti']}% maximum, {$coverage} meets the {$limits['min_coverage']} minimum and {$remaining} meets the {$minimumIncome} minimum";

        if ($result === 'within_capacity') {
            return "Within capacity: {$passed}, on verified figures.";
        }

        $otherMissing = array_values(array_diff($missing, $hardMissing, [self::NO_VERIFIED_INCOME, self::NO_VERIFIED_DEBT]));
        $because = array_values(array_filter([
            $provisional ? 'the figures aren\'t all verified yet' : null,
            $conflicts !== [] ? 'there are conflicts to check ('.rtrim(self::lowerFirst(implode(' ', $conflicts)), '.').')' : null,
            $otherMissing !== [] ? 'information is missing ('.rtrim(self::lowerFirst(implode(' ', $otherMissing)), '.').')' : null,
        ]));

        $label = $result === 'insufficient_information' ? 'Insufficient information' : 'Further review needed';

        return "{$label}: {$passed}, but ".self::sentence($because).'.';
    }

    /** @param  list<string>  $parts */
    private static function sentence(array $parts): string
    {
        if (count($parts) <= 1) {
            return $parts[0] ?? 'information is missing';
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }

    private static function lowerFirst(string $text): string
    {
        return rtrim(mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1), '.').'.';
    }

    private static function trimmed(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    /** A peso amount as a two-decimal string, or null. */
    private static function pesos(?string $amount): ?string
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        return (string) BigDecimal::of(trim($amount))->toScale(2, RoundingMode::Unnecessary);
    }
}
