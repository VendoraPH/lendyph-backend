<?php

namespace App\Services\Linda;

use App\Models\Loan;
use UnexpectedValueException;

/**
 * The Loan No. check (finish run round 6): whether a question holds an
 * imported loan's own number from the cooperative's file
 * (`loans.external_loan_no`, "L-2019-0042"), which the lookup parser can't
 * know, since the cooperative chose its form. A question holding one, as a
 * whole value, any case, is a loan lookup, answered on the server
 * (LindaAnswerer::lookup()) and never sent to the model.
 *
 * The question is read as the lookup parser reads it (LindaLookup::normalise()).
 * The values checked (self::candidates()):
 * - each whitespace-separated token, as typed and with the punctuation around
 *   it trimmed ("(L-2019-0042)?" checks "L-2019-0042");
 * - runs of two and three tokens joined by one space, for a Loan No. that
 *   holds spaces as the file stored it ("BC 2019 07"; a Loan No. with two
 *   spaces in a row, or more than three words, is not found this way);
 * - only values holding a digit, at most 50 characters (the column);
 * - a bare number of 1 to 4 digits only right after a loan word ("loan 42",
 *   "loan no. 42", "utang 42"), so a count or a year ("top 10", "2026") is
 *   never taken for a loan.
 *
 * The values go through one query on the column's unique index; its
 * collation (utf8mb4_unicode_ci) ignores case. Every loan of the deployment
 * counts, whatever the caller's branches, so a Loan No. never reaches the
 * model; the answer itself keeps the caller's branch scope. The longest
 * match wins. A value that matches nothing leaves the question to the rules
 * before this one.
 *
 * find() throws when the question or the database can't be read, and the
 * caller keeps the question on the server (fails closed).
 */
final class LindaLoanNumbers
{
    /** `loans.external_loan_no` is varchar(50). */
    private const MAX_LENGTH = 50;

    /** The fewest digits a bare number needs to be checked on its own. */
    private const BARE_DIGITS_MIN = 5;

    /** The most tokens one value spans. */
    private const MAX_WORDS = 3;

    /** Words after which a short bare number may be a Loan No. */
    private const LOAN_WORDS = ['loan', 'loans', 'no', 'num', 'number', 'utang', 'pautang', 'account', 'acct'];

    /**
     * The Loan No. the question holds, as stored, or null.
     *
     * @throws UnexpectedValueException when the question can't be read
     */
    public static function find(#[\SensitiveParameter] string $question): ?string
    {
        $candidates = self::candidates($question);

        if ($candidates === []) {
            return null;
        }

        $found = Loan::query()
            ->whereIn('external_loan_no', $candidates)
            ->orderByRaw('CHAR_LENGTH(external_loan_no) DESC')
            ->value('external_loan_no');

        return $found === null ? null : (string) $found;
    }

    /**
     * The values of a question checked against the Loan Nos.
     *
     * @return list<string>
     *
     * @throws UnexpectedValueException when the question can't be read
     */
    public static function candidates(#[\SensitiveParameter] string $question): array
    {
        if (! mb_check_encoding($question, 'UTF-8')) {
            throw new UnexpectedValueException('utf8');
        }

        $tokens = preg_split('/\s+/u', trim(LindaLookup::normalise($question)), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            throw new UnexpectedValueException('split');
        }

        $candidates = [];
        $count = count($tokens);

        foreach ($tokens as $at => $token) {
            $previous = $at === 0 ? '' : mb_strtolower(self::trimmed($tokens[$at - 1]));
            $afterLoanWord = in_array($previous, self::LOAN_WORDS, true);

            foreach ([$token, self::trimmed($token)] as $value) {
                if (self::checks($value, $afterLoanWord)) {
                    $candidates[$value] = true;
                }
            }

            for ($words = 2; $words <= self::MAX_WORDS && $at + $words <= $count; $words++) {
                $run = self::trimmed(implode(' ', array_slice($tokens, $at, $words)));

                if (self::checks($run, false)) {
                    $candidates[$run] = true;
                }
            }
        }

        return array_map('strval', array_keys($candidates));
    }

    /** A token without the punctuation around it. */
    private static function trimmed(string $token): string
    {
        $trimmed = preg_replace('/^[^\pL\pN]+|[^\pL\pN]+$/u', '', $token);

        if ($trimmed === null) {
            throw new UnexpectedValueException('trim');
        }

        return $trimmed;
    }

    private static function checks(string $value, bool $afterLoanWord): bool
    {
        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH || preg_match('/\d/u', $value) !== 1) {
            return false;
        }

        if (preg_match('/^\d+$/u', $value) !== 1) {
            return true;
        }

        return strlen($value) >= self::BARE_DIGITS_MIN || $afterLoanWord;
    }
}
