<?php

namespace App\Services\CreditScoring;

use App\Models\CreditScoringSetting;
use App\Services\LoanListTotals;
use App\Services\LoanService;
use App\Support\PhilippineMobile;
use Carbon\Carbon;

/**
 * The Lendy Credit Score: an internal, advisory 0 to 100 risk score with the
 * reasons behind it. It never decides or changes a loan.
 *
 * - Each active factor of the revision is scored from the member's data. A
 *   factor without data is left out: it earns nothing and counts in nothing.
 * - Score = round(100 x points earned / points possible), over the factors
 *   with data, so a thin file is scaled rather than penalised.
 * - A score needs Repayment Behaviour or Payment Capacity data; without
 *   either the result is `insufficient_data`.
 * - Never used: the member's date of birth, sex, marital status, home, family
 *   details or share capital. MemberCreditData doesn't even load them.
 */
final class Scorer
{
    public const ENGINE_VERSION = '1.0.0';

    public function __construct(
        private readonly LoanListTotals $totals,
        private readonly LoanService $loans,
        private readonly PolicyFlags $policyFlags,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     score: int|null,
     *     risk_level: string|null,
     *     score_type: string,
     *     confidence: string,
     *     confidence_reason: string,
     *     limited_history: bool,
     *     categories: list<array<string, mixed>>,
     *     factors: list<array<string, mixed>>,
     *     capacity: array<string, mixed>,
     *     flags: list<array<string, mixed>>,
     *     recommendation: string,
     *     data_used: array<string, mixed>,
     *     revision_id: int,
     *     revision_version: string,
     *     has_active_loan: bool,
     *     past_due: bool,
     * }
     */
    public function score(MemberCreditData $data, CreditScoringSetting $settings, FactorTable $table): array
    {
        $history = PaymentHistory::from($data);
        $exposure = Exposure::from($data, $this->totals);
        $capacity = Capacity::from($data, $exposure, $settings, $this->loans);
        $incomeVerified = $data->profile?->income_verification === 'verified';
        $confidence = Confidence::assess($history, $capacity, $incomeVerified);

        $values = $this->rawValues($data, $history, $exposure, $capacity);
        $factors = [];

        foreach ($table->factors() as $factor) {
            $value = $values[$factor['key']] ?? null;
            $possible = (int) $factor['points'];

            if ($value === null) {
                $factors[] = $this->factorRow($factor, false, null, 0, $possible, 'No data, so this factor is left out.');

                continue;
            }

            $earned = $table->points($factor, $value['value'], $value['defaulted'] ?? false);
            $factors[] = $this->factorRow($factor, true, $value, $earned, $possible, $value['detail']);
        }

        $categories = $this->categories($table, $factors);
        $scorable = collect($categories)->whereIn('key', ['repayment', 'capacity'])->contains('has_data', true);
        $earned = array_sum(array_column(array_filter($factors, fn ($f) => $f['has_data']), 'points_earned'));
        $possible = array_sum(array_column(array_filter($factors, fn ($f) => $f['has_data']), 'points_possible'));

        $score = $scorable && $possible > 0 ? intdiv(200 * $earned + $possible, 2 * $possible) : null;
        $band = $score === null ? null : $table->bandFor($score);
        $flags = $this->policyFlags->evaluate($data, $history, $exposure, $capacity, $settings, $table);

        return [
            'status' => $score === null ? 'insufficient_data' : 'scored',
            'score' => $score,
            'risk_level' => $band['key'] ?? null,
            'score_type' => $history->hasHistory() ? 'behavioral' : 'application',
            'confidence' => $confidence->level,
            'confidence_reason' => $confidence->reason,
            'limited_history' => $confidence->limitedHistory,
            'categories' => $categories,
            'factors' => $factors,
            'capacity' => $capacity->toArray(),
            'flags' => $flags,
            'recommendation' => $this->recommendation($band, $flags, $capacity),
            'data_used' => $this->dataUsed($data, $history, $exposure, $capacity),
            'revision_id' => $table->revisionId,
            'revision_version' => $table->version,
            'has_active_loan' => $exposure->activeLoans > 0,
            'past_due' => $history->daysPastDueNow > 0,
        ];
    }

    /**
     * Each factor's raw value and explanation, or null where there is no
     * data for it.
     *
     * No completed loan is no data for the two completed-loan factors, so a
     * first-time member partway through a loan scales like a member with no
     * loan, unless one of the member's loans ended badly (defaulted, written
     * off or restructured), when zero completed loans scores zero.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function rawValues(MemberCreditData $data, PaymentHistory $history, Exposure $exposure, Capacity $capacity): array
    {
        $profile = $data->profile;
        $asOf = $data->asOf;
        $hasHistory = $history->hasHistory();
        $rate = $history->onTimeRate();
        $outsideLenders = (int) ($profile?->outside_lenders_count ?? 0);
        $activeLoans = $exposure->activeLoans + $outsideLenders;
        $requested = $data->applicationLoan === null ? 0 : Centavos::of($data->applicationLoan->principal_amount);
        $income = $capacity->incomeCentavos;
        $dsr = $capacity->debtServiceRatio;
        $cover = $capacity->paymentCover();
        $tenure = $this->tenureYears($data, $asOf);
        $residence = $profile?->years_at_residence;
        $memberSince = Carbon::parse($data->member->approved_at ?? $data->member->created_at);
        $membershipYears = $this->yearsBetween($memberSince, $asOf);
        $troubled = $history->defaultedLoans + $history->writtenOffLoans + $history->restructuredLoans > 0;
        $hasCompletionData = $history->completedLoans > 0 || $troubled;
        $mobile = PhilippineMobile::normalise($data->member->contact_number) !== null;

        return [
            'on_time_rate' => $rate === null ? null : [
                'value' => $rate,
                'detail' => sprintf('%s%% of %d matured periods were paid on time.', number_format($rate, 2), $history->knownCount),
            ],
            'worst_days_late' => $history->worstDaysLate24m === null ? null : [
                'value' => $history->worstDaysLate24m,
                'detail' => $history->worstDaysLate24m === 0
                    ? 'No period was paid late in the last 24 months.'
                    : "The latest a period was paid in the last 24 months was {$history->worstDaysLate24m} days after its due date.",
            ],
            'days_past_due' => ! $hasHistory ? null : [
                'value' => $history->daysPastDueNow,
                'detail' => $history->daysPastDueNow === 0
                    ? 'No loan is past due now.'
                    : "A loan is {$history->daysPastDueNow} days past due now.",
            ],
            'defaults_restructures' => ! $hasHistory && ! $troubled ? null : [
                'value' => $history->restructuredLoans,
                'defaulted' => $history->defaultedLoans + $history->writtenOffLoans > 0,
                'detail' => match (true) {
                    $history->defaultedLoans > 0 => 'The member has a defaulted loan.',
                    $history->writtenOffLoans > 0 => 'Part of a previous loan was written off.',
                    $history->restructuredLoans === 0 => 'No defaults or restructures.',
                    $history->restructuredLoans === 1 => '1 loan was restructured.',
                    default => "{$history->restructuredLoans} loans were restructured.",
                },
            ],
            'debt_service_ratio' => $dsr === null ? null : [
                'value' => $dsr,
                'detail' => "Debt payments take {$capacity->debtServiceRatioText()}% of monthly income ("
                    .Centavos::format($capacity->existingDebtCentavos + ($capacity->proposedPaymentCentavos ?? 0)).' of '.Centavos::format($income).')'
                    .($capacity->proposedPaymentBasis === null ? '.' : "; the proposed payment is {$capacity->proposedPaymentBasis}."),
            ],
            'payment_cover' => $cover === null ? null : [
                'value' => $cover,
                'detail' => sprintf('Disposable income of %s covers the proposed payment of %s %s times; the proposed payment is %s.',
                    Centavos::format($capacity->disposableCentavos), Centavos::format($capacity->proposedPaymentCentavos), number_format($cover, 2), $capacity->proposedPaymentBasis),
            ],
            'past_due_amount' => [
                'value' => $exposure->pastDuePercent(),
                'amount' => Centavos::toPesos($exposure->pastDueCentavos),
                'detail' => $exposure->pastDueCentavos === 0
                    ? 'Nothing is past due.'
                    : Centavos::format($exposure->pastDueCentavos).' is past due, of '.Centavos::format($exposure->outstandingCentavos).' outstanding.',
            ],
            'active_loans' => [
                'value' => $activeLoans,
                'detail' => ($activeLoans === 1 ? '1 active loan' : "{$activeLoans} active loans")
                    .($outsideLenders > 0 ? ", {$outsideLenders} of them with outside lenders." : '.'),
            ],
            'debt_to_income' => $income === null ? null : [
                'value' => ($exposure->outstandingCentavos + $requested) * 100 / ($income * 12),
                'detail' => 'Outstanding'.($requested > 0 ? ' and requested' : '').' debt of '.Centavos::format($exposure->outstandingCentavos + $requested)
                    .' is '.number_format(($exposure->outstandingCentavos + $requested) * 100 / ($income * 12), 2).'% of annual income.',
            ],
            'tenure' => $tenure === null ? null : [
                'value' => $tenure,
                'detail' => sprintf('%s years in the current job or business.', number_format($tenure, 1)),
            ],
            'residence' => $residence === null ? null : [
                'value' => (float) $residence,
                'detail' => sprintf('%s years at the current residence.', number_format((float) $residence, 1)),
            ],
            'membership_tenure' => [
                'value' => $membershipYears,
                'detail' => sprintf('Member since %s (%s years).', $memberSince->format('F Y'), number_format($membershipYears, 1)),
            ],
            'completed_loans' => ! $hasCompletionData ? null : [
                'value' => $history->completedLoans,
                'detail' => $history->completedLoans === 1 ? '1 loan completed.' : "{$history->completedLoans} loans completed.",
            ],
            'clean_completed_loans' => ! $hasCompletionData ? null : [
                'value' => $history->cleanCompletedLoans,
                'detail' => $history->cleanCompletedLoans === 1
                    ? '1 completed loan was never more than 7 days late.'
                    : "{$history->cleanCompletedLoans} completed loans were never more than 7 days late.",
            ],
            'valid_id' => [
                'value' => $data->hasValidId ? 1 : 0,
                'detail' => $data->hasValidId ? 'A valid ID is on file.' : 'No valid ID is on file.',
            ],
            'valid_mobile' => [
                'value' => $mobile ? 1 : 0,
                'detail' => $mobile ? 'A valid mobile number is on file.' : 'No valid mobile number is on file.',
            ],
            'income_verified' => $profile?->income_verification === null ? null : [
                'value' => $profile->income_verification === 'verified' ? 1 : 0,
                'detail' => $profile->income_verification === 'verified' ? 'Income was verified.' : 'Income verification failed.',
            ],
            'verified_references' => $profile?->verified_references_count === null ? null : [
                'value' => (int) $profile->verified_references_count,
                'detail' => $profile->verified_references_count === 1 ? '1 verified reference.' : "{$profile->verified_references_count} verified references.",
            ],
        ];
    }

    /**
     * The longer of job tenure and business age, in years, or null when
     * neither is known.
     */
    private function tenureYears(MemberCreditData $data, Carbon $asOf): ?float
    {
        $starts = array_filter([
            $data->member->date_hired !== null ? Carbon::parse($data->member->date_hired) : null,
            $data->profile?->business_start_date,
        ]);

        if ($starts === []) {
            return null;
        }

        return max(array_map(fn (Carbon $start) => $this->yearsBetween($start, $asOf), $starts));
    }

    /**
     * Whole months between two dates, in years; 0 for a start in the future.
     */
    private function yearsBetween(Carbon $start, Carbon $asOf): float
    {
        return $start->gte($asOf) ? 0.0 : floor($start->copy()->startOfDay()->diffInMonths($asOf)) / 12;
    }

    /**
     * @param  array<string, mixed>  $factor
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>
     */
    private function factorRow(array $factor, bool $hasData, ?array $value, int $earned, int $possible, string $detail): array
    {
        return [
            'factor_key' => $factor['key'],
            'category_key' => $factor['category_key'],
            'label' => $factor['label'],
            'has_data' => $hasData,
            'raw_value' => $value === null ? null : array_diff_key($value, ['detail' => true]),
            'points_earned' => $earned,
            'points_possible' => $possible,
            'impact' => ! $hasData ? 'neutral' : ($earned * 2 >= $possible ? 'positive' : 'risk'),
            'detail' => $detail,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $factors
     * @return list<array<string, mixed>>
     */
    private function categories(FactorTable $table, array $factors): array
    {
        return array_map(function (array $category) use ($factors) {
            $own = array_filter($factors, fn ($f) => $f['category_key'] === $category['key'] && $f['has_data']);

            return [
                'key' => $category['key'],
                'label' => $category['label'],
                'weight_percent' => $category['weight_percent'],
                'has_data' => $own !== [],
                'points_earned' => array_sum(array_column($own, 'points_earned')),
                'points_possible' => array_sum(array_column($own, 'points_possible')),
            ];
        }, $table->categories());
    }

    /**
     * Advice for the loan officer, never a verdict.
     *
     * @param  array<string, mixed>|null  $band
     * @param  list<array<string, mixed>>  $flags
     */
    private function recommendation(?array $band, array $flags, Capacity $capacity): string
    {
        if ($band === null) {
            return 'Not enough data to score. Review the member\'s application and history manually.';
        }

        $parts = [];
        $hard = array_values(array_filter($flags, fn ($flag) => $flag['type'] === 'hard_flag'));

        if ($hard !== []) {
            $parts[] = 'Manual review required: '.implode('; ', array_column($hard, 'label')).'.';
        }

        $parts[] = (string) ($band['recommendation'] ?? '');

        if ($capacity->proposedFits() === false) {
            $parts[] = 'The proposed payment is above the suggested maximum payment of '.Centavos::format($capacity->suggestedMaximumCentavos).'.';
        }

        return mb_substr(trim(implode(' ', array_filter($parts))), 0, 500);
    }

    /**
     * The inputs the score was calculated from, for the decision record.
     *
     * @return array<string, mixed>
     */
    private function dataUsed(MemberCreditData $data, PaymentHistory $history, Exposure $exposure, Capacity $capacity): array
    {
        return [
            'as_of' => $data->asOf->toDateString(),
            'engine_version' => self::ENGINE_VERSION,
            'application_loan_id' => $data->applicationLoan?->id,
            'application_profile_id' => $data->profile?->id,
            'loan_ids' => $data->loans->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'branch_ids' => $data->loans->pluck('branch_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all(),
            'matured_periods' => $history->maturedCount,
            'known_periods' => $history->knownCount,
            'on_time_periods' => $history->onTimeCount,
            'late_periods' => $history->lateCount,
            'legacy_periods' => $history->legacyCount,
            'worst_days_late_24m' => $history->worstDaysLate24m,
            'days_past_due' => $history->daysPastDueNow,
            'completed_loans' => $history->completedLoans,
            'clean_completed_loans' => $history->cleanCompletedLoans,
            'defaulted_loans' => $history->defaultedLoans,
            'written_off_loans' => $history->writtenOffLoans,
            'restructured_loans' => $history->restructuredLoans,
            'active_loans' => $exposure->activeLoans,
            'outstanding' => Centavos::toPesos($exposure->outstandingCentavos),
            'past_due_amount' => Centavos::toPesos($exposure->pastDueCentavos),
            'lendy_monthly_payments' => Centavos::toPesos($exposure->monthlyDebtCentavos),
            'requested_principal' => $data->applicationLoan === null ? null : Centavos::toPesos(Centavos::of($data->applicationLoan->principal_amount)),
            'has_valid_id' => $data->hasValidId,
            'capacity' => $capacity->toArray(),
        ];
    }
}
