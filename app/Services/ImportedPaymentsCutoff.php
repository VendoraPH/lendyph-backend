<?php

namespace App\Services;

use App\Models\Loan;
use Carbon\CarbonInterface;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The day up to which an imported loan's file already counts its payments
 * (finish run round 6).
 *
 * A loan migrated in by the CSV importer carries the import run's as-of date
 * as `loans.imported_arrears_baseline` (CsvImportProcessor). Its balances,
 * paid columns and arrears are the file's figures as of that day, so a payment
 * dated on or before it would be counted twice: once in the file and again
 * here. Every new payment dated on or before it is refused, in words, on the
 * field that carries the date; one dated the day after is accepted.
 *
 * The one definition, used wherever a dated repayment is recorded:
 * - the requests that take a payment date from the client (Record Payment,
 *   Payment Entry and the payment preview), through rule(), so the 422 lands
 *   on `payment_date` before anything is locked;
 * - RepaymentService::recordRepaymentOnLockedLoan(), the one method every
 *   repayment and every payment preview is recorded through, under the loan's
 *   lock: auto-pay, an extension's "pay" and its preview included;
 * - the server-dated paths, before they lock: an extension's "pay" (on
 *   `interest_option`) and auto-pay (a skip with this reason).
 *
 * Days are compared as the release-date rule compares them
 * (StoreRepaymentRequest::notBeforeRelease()): the calendar day the payment
 * is stored with, in the app timezone, against the as-of date, a plain date.
 * A loan with no as-of date is unchanged. Voids and payments already recorded
 * are never touched.
 */
final class ImportedPaymentsCutoff
{
    /** The last day the imported file counts, `Y-m-d`; null when the loan has none. */
    public static function until(Loan $loan): ?string
    {
        $baseline = $loan->imported_arrears_baseline;

        if ($baseline === null) {
            return null;
        }

        return $baseline instanceof CarbonInterface
            ? $baseline->toDateString()
            : Carbon::parse((string) $baseline)->toDateString();
    }

    /** Whether a payment on `$day` (`Y-m-d`) is refused on this loan. */
    public static function refuses(Loan $loan, string $day): bool
    {
        $until = self::until($loan);

        return $until !== null && $day <= $until;
    }

    /** The refusal, in words, with the as-of date as "Jun 30, 2026". */
    public static function message(Loan $loan): string
    {
        $until = Carbon::parse((string) self::until($loan))->format('M j, Y');

        return "This loan's imported file already counts payments up to {$until}. Record only payments made after that date.";
    }

    /**
     * The validation rule for a request's payment date. A value the date rules
     * already refuse (not written `Y-m-d`) is left to them, as
     * notBeforeRelease() leaves it.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function rule(?Loan $loan): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($loan): void {
            if ($loan === null || ! is_string($value)) {
                return;
            }

            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($day === false || $day->format('Y-m-d') !== $value) {
                return;
            }

            if (self::refuses($loan, $value)) {
                $fail(self::message($loan));
            }
        };
    }

    /**
     * Refuse (422 on `$field`) a payment dated `$date` on this loan. `$date`
     * is read as the repayment stores it: the calendar day in the app
     * timezone.
     *
     * @throws ValidationException
     */
    public static function assertAllowed(Loan $loan, CarbonInterface|string $date, string $field = 'payment_date'): void
    {
        $day = $date instanceof CarbonInterface
            ? $date->copy()->setTimezone(config('app.timezone'))->toDateString()
            : Carbon::parse($date, config('app.timezone'))->toDateString();

        if (self::refuses($loan, $day)) {
            throw ValidationException::withMessages([$field => self::message($loan)]);
        }
    }
}
