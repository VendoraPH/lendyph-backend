<?php

namespace App\Services;

use App\Enums\TermUnit;
use App\Models\Loan;
use App\Services\Accounting\PostingRules;

/**
 * The disclosure statement R.A. 3765 (Truth in Lending) requires, figures and all.
 *
 * ## What is a finance charge
 *
 * Interest and the cooperative's own fees, and nothing else. Each item of
 * `loans.deductions` is sorted by its type, read exactly as the release
 * journal reads it ({@see PostingRules::deductionType()} and
 * {@see PostingRules::DEDUCTION_ROLES}), so the statement and the books never
 * disagree on what a deduction is:
 *
 * - finance charge: a processing or service fee (booked to the cooperative's
 *   income), a catalog fee or any other untyped deduction (income too), and
 *   the part of `total_deductions` no item explains (the unitemised
 *   remainder, signed: "Other Charges" above zero, "Adjustment" below);
 * - non-finance charge: a notarial fee or an insurance premium (both booked
 *   as owed to somebody else), and any tax: a type containing "tax" or
 *   "documentary stamp", or exactly "dst". A tax wins over being a catalog
 *   fee, but not over a type with an account of its own;
 * - share capital: the member's own equity, deducted from the proceeds and
 *   shown there, never a charge.
 *
 * Every figure is worked in whole centavos, so the printed lines add up
 * exactly and the browser adds nothing.
 *
 * ## Insurance
 *
 * One line, whatever the release did: `amount` is the premium charged,
 * `deducted` what was withheld from the proceeds (the "Insurance Premium"
 * items the release appends), and `owed` what the release left unpaid
 * (`insurance_premium_amount` less the partial amount collected). Read from
 * the release's own columns, never from `insurance_remaining_balance`, which
 * falls as payments collect the premium and so would understate what was
 * charged once the member starts paying. A hand-typed "Insurance Premium" on
 * a loan with no premium columns is deducted in full and owes nothing.
 *
 * ## Rates
 *
 * The nominal annual rate is the contractual rate times the periods in a year
 * of the period it is quoted per, as the printable used to work it out in the
 * browser. The effective interest rate follows BSP Circular 730 (Memorandum
 * M-2011-040): the amount financed is the principal less the finance charges
 * deducted, the cash flows are each instalment's total due at its period
 * index, and the rate per period is the one that discounts those flows to the
 * amount financed. Annualised by the periods in a year of the PAYMENT
 * frequency; monthly is the annual rate over twelve. Non-finance charges and
 * share capital are not cash flows of the credit.
 */
class DisclosureService
{
    /**
     * Periods in a year of each frequency: the multipliers the printable used
     * (`FREQUENCY_META` in the frontend's printable templates) before the
     * server owned these figures. `upon_maturity` is not here: its one period
     * is the loan's own term, see {@see self::paymentPeriodsPerYear()}.
     *
     * @var array<string, int>
     */
    public const PERIODS_PER_YEAR = [
        'daily' => 365,
        'weekly' => 52,
        'bi_weekly' => 26,
        'semi_monthly' => 24,
        'monthly' => 12,
    ];

    /**
     * How each role in {@see PostingRules::DEDUCTION_ROLES} is disclosed. A
     * fee the cooperative keeps as income is a finance charge; one it owes on
     * to somebody else is not; share capital is the member's own.
     *
     * @var array<string, string>
     */
    private const KIND_BY_ROLE = [
        'processing_fee_income' => self::FINANCE,
        'service_fee_income' => self::FINANCE,
        'notarial_fees_payable' => self::NON_FINANCE,
        'insurance_premium_payable' => self::INSURANCE,
        'share_capital' => self::SHARE_CAPITAL,
    ];

    private const FINANCE = 'finance';

    private const NON_FINANCE = 'non_finance';

    private const INSURANCE = 'insurance';

    private const SHARE_CAPITAL = 'share_capital';

    /** The one line every insurance premium on a loan is disclosed under. */
    private const INSURANCE_LINE = 'Insurance Premium';

    public function __construct(private LoanService $loanService) {}

    public function generateDisclosure(Loan $loan): array
    {
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers', 'amortizationSchedules');

        $schedule = $loan->amortizationSchedules->isNotEmpty()
            ? $loan->amortizationSchedules->map(fn ($s) => [
                'period_number' => $s->period_number,
                'due_date' => $s->due_date->toDateString(),
                'principal_due' => (float) $s->principal_due,
                'interest_due' => (float) $s->interest_due,
                'total_due' => (float) $s->total_due,
                'remaining_balance' => (float) $s->remaining_balance,
            ])->values()->toArray()
            : $this->loanService->buildAmortizationPreview($loan);

        // Every total in whole centavos, so the printed statement's figures
        // add up exactly and the browser adds nothing.
        $sumOf = fn (string $column): int => array_sum(array_map(self::centavos(...), array_column($schedule, $column)));

        $totalPrincipal = $sumOf('principal_due');
        $totalInterest = $sumOf('interest_due');
        $principal = self::centavos($loan->principal_amount);
        $charges = $this->sortDeductions($loan);

        $financeItems = array_sum(array_column($charges['finance'], 'amount'));
        $financeTotal = $totalInterest + $financeItems;
        $nonFinanceTotal = array_sum(array_column($charges['non_finance'], 'amount'));
        $nonFinanceDeducted = array_sum(array_column($charges['non_finance'], 'deducted'));

        return [
            'document_title' => 'DISCLOSURE STATEMENT',
            'reference_number' => $loan->application_number,
            'generated_at' => now()->toDateTimeString(),

            'borrower' => [
                'borrower_code' => $loan->borrower->borrower_code,
                'full_name' => $loan->borrower->full_name,
                'address' => $loan->borrower->address,
                'contact_number' => $loan->borrower->contact_number,
                'email' => $loan->borrower->email,
                'employer_or_business' => $loan->borrower->employer_or_business,
                'monthly_income' => (float) $loan->borrower->monthly_income,
            ],

            'loan_terms' => [
                'application_number' => $loan->application_number,
                'loan_account_number' => $loan->loan_account_number,
                'loan_product_name' => $loan->loanProduct->name,
                'principal_amount' => (float) $loan->principal_amount,
                'interest_rate' => (float) $loan->interest_rate,
                'interest_rate_frequency' => $loan->interest_rate_frequency->value,
                'interest_method' => $loan->interest_method,
                'term' => $loan->term,
                'term_unit' => $loan->term_unit->value,
                'frequency' => $loan->frequency,
                'penalty_rate' => (float) $loan->penalty_rate,
                'grace_period_days' => $loan->grace_period_days,
                'start_date' => $loan->start_date->toDateString(),
                'maturity_date' => $loan->maturity_date->toDateString(),
            ],

            'deductions' => [
                'items' => $loan->deductions ?? [],
                'total_deductions' => (float) $loan->total_deductions,
                'net_proceeds' => (float) $loan->net_proceeds,
            ],

            'totals' => [
                'total_principal' => $totalPrincipal / 100,
                'total_interest' => $totalInterest / 100,
                'total_obligation' => ($totalPrincipal + $totalInterest) / 100,
                // The schedule's Total Amortization column added up.
                'total_amortization' => $sumOf('total_due') / 100,
                'total_deductions' => (float) $loan->total_deductions,
                'net_proceeds' => (float) $loan->net_proceeds,
                // Interest plus the cooperative's own fees: the finance charges
                // R.A. 3765 has disclosed. Not every deduction, as it once was.
                'total_finance_charges' => $financeTotal / 100,
                'total_non_finance_charges' => $nonFinanceTotal / 100,
                // The total less the itemised deductions, signed, printed as a
                // charge (or an adjustment) of its own so the lettered lines
                // add up to the total.
                'unitemised_deductions' => $loan->unitemisedDeductions(),
            ],

            'finance_charges' => [
                'interest' => $totalInterest / 100,
                'items' => array_map(fn (array $item) => ['name' => $item['name'], 'amount' => $item['amount'] / 100], $charges['finance']),
                'total' => $financeTotal / 100,
            ],

            'non_finance_charges' => [
                'items' => array_map(fn (array $item) => [
                    'name' => $item['name'],
                    'amount' => $item['amount'] / 100,
                    'deducted' => $item['deducted'] / 100,
                    'owed' => $item['owed'] / 100,
                ], $charges['non_finance']),
                'total' => $nonFinanceTotal / 100,
            ],

            // Exact by construction: the three deducted parts are every item
            // plus the unitemised remainder, i.e. `total_deductions`, so the
            // net is `principal_amount - total_deductions` — what every write
            // path stores as `net_proceeds`.
            'proceeds' => [
                'principal' => $principal / 100,
                'finance_charges_deducted' => $financeItems / 100,
                'non_finance_charges_deducted' => $nonFinanceDeducted / 100,
                'share_capital' => $charges['share_capital'] / 100,
                'net_proceeds' => ($principal - $financeItems - $nonFinanceDeducted - $charges['share_capital']) / 100,
            ],

            'rates' => $this->rates($loan, $schedule, $principal - $financeItems),

            'amortization_schedule' => $schedule,

            'co_makers' => $loan->coMakers->map(fn ($cm) => [
                'full_name' => $cm->full_name,
                'address' => $cm->address,
                'contact_number' => $cm->contact_number,
            ])->values()->toArray(),
        ];
    }

    /**
     * The loan's deductions sorted into finance charges, non-finance charges
     * and share capital, in centavos, each list in the order of the items.
     *
     * An entry with no numeric amount is not an item here, exactly as
     * {@see Loan::unitemisedDeductions()} reads it: its money, if any, is in
     * the remainder, which closes the finance list.
     *
     * @return array{
     *     finance: list<array{name: string, amount: int}>,
     *     non_finance: list<array{name: string, amount: int, deducted: int, owed: int}>,
     *     share_capital: int,
     * }
     */
    private function sortDeductions(Loan $loan): array
    {
        $finance = [];
        $nonFinance = [];
        $shareCapital = 0;
        $insuranceAt = null;
        $insuranceDeducted = 0;

        foreach (is_array($loan->deductions) ? $loan->deductions : [] as $item) {
            if (! is_array($item) || ! is_numeric($item['amount'] ?? null)) {
                continue;
            }

            $name = is_string($item['name'] ?? null) ? $item['name'] : '';
            $amount = self::centavos($item['amount']);

            switch (self::kindOf($name)) {
                case self::SHARE_CAPITAL:
                    $shareCapital += $amount;
                    break;
                case self::INSURANCE:
                    // One line for the premium, where its first item was.
                    $insuranceAt ??= count($nonFinance);
                    $nonFinance[$insuranceAt] = null;
                    $insuranceDeducted += $amount;
                    break;
                case self::NON_FINANCE:
                    $nonFinance[] = ['name' => $name, 'amount' => $amount, 'deducted' => $amount, 'owed' => 0];
                    break;
                default:
                    $finance[] = ['name' => $name, 'amount' => $amount];
            }
        }

        $remainder = self::centavos($loan->unitemisedDeductions());

        if ($remainder !== 0) {
            $finance[] = ['name' => $remainder > 0 ? 'Other Charges' : 'Adjustment', 'amount' => $remainder];
        }

        $owed = $this->insuranceOwedAtRelease($loan);

        if ($insuranceAt !== null || $owed > 0) {
            $nonFinance[$insuranceAt ?? count($nonFinance)] = [
                'name' => self::INSURANCE_LINE,
                'amount' => $insuranceDeducted + $owed,
                'deducted' => $insuranceDeducted,
                'owed' => $owed,
            ];
        }

        return ['finance' => $finance, 'non_finance' => array_values($nonFinance), 'share_capital' => $shareCapital];
    }

    /**
     * How a deduction named `$name` is disclosed.
     *
     * A type with an account of its own decides first, so a catalog fee
     * called "Service Fee" is a service fee. Then a tax, so a catalog fee
     * called "Documentary Stamp Tax" is not the cooperative's own. Anything
     * else (a catalog fee, a hand-typed charge) is booked to the
     * cooperative's income, so it is a finance charge.
     */
    private static function kindOf(string $name): string
    {
        $type = PostingRules::deductionType($name);
        $role = PostingRules::DEDUCTION_ROLES[$type] ?? null;

        if ($role !== null) {
            return self::KIND_BY_ROLE[$role] ?? self::FINANCE;
        }

        if ($type === 'dst' || str_contains($type, 'tax') || str_contains($type, 'documentary stamp')) {
            return self::NON_FINANCE;
        }

        return self::FINANCE;
    }

    /**
     * The premium the release left unpaid, in centavos: the full premium
     * less what a partial payment collected, or 0 when it was paid in full
     * or the loan carries no premium.
     */
    private function insuranceOwedAtRelease(Loan $loan): int
    {
        $premium = self::centavos($loan->insurance_premium_amount ?? 0);

        if ($premium <= 0 || $loan->insurance_payment_type !== 'partial') {
            return 0;
        }

        return max(0, $premium - self::centavos($loan->insurance_partial_amount ?? 0));
    }

    /**
     * The contractual, nominal annual and effective interest rates, in percent.
     *
     * @param  list<array<string, mixed>>  $schedule  the instalments disclosed
     * @param  int  $financed  the amount financed, in centavos
     * @return array{contractual_rate: float, rate_frequency: string|null, nominal_annual_rate: float|null, effective_rate_monthly: float|null, effective_rate_annual: float|null}
     */
    private function rates(Loan $loan, array $schedule, int $financed): array
    {
        $rate = (float) $loan->interest_rate;
        $rateFrequency = $loan->interest_rate_frequency?->value;
        $ratePeriods = self::PERIODS_PER_YEAR[$rateFrequency] ?? null;

        usort($schedule, fn (array $a, array $b) => $a['period_number'] <=> $b['period_number']);

        $perPeriod = self::periodicRate($financed, array_map(fn (array $row) => self::centavos($row['total_due']), $schedule));
        $paymentPeriods = $this->paymentPeriodsPerYear($loan);
        $annual = $perPeriod === null || $paymentPeriods === null ? null : $perPeriod * $paymentPeriods * 100;

        return [
            'contractual_rate' => $rate,
            'rate_frequency' => $rateFrequency,
            'nominal_annual_rate' => $ratePeriods === null ? null : self::percent($rate * $ratePeriods),
            'effective_rate_monthly' => $annual === null ? null : self::percent($annual / 12),
            'effective_rate_annual' => $annual === null ? null : self::percent($annual),
        ];
    }

    /**
     * Instalments in a year: the payment frequency's periods, or for a loan
     * paid in one sum at maturity, as many of its terms as fit in a year
     * (12 / months, or 365 / days, the daily convention above). Null when
     * neither is known.
     */
    private function paymentPeriodsPerYear(Loan $loan): ?float
    {
        if ($loan->frequency !== 'upon_maturity') {
            return isset(self::PERIODS_PER_YEAR[$loan->frequency]) ? (float) self::PERIODS_PER_YEAR[$loan->frequency] : null;
        }

        $term = (int) $loan->term;

        if ($term <= 0) {
            return null;
        }

        return ($loan->term_unit === TermUnit::Days ? 365 : 12) / $term;
    }

    /**
     * The rate per period `r` at which the flows, the k-th discounted by
     * (1 + r)^k, are worth exactly `$financed`; null when there is none.
     *
     * With no negative flow the present value falls steadily as the rate
     * rises (from the plain sum at 0 towards 0), so there is at most one
     * answer: the search brackets it on whichever side of 0 it lies, then
     * halves the bracket until it is narrower than 1e-15, far finer than the
     * four places of a percent the statement prints.
     *
     * @param  int  $financed  centavos
     * @param  list<int>  $flows  centavos, in period order
     */
    private static function periodicRate(int $financed, array $flows): ?float
    {
        if ($financed <= 0 || $flows === [] || min($flows) < 0 || array_sum($flows) <= 0) {
            return null;
        }

        $presentValue = function (float $rate) use ($flows): float {
            $value = 0.0;
            $discount = 1.0;

            foreach ($flows as $flow) {
                $discount /= 1 + $rate;
                $value += $flow * $discount;
            }

            return $value;
        };

        $low = 0.0;
        $high = 0.0;

        if ($presentValue(0.0) >= $financed) {
            $high = 1.0;

            while ($presentValue($high) > $financed) {
                $high *= 2;

                if ($high > 1e6) {
                    return null;
                }
            }
        } else {
            $low = -0.5;

            while ($presentValue($low) < $financed) {
                $low = ($low - 1) / 2;

                if ($low < -1 + 1e-12) {
                    return null;
                }
            }
        }

        for ($i = 0; $i < 200 && $high - $low > 1e-15; $i++) {
            $mid = ($low + $high) / 2;

            if ($presentValue($mid) > $financed) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return ($low + $high) / 2;
    }

    /** A percentage to four places, never a negative zero. */
    private static function percent(float $value): float
    {
        return round($value, 4) + 0.0;
    }

    private static function centavos(mixed $pesos): int
    {
        return (int) round((float) $pesos * 100);
    }
}
