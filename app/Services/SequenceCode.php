<?php

namespace App\Services;

use App\Exceptions\MalformedSequenceCodeException;

/**
 * The one place `BRW-000123` / `LA-000123` is parsed and incremented.
 *
 * Borrower::booted(), Loan::booted() and
 * App\Services\CsvImport\SequenceAllocator all allocate the next code as
 * "highest existing row's number, plus one", and they have to agree EXACTLY:
 * the allocator's job is to predict the codes the hooks will go on to issue, so
 * a discrepancy of one between them is a unique-key violation on an otherwise
 * ordinary row. Three copies of the arithmetic is three chances to drift, which
 * is why it lives here.
 *
 * ## Why it parses rather than casts
 *
 * `(int) substr($code, 4) + 1` — what all three used to do — silently answers 1
 * for anything it cannot read. See MalformedSequenceCodeException for what that
 * costs. So the code is matched against its exact form and a value that does not
 * match stops the allocation instead of poisoning it.
 *
 * ## Not a database sequence
 *
 * This deliberately reads the highest row rather than an AUTO_INCREMENT or a
 * counter table, because it has to interoperate with what is already in the
 * database on ten live deployments. Its safety comes entirely from the caller
 * holding `orderByDesc('id')->lockForUpdate()` in an open transaction — see
 * SequenceAllocator, which exists to hold that lock across a whole chunk.
 */
final class SequenceCode
{
    /**
     * Digits after the prefix. Six is what every existing code uses; longer
     * numbers are not padded down, they just get wider, so passing a million
     * members does not break the format.
     */
    public const PAD_LENGTH = 6;

    /**
     * The first code of a family, for a table with no rows in it at all.
     */
    public static function first(string $prefix): string
    {
        return self::format($prefix, 1);
    }

    /**
     * The code after `$lastCode`.
     *
     * @param  string|null  $lastCode  The highest existing row's code. A null or
     *                                 empty value is NOT "start again": it means
     *                                 a row exists whose code was lost, and
     *                                 restarting would collide with everything
     *                                 below it.
     * @param  string  $where  Enough to find that row, e.g. `borrowers.id 812`.
     *
     * @throws MalformedSequenceCodeException
     */
    public static function after(string $prefix, ?string $lastCode, string $where): string
    {
        if ($lastCode === null || ! preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $lastCode, $matches)) {
            throw MalformedSequenceCodeException::for($prefix, $lastCode, $where);
        }

        return self::format($prefix, (int) $matches[1] + 1);
    }

    private static function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }
}
