<?php

namespace App\Services\Binhs;

use App\Enums\TermUnit;
use App\Models\Loan;
use App\Services\LoanTermSchedule;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * The Amortization (BINHS) page's three schedules
 * (POST /loans/amortization-calculator/binhs), worked out on the server with
 * Brick\Math, exactly or to 50 decimal places (see self::SCALE), never in
 * floating point. It reproduces the page's own arithmetic (the frontend's
 * src/lib/binhs.ts) figure for figure:
 *
 * - The monthly rate is the annual rate ÷ 12 ÷ 100, and the level payment is
 *   the standard annuity payment (principal ÷ term when the rate is 0).
 * - Interest runs on the unrounded balance; only the figures shown are
 *   rounded, half up to the centavo (towards +∞ at a tie, as the page's
 *   Math.round did).
 * - The principal adds up exactly: the last row's principal is the principal
 *   less the rounded principal settled on the rows before it, and its payment
 *   follows from it (see self::lastPrincipal()). This differs on purpose from
 *   the page, which summed rounded rows and could read ₱99,999.99 for a
 *   ₱100,000.00 loan.
 * - Due dates step one calendar month from the start, on its day of the month
 *   or a shorter month's last day, and the first due date is the start date.
 * - The Share Capital Build-Up is a fixed amount every period.
 * - Worst Case (nothing paid) and Custom (the periods marked paid) carry the
 *   unpaid principal forward with a 20% penalty on it, added to the balance
 *   with the period's interest, which then compounds.
 *
 * What-if figures only: it writes nothing. A loan is read only for the
 * page's prefill (prefillFor()).
 */
final class BinhsCalculator
{
    /** The BINHS penalty: 20% of the principal carried unpaid. */
    public const PENALTY_RATE = '0.2';

    public const MAX_TERM_MONTHS = 60;

    /** Each rate period in words, for the prefill notes ("9% a month"). */
    private const RATE_PERIOD_WORDS = [
        'daily' => 'a day',
        'weekly' => 'a week',
        'bi_weekly' => 'every 2 weeks',
        'semi_monthly' => 'per half month',
        'monthly' => 'a month',
        'annual' => 'a year',
    ];

    /** The highest rate a year the calculator takes (BinhsCalculatorRequest). */
    public const MAX_ANNUAL_RATE = 100;

    /**
     * Decimal places a working figure that isn't a finite decimal is held to.
     *
     * - Sums and the 20% penalty are exact.
     * - Each product with the monthly rate, and the level payment, is kept
     *   exact when it is a finite decimal (₱29,971,651.74 at 100% a year is
     *   exactly ₱2,497,637.645 of interest, which must round up), and
     *   otherwise held to this many places, half to even.
     * - That keeps every figure within 10^-30 of its exact value, even 60
     *   months of compounding at 100% a year on ₱100,000,000.00, against the
     *   half-centavo a shown figure is rounded at.
     *
     * Fully exact fractions grew past a thousand digits on such a schedule
     * and took seconds a request.
     */
    private const SCALE = 50;

    /**
     * @param  string  $principal  pesos, up to 2 decimals
     * @param  string  $annualRate  percent a year, up to 4 decimals
     * @param  string  $scbuPerPeriod  pesos, up to 2 decimals
     * @param  list<bool>  $paidFlags  the Custom scenario's paid periods, from period 1; a missing one is unpaid
     * @return array{ideal: array{rows: list<array<string, mixed>>, totals: array<string, string>}, worst_case: array{rows: list<array<string, mixed>>, totals: array<string, string>}, custom: array{rows: list<array<string, mixed>>, totals: array<string, string>}}
     */
    public function calculate(string $principal, string $annualRate, int $termMonths, string $scbuPerPeriod, CarbonImmutable $startDate, array $paidFlags = []): array
    {
        $plan = $this->plan(BigDecimal::of($principal), BigRational::of($annualRate)->dividedBy(1200), $termMonths);
        $scbu = self::round2(BigDecimal::of($scbuPerPeriod));

        $dueDates = [];

        for ($i = 0; $i < $termMonths; $i++) {
            $dueDates[] = $startDate->addMonthsNoOverflow($i)->toDateString();
        }

        return [
            'ideal' => self::withTotals($this->ideal($plan, $scbu, $dueDates)),
            'worst_case' => self::withTotals($this->worstCase($plan, $scbu, $dueDates)),
            'custom' => self::withTotals($this->custom($plan, $scbu, $dueDates, $paidFlags)),
        ];
    }

    /**
     * The page's starting inputs for a loan: its principal, its rate as a rate
     * a year, its term in months and its first due date (the calculator's
     * `start_date`, see firstDueDateOf()), with a note in words for each
     * value left out.
     *
     * - The rate a year is the loan's rate times its periods in a year on the
     *   30-day-month convention (monthly × 12, annual × 1, any other period
     *   × 360 ÷ its days), exact and never rounded.
     * - It is left out (null) when it isn't a finite decimal (1% a week is
     *   360/7% a year) or is above the calculator's 100%.
     * - The term is left out when it is in days, or outside 1 to 60 months.
     *
     * So a prefill never suggests a value the calculator would refuse.
     *
     * @return array{prefill: array{loan_id: int, principal: string, annual_rate: string|null, term_months: int|null, start_date: string|null}, notes: list<string>}
     */
    public function prefillFor(Loan $loan): array
    {
        $period = $loan->interest_rate_frequency->value;
        $rate = BigDecimal::of((string) $loan->interest_rate)->strippedOfTrailingZeros();
        $quoted = $rate->__toString().'% '.self::RATE_PERIOD_WORDS[$period];
        $notes = [];

        try {
            $annual = $rate->toBigRational()
                ->multipliedBy(LoanTermSchedule::DAYS_PER_YEAR)
                ->dividedBy(LoanTermSchedule::PERIOD_DAYS[$period])
                ->toBigDecimal()
                ->strippedOfTrailingZeros();
            $annualRate = $annual->__toString();

            if ($annual->isGreaterThan(self::MAX_ANNUAL_RATE)) {
                $notes[] = "Annual rate left out: {$quoted} is {$annualRate}% a year, above the calculator's limit of ".self::MAX_ANNUAL_RATE.'%.';
                $annualRate = null;
            }
        } catch (RoundingNecessaryException) {
            $notes[] = "Annual rate left out: {$quoted} has no exact rate a year. Enter the annual rate.";
            $annualRate = null;
        }

        $term = (int) $loan->term;
        $termMonths = null;

        if ($loan->term_unit !== TermUnit::Months) {
            $notes[] = "Term left out: the loan's term is {$term} days, and the calculator takes months.";
        } elseif ($term < 1 || $term > self::MAX_TERM_MONTHS) {
            $notes[] = "Term left out: the loan's term of {$term} months is outside the calculator's 1 to ".self::MAX_TERM_MONTHS.' months.';
        } else {
            $termMonths = $term;
        }

        return [
            'prefill' => [
                'loan_id' => $loan->id,
                'principal' => BigDecimal::of((string) $loan->principal_amount)->toScale(2)->__toString(),
                'annual_rate' => $annualRate,
                'term_months' => $termMonths,
                'start_date' => $this->firstDueDateOf($loan),
            ],
            'notes' => $notes,
        ];
    }

    /**
     * The loan's first due date: its schedule's first period, or, while it has
     * no schedule (a draft), the date the schedule will give it: one payment
     * period after the start, on the start's day of the month (a shorter
     * month's last day) when it steps by calendar month, or the maturity date
     * for a single payment at maturity.
     */
    private function firstDueDateOf(Loan $loan): ?string
    {
        $scheduled = $loan->amortizationSchedules()->reorder('period_number')->value('due_date');

        if ($scheduled !== null) {
            return CarbonImmutable::parse($scheduled)->toDateString();
        }

        if ($loan->start_date === null) {
            return null;
        }

        $start = Carbon::parse($loan->start_date->toDateString());
        $term = max(1, (int) $loan->term);

        if ($loan->frequency === 'upon_maturity') {
            return ($loan->maturity_date ?? LoanTermSchedule::maturityDate($start, $term, $loan->term_unit->value, 'upon_maturity'))
                ->toDateString();
        }

        return LoanTermSchedule::instalments($start, $term, $loan->term_unit->value, $loan->frequency)[0]['due_date']->toDateString();
    }

    /**
     * The ideal plan, unrounded: the level payment and each period's
     * scheduled principal and interest. The last period takes the balance
     * left, so the scheduled principal adds up to the principal exactly.
     *
     * @return array{principal: BigDecimal|BigRational, rate: BigRational, level: BigDecimal|BigRational, zero: BigDecimal|BigRational, scheduled_principal: list<BigDecimal|BigRational>, scheduled_interest: list<BigDecimal|BigRational>}
     */
    private function plan(BigDecimal $principal, BigRational $rate, int $n): array
    {
        if ($rate->isZero()) {
            // Interest-free: every figure is a multiple of principal ÷ term,
            // so it is kept as an exact (small) fraction, and one that falls
            // exactly on a half-centavo rounds as it should.
            $principal = BigRational::of($principal);
            $level = $principal->dividedBy($n);
        } else {
            // P·r / (1 − (1 + r)^−n), written as P·r·g / (g − 1) with
            // g = (1 + r)^n, worked out exactly first.
            $growth = $rate->plus(1)->power($n);
            $level = self::decimal($rate->multipliedBy($principal)->multipliedBy($growth)->dividedBy($growth->minus(1)));
        }

        $scheduledPrincipal = [];
        $scheduledInterest = [];
        $balance = $principal;

        for ($i = 0; $i < $n; $i++) {
            $interest = self::times($balance, $rate);
            $principalPaid = $i === $n - 1 ? $balance : $level->minus($interest);
            $scheduledPrincipal[] = $principalPaid;
            $scheduledInterest[] = $interest;
            $balance = $balance->minus($principalPaid);
        }

        return [
            'principal' => $principal,
            'rate' => $rate,
            'level' => $level,
            'zero' => $principal instanceof BigRational ? BigRational::zero() : BigDecimal::zero(),
            'scheduled_principal' => $scheduledPrincipal,
            'scheduled_interest' => $scheduledInterest,
        ];
    }

    /**
     * Every period paid as scheduled. The balance shown steps down by the
     * rounded principal, and reads 0.00 where the rounding takes it below 0.
     * The last row takes the principal the rows before it left, so the
     * column adds up to the principal and the balance closes at 0.00.
     *
     * @param  array{principal: BigDecimal|BigRational, rate: BigRational, level: BigDecimal|BigRational, zero: BigDecimal|BigRational, scheduled_principal: list<BigDecimal|BigRational>, scheduled_interest: list<BigDecimal|BigRational>}  $plan
     * @param  list<string>  $dueDates
     * @return list<array<string, mixed>>
     */
    private function ideal(array $plan, BigDecimal $scbu, array $dueDates): array
    {
        $rows = [];
        $balance = self::round2($plan['principal']);
        $settled = BigDecimal::zero();
        $last = count($dueDates) - 1;

        foreach ($dueDates as $i => $dueDate) {
            $interest = self::round2($plan['scheduled_interest'][$i]);
            $principalPaid = $i === $last
                ? self::lastPrincipal($plan, $settled)
                : self::settledPrincipal($plan, $settled, $plan['scheduled_principal'][$i]);
            $settled = $settled->plus($principalPaid);
            $balance = $balance->minus($principalPaid);

            $rows[] = self::row($i, $dueDate, $principalPaid, $interest, BigDecimal::zero(), $scbu,
                $principalPaid->plus($interest)->plus($scbu),
                $balance->isNegative() ? BigDecimal::zero() : $balance);
        }

        return $rows;
    }

    /**
     * No period paid. Each row shows the principal carried so far and the
     * 20% penalty on it. The first period's interest is on the principal, the
     * second's on the ideal balance after period 1, and every later one on
     * the running balance, which adds each period's interest and penalty.
     * The payment shown is the level payment plus the build-up. Nothing is
     * settled, so the last row carries the whole principal.
     *
     * @param  array{principal: BigDecimal|BigRational, rate: BigRational, level: BigDecimal|BigRational, zero: BigDecimal|BigRational, scheduled_principal: list<BigDecimal|BigRational>, scheduled_interest: list<BigDecimal|BigRational>}  $plan
     * @param  list<string>  $dueDates
     * @return list<array<string, mixed>>
     */
    private function worstCase(array $plan, BigDecimal $scbu, array $dueDates): array
    {
        $rows = [];
        $carried = $plan['zero'];
        $running = $plan['principal'];
        $idealBalance = $plan['principal'];
        $payment = self::round2($plan['level']->plus($scbu));
        $last = count($dueDates) - 1;

        foreach ($dueDates as $i => $dueDate) {
            $carried = $carried->plus($plan['scheduled_principal'][$i]);
            $penalty = $carried->multipliedBy(self::PENALTY_RATE);

            if ($i === 0) {
                $interest = self::times($plan['principal'], $plan['rate']);
                $idealBalance = $idealBalance->minus($plan['scheduled_principal'][$i]);
                $running = $idealBalance->plus($interest)->plus($penalty);
            } elseif ($i === 1) {
                $interest = self::times($idealBalance, $plan['rate']);
                $running = $running->plus($interest)->plus($penalty);
            } else {
                $interest = self::times($running, $plan['rate']);
                $running = $running->plus($interest)->plus($penalty);
            }

            $shownPrincipal = $i === $last ? self::lastPrincipal($plan, BigDecimal::zero()) : self::round2($carried);

            $rows[] = self::row($i, $dueDate, $shownPrincipal, self::round2($interest), self::round2($penalty),
                $scbu, $payment, self::round2($running));
        }

        return $rows;
    }

    /**
     * The periods marked paid settle the scheduled principal plus any carried
     * from unpaid periods before them. An unpaid period carries its scheduled
     * principal, and adds its interest and a 20% penalty on everything carried
     * to the outstanding balance. Interest is always on that balance. The
     * last row, paid or carried, takes the principal the paid rows before it
     * left, and a paid last row's payment is that principal plus its
     * interest and build-up.
     *
     * @param  array{principal: BigDecimal|BigRational, rate: BigRational, level: BigDecimal|BigRational, zero: BigDecimal|BigRational, scheduled_principal: list<BigDecimal|BigRational>, scheduled_interest: list<BigDecimal|BigRational>}  $plan
     * @param  list<string>  $dueDates
     * @param  list<bool>  $paidFlags
     * @return list<array<string, mixed>>
     */
    private function custom(array $plan, BigDecimal $scbu, array $dueDates, array $paidFlags): array
    {
        $rows = [];
        $outstanding = $plan['principal'];
        $carried = $plan['zero'];
        $unpaidPayment = self::round2($plan['level']->plus($scbu));
        $settledShown = BigDecimal::zero();
        $last = count($dueDates) - 1;

        foreach ($dueDates as $i => $dueDate) {
            $scheduled = $plan['scheduled_principal'][$i];
            $interest = self::times($outstanding, $plan['rate']);

            if (($paidFlags[$i] ?? false) === true) {
                $settled = $carried->plus($scheduled);
                $outstanding = $outstanding->minus($settled);
                $carried = $plan['zero'];

                if ($i === $last) {
                    $shown = self::lastPrincipal($plan, $settledShown);
                    $payment = $shown->plus(self::round2($interest))->plus($scbu);
                } else {
                    $shown = self::settledPrincipal($plan, $settledShown, $settled);
                    $payment = $shown->isEqualTo(self::round2($settled))
                        ? self::round2($settled->plus($interest)->plus($scbu))
                        : $shown->plus(self::round2($interest))->plus($scbu);
                }

                $settledShown = $settledShown->plus($shown);

                $rows[] = self::row($i, $dueDate, $shown, self::round2($interest), BigDecimal::zero(),
                    $scbu, $payment,
                    $outstanding->isNegative() ? BigDecimal::zero() : self::round2($outstanding));

                continue;
            }

            $carried = $carried->plus($scheduled);
            $penalty = $carried->multipliedBy(self::PENALTY_RATE);
            $outstanding = $outstanding->plus($interest)->plus($penalty);
            $shown = $i === $last ? self::lastPrincipal($plan, $settledShown) : self::round2($carried);

            $rows[] = self::row($i, $dueDate, $shown, self::round2($interest), self::round2($penalty),
                $scbu, $unpaidPayment, self::round2($outstanding));
        }

        return $rows;
    }

    /**
     * A row's principal settled, rounded as the page rounds it, but never more
     * than the principal still left to settle.
     *
     * On an ordinary schedule the cap never binds. It does when each row's
     * share is under a centavo or so and rounds up every time (₱1.50 over 60
     * months at 0% is ₱0.025 a month, shown as ₱0.03): 59 rounded rows would
     * settle ₱1.77 and leave the last row at -₱0.27. Capped, the rows settle
     * ₱0.03 until the principal is used up, then ₱0.00, and the last row's
     * remainder is never below ₱0.00.
     *
     * @param  array{principal: BigDecimal|BigRational, rate: BigRational, level: BigDecimal|BigRational, zero: BigDecimal|BigRational, scheduled_principal: list<BigDecimal|BigRational>, scheduled_interest: list<BigDecimal|BigRational>}  $plan
     */
    private static function settledPrincipal(array $plan, BigDecimal $settledShown, BigDecimal|BigRational $settled): BigDecimal
    {
        return BigDecimal::min(self::round2($settled), self::lastPrincipal($plan, $settledShown));
    }

    /**
     * The last row's principal: the principal less the rounded principal the
     * rows before it settled, so the principal a schedule settles adds up to
     * the principal exactly. Interest is never moved: each row keeps its own.
     * settledPrincipal() keeps it from going below ₱0.00.
     *
     * @param  array{principal: BigDecimal|BigRational, rate: BigRational, level: BigDecimal|BigRational, zero: BigDecimal|BigRational, scheduled_principal: list<BigDecimal|BigRational>, scheduled_interest: list<BigDecimal|BigRational>}  $plan
     */
    private static function lastPrincipal(array $plan, BigDecimal $settledShown): BigDecimal
    {
        return self::round2($plan['principal'])->minus($settledShown);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(int $index, string $dueDate, BigDecimal $principal, BigDecimal $interest, BigDecimal $penalty, BigDecimal $scbu, BigDecimal $total, BigDecimal $runningBalance): array
    {
        return [
            'period' => $index + 1,
            'due_date' => $dueDate,
            'principal' => self::money($principal),
            'interest' => self::money($interest),
            'penalty' => self::money($penalty),
            'scbu' => self::money($scbu),
            'total_payment' => self::money($total),
            'running_balance' => self::money($runningBalance),
        ];
    }

    /**
     * The rows and the column totals the schedule table prints: each the sum
     * of the rounded figures shown (no running balance total).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    private static function withTotals(array $rows): array
    {
        $totals = [];

        foreach (['principal', 'interest', 'penalty', 'scbu', 'total_payment'] as $column) {
            $sum = BigDecimal::zero();

            foreach ($rows as $row) {
                $sum = $sum->plus($row[$column]);
            }

            $totals[$column] = self::money($sum);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * `$value` × the monthly rate: an exact fraction on an interest-free
     * schedule, otherwise a decimal (see self::decimal()).
     */
    private static function times(BigDecimal|BigRational $value, BigRational $rate): BigDecimal|BigRational
    {
        $product = $rate->multipliedBy($value);

        return $value instanceof BigRational ? $product : self::decimal($product);
    }

    /** The exact decimal when it is a finite one of at most self::SCALE places, else held to self::SCALE places. */
    private static function decimal(BigRational $value): BigDecimal
    {
        try {
            $exact = $value->toBigDecimal();

            if ($exact->getScale() <= self::SCALE) {
                return $exact;
            }
        } catch (RoundingNecessaryException) {
            // Not a finite decimal: held to self::SCALE places below.
        }

        return $value->toScale(self::SCALE, RoundingMode::HalfEven);
    }

    private static function round2(BigDecimal|BigRational $value): BigDecimal
    {
        return $value->toScale(2, RoundingMode::HalfCeiling);
    }

    private static function money(BigDecimal $value): string
    {
        return $value->isZero() ? '0.00' : $value->toScale(2)->__toString();
    }
}
