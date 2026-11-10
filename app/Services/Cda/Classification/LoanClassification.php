<?php

namespace App\Services\Cda\Classification;

use App\Models\AmortizationSchedule;
use App\Models\CdaReportingPeriod;
use App\Services\Accounting\AllowanceCalculator;
use App\Services\Accounting\LoanAgingBuilder;
use App\Services\Accounting\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * A year's loan classification and provision gap (CDA design §7), read only.
 *
 * - With a year-end capture (`cda_reporting_periods.year_end_capture`, taken
 *   at 23:58 Manila on Dec 31), the figures are the capture's, unchanged:
 *   loan balances can't be read for a past date afterwards.
 * - Without one, they are computed live, through the same AllowanceCalculator
 *   and LoanAgingBuilder the capture uses, as of today (Manila) or the period
 *   end if that is earlier, from current balances: `provisional`, with a
 *   Warning in words.
 *
 * Buckets: Current (no allowance) and the five past-due buckets of
 * AllowanceCalculator. The past-due buckets' counts, principal and required
 * allowance are the calculator's own (summed over branches, each branch's
 * bucket rounded once). The gap is required − the 1200 balance (every branch
 * plus journals with no branch). Interest due and unpaid is a memo only: the
 * books are on a cash basis. Money is integer centavos here.
 */
final class LoanClassification
{
    public const BASIS_CAPTURE = 'year_end_capture';

    public const BASIS_LIVE = 'live';

    public const TIMEZONE = 'Asia/Manila';

    /** @var list<string> */
    public const BUCKETS = ['current', '1_30', '31_60', '61_90', '91_120', 'over_120'];

    public const LABELS = [
        'current' => 'Current',
        '1_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        '91_120' => '91–120 days',
        'over_120' => 'Over 120 days',
    ];

    public const DPD_RANGES = [
        'current' => '0',
        '1_30' => '1–30',
        '31_60' => '31–60',
        '61_90' => '61–90',
        '91_120' => '91–120',
        'over_120' => 'Over 120',
    ];

    public const MEMO_NOTE = 'Interest due and unpaid, shown as a memo: the books are on a cash basis, so it is not in the balances.';

    public function __construct(
        private readonly AllowanceCalculator $allowance,
        private readonly LoanAgingBuilder $aging,
    ) {}

    /**
     * @return array{basis: string, as_of: string, captured_at: Carbon|null, provisional: bool, warning: string|null, basis_note: string,
     *     buckets: list<array{bucket: string, label: string, dpd_range: string, loan_count: int, principal: int, rate_percent: string, required: int}>,
     *     totals: array{loan_count: int, principal: int, required: int, booked: int, gap: int, unpaid_interest_due: int},
     *     booked: array{branches: array<int, int>, unassigned: int},
     *     loans: list<array<string, mixed>>}
     */
    public function for(CdaReportingPeriod $period): array
    {
        $capture = $period->year_end_capture;

        if ($period->year_end_captured_at !== null && is_array($capture)) {
            return $this->shape($capture, self::BASIS_CAPTURE, $period->year_end_captured_at);
        }

        $asOf = $this->liveDate($period);
        $result = $this->shape($this->live($asOf), self::BASIS_LIVE, null);
        $result['warning'] = 'There is no year-end capture yet, so these figures are computed from current balances, as of '
            .CarbonImmutable::parse($asOf)->format('M j, Y').'. They are kept at 23:58 on December 31.';

        return $result;
    }

    /** Today in Manila, or the period end once the year is over. */
    private function liveDate(CdaReportingPeriod $period): string
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $end = $period->period_end->toDateString();

        return $today < $end ? $today : $end;
    }

    /**
     * The capture's shape, computed now (YearEndCapture keeps the same keys).
     *
     * @return array<string, mixed>
     */
    private function live(string $asOf): array
    {
        $balances = $this->allowance->balances($asOf);
        $rows = $this->aging->rows($asOf);
        $interest = $this->unpaidInterestDue(array_map(fn (array $row) => (int) $row['loan_id'], $rows), $asOf);

        return [
            'as_of' => $asOf,
            'basis_note' => AllowanceCalculator::BASIS_NOTE,
            'rates' => $this->allowance->rates(),
            'allowance' => $this->allowance->forDate($asOf),
            'balances' => ['branches' => $balances['branches'], 'unassigned' => (int) $balances['unassigned']],
            'loans' => array_map(fn (array $row): array => [
                'loan_id' => (int) $row['loan_id'],
                'account_number' => $row['account_number'],
                'member_id' => (int) $row['member']['id'],
                'branch_id' => $row['branch']['id'] ?? null,
                'bucket' => $row['bucket'],
                'days_past_due' => (int) $row['days_past_due'],
                'oldest_late_due_date' => $row['oldest_late_due_date'],
                'is_past_due' => (bool) $row['is_past_due'],
                'outstanding_principal' => (int) $row['outstanding_principal'],
                'total_outstanding' => (int) $row['total_outstanding'],
                'past_due_amount' => (int) $row['past_due_amount'],
                'unpaid_interest_due' => $interest[(int) $row['loan_id']] ?? 0,
            ], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $capture
     * @return array<string, mixed>
     */
    private function shape(array $capture, string $basis, ?Carbon $capturedAt): array
    {
        $loans = array_values(array_map(fn ($row) => (array) $row, (array) ($capture['loans'] ?? [])));
        $rates = [];

        foreach ((array) ($capture['rates'] ?? []) as $rate) {
            $rates[(string) $rate['bucket']] = self::percent((string) $rate['rate_percent']);
        }

        $pastDue = [];

        foreach ((array) ($capture['allowance']['branches'] ?? []) as $branch) {
            foreach ((array) $branch['buckets'] as $bucket) {
                $key = (string) $bucket['bucket'];
                $pastDue[$key]['loan_count'] = ($pastDue[$key]['loan_count'] ?? 0) + (int) $bucket['loan_count'];
                $pastDue[$key]['principal'] = ($pastDue[$key]['principal'] ?? 0) + (int) $bucket['principal'];
                $pastDue[$key]['required'] = ($pastDue[$key]['required'] ?? 0) + (int) $bucket['required'];
                $rates[$key] ??= self::percent((string) $bucket['rate_percent']);
            }
        }

        $current = array_values(array_filter($loans, fn (array $row) => ($row['bucket'] ?? null) === 'current'));

        $buckets = array_map(fn (string $bucket): array => [
            'bucket' => $bucket,
            'label' => self::LABELS[$bucket],
            'dpd_range' => self::DPD_RANGES[$bucket],
            'loan_count' => $bucket === 'current' ? count($current) : ($pastDue[$bucket]['loan_count'] ?? 0),
            'principal' => $bucket === 'current'
                ? array_sum(array_map(fn (array $row) => (int) $row['outstanding_principal'], $current))
                : ($pastDue[$bucket]['principal'] ?? 0),
            'rate_percent' => $bucket === 'current' ? '0.00' : ($rates[$bucket] ?? '0.00'),
            'required' => $bucket === 'current' ? 0 : ($pastDue[$bucket]['required'] ?? 0),
        ], self::BUCKETS);

        $branches = [];

        foreach ((array) ($capture['balances']['branches'] ?? []) as $branchId => $balance) {
            $branches[(int) $branchId] = (int) $balance;
        }

        $unassigned = (int) ($capture['balances']['unassigned'] ?? 0);
        $booked = array_sum($branches) + $unassigned;
        $required = array_sum(array_column($buckets, 'required'));

        return [
            'basis' => $basis,
            'as_of' => (string) $capture['as_of'],
            'captured_at' => $capturedAt,
            'provisional' => $basis === self::BASIS_LIVE,
            'warning' => null,
            'basis_note' => (string) ($capture['basis_note'] ?? AllowanceCalculator::BASIS_NOTE),
            'buckets' => $buckets,
            'totals' => [
                'loan_count' => array_sum(array_column($buckets, 'loan_count')),
                'principal' => array_sum(array_column($buckets, 'principal')),
                'required' => $required,
                'booked' => $booked,
                'gap' => $required - $booked,
                'unpaid_interest_due' => array_sum(array_map(fn (array $row) => (int) ($row['unpaid_interest_due'] ?? 0), $loans)),
            ],
            'booked' => ['branches' => $branches, 'unassigned' => $unassigned],
            'loans' => $loans,
        ];
    }

    /**
     * A rate as the API shows percents: at least two decimals, never rounded
     * ("5" → "5.00", "12.345" stays).
     */
    public static function percent(string $rate): string
    {
        [$whole, $decimals] = array_pad(explode('.', $rate, 2), 2, '');

        return $whole.'.'.str_pad($decimals, 2, '0');
    }

    /**
     * Interest on periods due on or before the date and still unpaid, per
     * loan, in centavos (as YearEndCapture keeps it).
     *
     * @param  list<int>  $loanIds
     * @return array<int, int>
     */
    private function unpaidInterestDue(array $loanIds, string $asOf): array
    {
        if ($loanIds === []) {
            return [];
        }

        return AmortizationSchedule::query()
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->whereDate('due_date', '<=', $asOf)
            ->groupBy('loan_id')
            ->selectRaw('loan_id, COALESCE(SUM('.AmortizationSchedule::remainingInterestSql().'), 0) as interest')
            ->pluck('interest', 'loan_id')
            ->map(fn ($interest) => (int) Money::toCentavos((string) $interest))
            ->all();
    }
}
