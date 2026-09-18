<?php

namespace App\Services\Accounting;

/**
 * Centavo arithmetic for the accounting module. The PHP half of
 * `@/lib/accounting/money` on the frontend — the two must agree exactly.
 *
 * Every amount in accounting is an integer number of centavos. Pesos are a
 * display format and nothing else. The reason is narrow and non-negotiable: a
 * posted journal must satisfy `debit === credit` exactly, and IEEE-754 cannot
 * represent 0.1 — sum a few thousand peso floats and the books drift off
 * balance by a centavo nobody can account for. Integers have no such failure
 * mode below 2^53 centavos (≈ ₱90 trillion), comfortably beyond any portfolio
 * this system will hold.
 *
 * Convert at the edges: {@see self::toCentavos()} where request payloads come
 * in, {@see self::format()} where figures go out.
 */
final class Money
{
    /** A positive decimal with at most one dot. Grouping is stripped first. */
    private const DECIMAL = '/^\d+(\.\d+)?$/';

    /**
     * Characters people and currency formatters add around an amount: the peso
     * sign, thousands separators, and whitespace — including the non-breaking
     * and narrow no-break spaces `Intl.NumberFormat` emits on the frontend.
     */
    private const NOISE = '/[₱,\s\x{00A0}\x{202F}]/u';

    /**
     * Pesos above this are refused rather than silently truncated. 15 digits of
     * pesos is ₱999 trillion; the centavo figure still fits a 64-bit integer,
     * and anything larger is a typo or an attack, never a balance.
     */
    private const MAX_PESO_DIGITS = 15;

    /**
     * The ceiling itself: one peso past the largest {@see self::MAX_PESO_DIGITS}
     * allows, so `>= MAX_PESOS` is "too many digits" for a number the same way
     * `strlen() > MAX_PESO_DIGITS` is for a string.
     */
    private const MAX_PESOS = 1_000_000_000_000_000;

    /**
     * The largest amount any single figure in the books may hold, in centavos.
     *
     * Public because the ceiling was worth nothing where it sat. This class is
     * a boundary parser and the journals module never calls it — a journal line
     * arrives as integer centavos, already converted by the frontend. Meanwhile
     * `debit`/`credit` are UNSIGNED BIGINT, topping out near ₱184 quintillion,
     * and a BALANCED pair of ₱92 quadrillion lines satisfies both CHECK
     * constraints, passes {@see self::isBalanced()}, clears the "records
     * nothing" guard, and lands in the trial balance, the general ledger and
     * the dashboard looking entirely ordinary.
     *
     * So the bound is exported and applied where the numbers actually enter:
     * `ValidatesJournalLines::lineRules()` at the HTTP boundary, and
     * `JournalPoster::post()` on the recomputed total — which is the path the
     * automatic engine takes without going near a FormRequest.
     *
     * ## Why 2^53 rather than the peso-digit ceiling above
     *
     * {@see self::MAX_PESOS} bounds what this class will PARSE — a digit count,
     * chosen so a typo cannot become a balance. This is a different bound, and
     * a lower one: it is the largest centavo figure the module's own arithmetic
     * stays EXACT at.
     *
     * {@see self::sum()} accepts numeric strings (a `decimal:2` cast arrives
     * off the wire as one) and routes every value through a float to do so.
     * Below 2^53 centavos that round trip is lossless; above it, it is not —
     * 9007199254740993 comes back as ...992. `JournalPoster::post()` recomputes
     * `total_debit`/`total_credit` through `sum()`, so a total past this point
     * would be stored a few centavos away from what its own lines add up to,
     * and no report joins the header to the lines to catch it.
     *
     * 2^53 centavos is ≈ ₱90 trillion — the figure this file's class docblock
     * already names as the point below which integers have no failure mode, and
     * comfortably beyond any portfolio this system will hold.
     *
     * INCLUSIVE: the largest value that is allowed, so it can be handed
     * straight to Laravel's `max:` rule.
     */
    public static function maxCentavos(): int
    {
        return 9_007_199_254_740_992;
    }

    /**
     * Parses request input or a decimal string into centavos.
     *
     * Returns `null` for anything that is not a usable amount — blank,
     * whitespace, text, or negative. Blank is deliberately `null` rather than
     * `0`: in a journal line an empty debit means "this side is unused", while
     * ₱0.00 means "this side is zero", and the entry form has to tell them
     * apart.
     *
     * Negatives are refused because direction belongs to the column, not the
     * sign. A "-100" debit is a 100 credit, and accepting both spellings would
     * let two visually different entries post the same numbers.
     *
     * Strings are parsed digit by digit rather than through a float, so
     * "1.555" lands on 156 by arithmetic rather than by an epsilon nudge, and a
     * large amount cannot lose its last centavo to binary rounding on the way
     * in. Ints and floats — what `json_decode` hands a controller — go through
     * the same half-away-from-zero rule the frontend applies.
     */
    public static function toCentavos(string|int|float|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        // The ceiling applies to ALL THREE branches, not only to strings.
        //
        // It used to guard the string path alone, which is the path a JSON
        // payload never takes: `json_decode` hands a controller an int or a
        // float, and those two went through unbounded. A JSON number past
        // 2^53 is already a float by the time PHP sees it, so `* 100` lands on
        // a value whose last centavos are noise — and the result is a plausible
        // integer that silently differs from what was sent, on the one field in
        // this system where "close" has no meaning.
        //
        // NOTE this is a boundary PARSER, and the journals module does not call
        // it: a journal line arrives already converted to integer centavos by
        // the frontend, so nothing here ever sees it. The bound that protects
        // the ledger is self::maxCentavos(), applied in ValidatesJournalLines
        // and JournalPoster::post(). Keep the two in step.
        if (is_int($value)) {
            return $value < 0 || $value >= self::MAX_PESOS ? null : $value * 100;
        }

        if (is_float($value)) {
            // Note the float branch is only exactly representable well below
            // this bound — a double holds whole centavos up to 2^53, i.e. about
            // ₱90 trillion. The ceiling is stated identically to the other two
            // branches so all three refuse the same inputs; anything a caller
            // sends as a float above ₱90tn was already approximate when it
            // arrived, and no ceiling here can un-approximate it. Send large
            // amounts as strings.
            if (! is_finite($value) || $value < 0 || $value >= self::MAX_PESOS) {
                return null;
            }

            return self::roundHalfAwayFromZero($value * 100);
        }

        $cleaned = preg_replace(self::NOISE, '', $value) ?? '';

        if ($cleaned === '' || preg_match(self::DECIMAL, $cleaned) !== 1) {
            return null;
        }

        [$pesos, $fraction] = array_pad(explode('.', $cleaned, 2), 2, '');
        $pesos = ltrim($pesos, '0');

        if (strlen($pesos) > self::MAX_PESO_DIGITS) {
            return null;
        }

        return (int) ($pesos === '' ? 0 : $pesos) * 100 + self::fractionToCentavos($fraction);
    }

    /**
     * The centavo value of a decimal fraction, rounded half away from zero.
     *
     * A carry is possible and is the caller's to absorb only in theory: "0.999"
     * rounds its fraction to 100 centavos, which this folds back into a whole
     * peso rather than returning an impossible 100.
     */
    private static function fractionToCentavos(string $fraction): int
    {
        if ($fraction === '') {
            return 0;
        }

        $centavos = (int) str_pad(substr($fraction, 0, 2), 2, '0');
        $rest = substr($fraction, 2);

        // Everything past the second decimal is at least half a centavo exactly
        // when its leading digit is 5 or more — "0.4999" is below the boundary,
        // "0.5" and "0.50001" are both on or above it.
        if ($rest !== '' && $rest[0] >= '5') {
            $centavos++;
        }

        return $centavos;
    }

    /** Centavos back to pesos, for display or for an API that wants decimals. */
    public static function fromCentavos(int $centavos): float
    {
        return $centavos / 100;
    }

    /**
     * Total of a list, skipping absent values. Exact, because integers.
     *
     * Strings are accepted because they are what arrives off the wire: a
     * `decimal:2` cast serialises to a JSON string, and every centavo field is
     * typed as a number on the strength of a contract nothing checks at the
     * boundary. One unparseable row is skipped rather than allowed to poison
     * the whole total into NAN.
     *
     * Fractional values are rounded per value instead of accumulating, because
     * a centavo is the smallest unit that exists here and a fraction of one is
     * already corrupt. This is a no-op for every legitimate caller.
     *
     * @param  array<int, string|int|float|null>  $values
     */
    public static function sum(array $values): int
    {
        $total = 0;

        foreach ($values as $value) {
            if ($value === null || ! is_numeric($value)) {
                continue;
            }

            $amount = (float) $value;

            if (! is_finite($amount)) {
                continue;
            }

            $total += fmod($amount, 1.0) === 0.0
                ? (int) $amount
                : self::roundHalfAwayFromZero($amount);
        }

        return $total;
    }

    /**
     * Whether two sides agree. Exact equality, on purpose — there is no
     * tolerance band in double-entry bookkeeping, and integers make none
     * necessary.
     */
    public static function isBalanced(int $debit, int $credit): bool
    {
        return $debit === $credit;
    }

    /** Peso-formatted for display: 150050 → "₱1,500.50". */
    public static function format(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';

        return $sign.'₱'.number_format(abs($centavos) / 100, 2, '.', ',');
    }

    /**
     * Rounds to a whole centavo, half away from zero.
     *
     * Multiplying by 100 can land a hair below the true value
     * (1.555 * 100 === 155.49999999999997). The epsilon nudge pulls such cases
     * back onto the intended boundary before rounding, so 1.555 becomes 156
     * rather than 155 — matching `roundHalfUp` in the frontend's money module,
     * which exists for exactly the same reason.
     */
    private static function roundHalfAwayFromZero(float $centavos): int
    {
        $nudged = $centavos + ($centavos <=> 0) * PHP_FLOAT_EPSILON * abs($centavos);

        return (int) round($nudged);
    }
}
