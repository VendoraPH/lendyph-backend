<?php

namespace App\Services\CreditScoring;

use App\Models\CreditScoringSetting;
use App\Models\Loan;
use App\Services\LoanService;
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
 * - debt service ratio = (existing debt + proposed payment) / income, read
 *   as manageable up to the settings' manageable level (30%), caution up to
 *   the caution level (40%), then above capacity.
 *
 * The proposed payment is the highest calendar-month total in the
 * application loan's previewed schedule (LoanService::buildAmortizationPreview(),
 * read only). Without an application loan there is none, and the ratio is
 * the existing debt's alone.
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
        public readonly ?int $disposableCentavos,
        public readonly ?int $suggestedMaximumCentavos,
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
    ): self {
        $income = $incomeCentavos !== null && $incomeCentavos > 0 ? $incomeCentavos : null;
        $disposable = $income !== null && $expensesCentavos !== null
            ? $income - $expensesCentavos - $existingDebtCentavos
            : null;

        $suggested = $disposable === null ? null : max(0, min(
            Centavos::share($disposable, $settings->capacity_share),
            Centavos::share($income, $settings->capacity_share) - $existingDebtCentavos,
        ));

        $ratio = $income === null ? null : ($existingDebtCentavos + ($proposedPaymentCentavos ?? 0)) * 100 / $income;

        return new self(
            incomeCentavos: $income,
            expensesCentavos: $expensesCentavos,
            existingDebtCentavos: $existingDebtCentavos,
            proposedPaymentCentavos: $proposedPaymentCentavos,
            disposableCentavos: $disposable,
            suggestedMaximumCentavos: $suggested,
            debtServiceRatio: $ratio,
            assessment: $ratio === null ? null : match (true) {
                $ratio <= (float) $settings->dsr_manageable_max => self::MANAGEABLE,
                $ratio <= (float) $settings->dsr_caution_max => self::CAUTION,
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

        return self::calculate(
            $incomeParts === [] ? null : array_sum(array_map(fn ($part) => Centavos::of($part), $incomeParts)),
            Centavos::orNull($profile?->household_expenses),
            $exposure->monthlyDebtCentavos + Centavos::of($profile?->outside_debt_payments),
            $data->applicationLoan === null ? null : self::proposedPayment($data->applicationLoan, $loans),
            $settings,
        );
    }

    /**
     * The highest calendar-month total in the loan's previewed schedule, or
     * null when the schedule can't be previewed.
     */
    public static function proposedPayment(Loan $loan, LoanService $loans): ?int
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

        $months = [];

        foreach ($rows as $row) {
            $month = Carbon::parse($row['due_date'])->format('Y-m');
            $months[$month] = ($months[$month] ?? 0) + Centavos::of($row['total_due']);
        }

        return $months === [] ? null : max($months);
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
        if ($this->debtServiceRatio === null) {
            return null;
        }

        $basisPoints = intdiv(($this->existingDebtCentavos + ($this->proposedPaymentCentavos ?? 0)) * 20000 + $this->incomeCentavos, 2 * $this->incomeCentavos);

        return number_format($basisPoints / 100, 2, '.', '');
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
            'disposable_income' => Centavos::toPesos($this->disposableCentavos),
            'suggested_maximum_payment' => Centavos::toPesos($this->suggestedMaximumCentavos),
            'debt_service_ratio' => $this->debtServiceRatioText(),
            'assessment' => $this->assessment,
            'proposed_fits' => $this->proposedFits(),
        ];
    }
}
