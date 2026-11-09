<?php

namespace App\Services\CreditScoring;

use App\Enums\TermUnit;
use App\Models\CreditScoringSetting;
use App\Models\Loan;
use App\Services\LoanService;
use App\Services\LoanTermSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payment capacity, in integer centavos rounded half up:
 * - income = the member's monthly income plus the application's other and
 *   business income;
 * - existing debt = the monthly payment of the member's Lendy loans plus the
 *   declared outside debt payments;
 * - disposable = income - household expenses - existing debt;
 * - suggested maximum payment = max(0, min(disposable x share,
 *   income x share - existing debt)), the share being the settings'
 *   `capacity_share` (40%);
 * - debt service ratio = (existing debt + proposed payment) / income, to two
 *   decimals rounded half up, read on those same two decimals as manageable
 *   up to the settings' manageable level (30%), caution up to the caution
 *   level (40%), then above capacity.
 *
 * The proposed payment is the highest calendar-month total in the
 * application loan's previewed schedule (LoanService::buildAmortizationPreview(),
 * read only). A single-payment loan (upon maturity) counts its monthly
 * equivalent: the payment divided by the term in months (30-day months for a
 * days term), at least one, so a loan of a month or less counts whole.
 * Without an application loan there is none, and the ratio is the existing
 * debt's alone.
 *
 * A blank figure stays blank: no income means no ratio, no expenses means
 * no disposable income, and the factors that need them drop out.
 */
final class Capacity
{
    public const MANAGEABLE = 'manageable';

    public const CAUTION = 'caution';

    public const ABOVE_CAPACITY = 'above_capacity';

    private function __construct(
        public readonly ?int $incomeCentavos,
        public readonly ?int $expensesCentavos,
        public readonly int $existingDebtCentavos,
        public readonly ?int $proposedPaymentCentavos,
        public readonly ?string $proposedPaymentBasis,
        public readonly ?int $disposableCentavos,
        public readonly ?int $suggestedMaximumCentavos,
        public readonly ?int $debtServiceBasisPoints,
        public readonly ?float $debtServiceRatio,
        public readonly ?string $assessment,
    ) {}

    /**
     * The calculation itself, on figures already in centavos.
     */
    public static function calculate(
        ?int $incomeCentavos,
        ?int $expensesCentavos,
        int $existingDebtCentavos,
        ?int $proposedPaymentCentavos,
        CreditScoringSetting $settings,
        ?string $proposedPaymentBasis = null,
    ): self {
        $income = $incomeCentavos !== null && $incomeCentavos > 0 ? $incomeCentavos : null;
        $disposable = $income !== null && $expensesCentavos !== null
            ? $income - $expensesCentavos - $existingDebtCentavos
            : null;

        $suggested = $disposable === null ? null : max(0, min(
            Centavos::share($disposable, $settings->capacity_share),
            Centavos::share($income, $settings->capacity_share) - $existingDebtCentavos,
        ));

        $debt = $existingDebtCentavos + ($proposedPaymentCentavos ?? 0);
        $basisPoints = $income === null ? null : intdiv(2 * $debt * 10000 + $income, 2 * $income);
        $within = fn (string|int|float $levelPercent): bool => $basisPoints * 100 <= (int) round(((float) $levelPercent) * 10000);

        return new self(
            incomeCentavos: $income,
            expensesCentavos: $expensesCentavos,
            existingDebtCentavos: $existingDebtCentavos,
            proposedPaymentCentavos: $proposedPaymentCentavos,
            proposedPaymentBasis: $proposedPaymentCentavos === null ? null : $proposedPaymentBasis,
            disposableCentavos: $disposable,
            suggestedMaximumCentavos: $suggested,
            debtServiceBasisPoints: $basisPoints,
            debtServiceRatio: $basisPoints === null ? null : $basisPoints / 100,
            assessment: $basisPoints === null ? null : match (true) {
                $within($settings->dsr_manageable_max) => self::MANAGEABLE,
                $within($settings->dsr_caution_max) => self::CAUTION,
                default => self::ABOVE_CAPACITY,
            },
        );
    }

    public static function from(MemberCreditData $data, Exposure $exposure, CreditScoringSetting $settings, LoanService $loans): self
    {
        $profile = $data->profile;
        $incomeParts = array_filter(
            [$data->member->monthly_income, $profile?->other_income, $profile?->business_income],
            fn ($part) => $part !== null,
        );

        $proposed = $data->applicationLoan === null ? null : self::proposedPayment($data->applicationLoan, $loans);

        return self::calculate(
            $incomeParts === [] ? null : array_sum(array_map(fn ($part) => Centavos::of($part), $incomeParts)),
            Centavos::orNull($profile?->household_expenses),
            $exposure->monthlyDebtCentavos + Centavos::of($profile?->outside_debt_payments),
            $proposed['centavos'] ?? null,
            $settings,
            $proposed['basis'] ?? null,
        );
    }

    /**
     * The proposed monthly payment and how it was worked out, or null when
     * the schedule can't be previewed: the highest calendar-month total of an
     * instalment loan, or a single payment's monthly equivalent.
     *
     * @return array{centavos: int, basis: string}|null
     */
    public static function proposedPayment(Loan $loan, LoanService $loans): ?array
    {
        try {
            $rows = $loans->buildAmortizationPreview($loan);
        } catch (Throwable $e) {
            Log::warning('Credit scoring: the application loan\'s schedule could not be previewed; capacity is scored without a proposed payment.', [
                'loan_id' => $loan->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($rows === []) {
            return null;
        }

        if (count($rows) === 1) {
            return self::monthlyEquivalent($loan, Centavos::of($rows[0]['total_due']));
        }

        $months = [];

        foreach ($rows as $row) {
            $month = Carbon::parse($row['due_date'])->format('Y-m');
            $months[$month] = ($months[$month] ?? 0) + Centavos::of($row['total_due']);
        }

        return ['centavos' => max($months), 'basis' => 'the highest calendar-month total of the previewed schedule'];
    }

    /**
     * A single payment spread over the term in months, at least one: a
     * months term divides by its months, a days term by its 30-day months.
     * Rounded half up to the centavo.
     *
     * @return array{centavos: int, basis: string}
     */
    private static function monthlyEquivalent(Loan $loan, int $totalCentavos): array
    {
        $term = max(0, (int) $loan->term);
        $inDays = $loan->term_unit === TermUnit::Days;
        $months = $inDays ? $term / LoanTermSchedule::DAYS_PER_MONTH : $term;

        if ($months <= 1) {
            return ['centavos' => $totalCentavos, 'basis' => 'the single payment of '.Centavos::format($totalCentavos).', counted whole for a term of a month or less'];
        }

        $centavos = $inDays
            ? intdiv(2 * $totalCentavos * LoanTermSchedule::DAYS_PER_MONTH + $term, 2 * $term)
            : intdiv(2 * $totalCentavos + $term, 2 * $term);
        $monthText = rtrim(rtrim(number_format($months, 2, '.', ''), '0'), '.');

        return ['centavos' => $centavos, 'basis' => 'the monthly equivalent of the single payment of '.Centavos::format($totalCentavos)." over {$monthText} months"];
    }

    /**
     * Disposable income over the proposed payment, or null without both.
     */
    public function paymentCover(): ?float
    {
        if ($this->disposableCentavos === null || $this->proposedPaymentCentavos === null || $this->proposedPaymentCentavos <= 0) {
            return null;
        }

        return $this->disposableCentavos / $this->proposedPaymentCentavos;
    }

    /**
     * The debt service ratio as shown: two decimals, rounded half up.
     */
    public function debtServiceRatioText(): ?string
    {
        if ($this->debtServiceBasisPoints === null) {
            return null;
        }

        return number_format($this->debtServiceBasisPoints / 100, 2, '.', '');
    }

    /**
     * Whether the proposed payment fits the suggested maximum, or null when
     * either is unknown.
     */
    public function proposedFits(): ?bool
    {
        if ($this->proposedPaymentCentavos === null || $this->suggestedMaximumCentavos === null) {
            return null;
        }

        return $this->proposedPaymentCentavos <= $this->suggestedMaximumCentavos;
    }

    /**
     * The stored capacity block, money as two-decimal peso strings.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monthly_income' => Centavos::toPesos($this->incomeCentavos),
            'household_expenses' => Centavos::toPesos($this->expensesCentavos),
            'existing_debt_payments' => Centavos::toPesos($this->existingDebtCentavos),
            'proposed_payment' => Centavos::toPesos($this->proposedPaymentCentavos),
            'proposed_payment_basis' => $this->proposedPaymentBasis,
            'disposable_income' => Centavos::toPesos($this->disposableCentavos),
            'suggested_maximum_payment' => Centavos::toPesos($this->suggestedMaximumCentavos),
            'debt_service_ratio' => $this->debtServiceRatioText(),
            'assessment' => $this->assessment,
            'proposed_fits' => $this->proposedFits(),
        ];
    }
}
