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
 *   it and a trailing possessive dropped ("(L-2019-0042)?", "L-2019-0042's"
 *   check "L-2019-0042");
 * - its parts split on "/", "," and ";" ("L-1/L-2" checks "L-1" and "L-2"),
 *   and runs of two and three parts joined by one space, for a Loan No. that
 *   holds spaces as the file stored it ("BC 2019 07's" checks "BC 2019 07";
 *   a Loan No. with two spaces in a row, or more than three words, is not
 *   found this way);
 * - never a value written like one of the app's own numbers ("LN-0042",
 *   "PN 42"), which the parser reads (LindaLookup::isKnownCode());
 * - at most 50 characters (the column);
 * - values holding a digit anywhere; a value with no digit only right after a
 *   loan word ("loan ABC-XYZ", "loan no. ABCD"; finish run round 7), and never
 *   a loan word itself, so a question without a loan word checks nothing new;
 * - a bare number of 1 to 4 digits only right after a loan word ("loan 42",
 *   "loan no. 42", "utang 42"), so a count or a year ("top 10", "2026") is
 *   never taken for a loan;
 * - a value glued to a loan word by ".", "#" or ":" ("Loan No.BC-07",
 *   "loan#ABC-XYZ", "Loan No:BC-07"; round 7) counts as right after it, and
 *   so does a run of two or three words that starts right after a loan word
 *   or with a glued value ("loan ABC XYZ", "Loan No.BC 2019 07").
 *
 * The values go through one query on the column's unique index; its
 * collation (utf8mb4_unicode_ci) ignores case. Every loan of the deployment
 * counts, whatever the caller's branches, so a Loan No. never reaches the
 * model; the answer itself keeps the caller's branch scope. A match holding a
 * digit wins, then the longest (so "loan summary for loan BC-07" finds BC-07,
 * not a Loan No. "SUMMARY"). A value that matches nothing leaves the question to the rules
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

    /** Words after which a short bare number, or a value with no digit, may be a Loan No. */
    private const LOAN_WORDS = ['loan', 'loans', 'no', 'num', 'number', 'utang', 'pautang', 'account', 'acct'];

    /** A loan word with a value glued to it by ".", "#" or ":" ("No.BC-07", "loan#ABC"). */
    private const GLUED = '/^(?<word>\pL+)[.#:]+(?<value>.+)$/u';

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
            ->orderByRaw("external_loan_no REGEXP '[0-9]' DESC")
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

        $tokens = self::split('/\s+/u', trim(LindaLookup::normalise($question)));
        $candidates = [];

        // Each token as typed, whole: a Loan No. may hold a slash or a comma.
        foreach ($tokens as $token) {
            foreach ([$token, self::cleaned($token)] as $value) {
                $candidates[$value] = self::checks($value, false);
            }
        }

        // Its parts, split on "/", "," and ";" ("L-1/L-2"), each alone and in
        // runs of two and three for a Loan No. holding spaces.
        $parts = [];
        foreach ($tokens as $token) {
            array_push($parts, ...self::split('/[\/,;]+/u', $token));
        }

        $count = count($parts);

        // The word before each part, skipping marks that clean to nothing
        // ("loan # ABC-XYZ" reads ABC-XYZ as right after "loan").
        $previous = '';

        foreach ($parts as $at => $part) {
            if ($at > 0) {
                $before = mb_strtolower(self::cleaned($parts[$at - 1]));
                $previous = $before === '' ? $previous : $before;
            }

            $afterLoanWord = in_array($previous, self::LOAN_WORDS, true);
            $value = self::cleaned($part);
            $candidates[$value] = ($candidates[$value] ?? false) || self::checks($value, $afterLoanWord);

            // A run right after a loan word counts as after it ("loan ABC XYZ").
            for ($words = 2; $words <= self::MAX_WORDS && $at + $words <= $count; $words++) {
                $run = self::cleaned(implode(' ', array_slice($parts, $at, $words)));
                $candidates[$run] = ($candidates[$run] ?? false) || self::checks($run, $afterLoanWord);
            }

            // A value glued to a loan word, alone and with the parts after it
            // ("Loan No.BC 2019 07" checks "BC", "BC 2019" and "BC 2019 07").
            $glued = self::gluedToLoanWord($part);
            if ($glued !== null) {
                $candidates[$glued] = ($candidates[$glued] ?? false) || self::checks($glued, true);

                for ($words = 2; $words <= self::MAX_WORDS && $at + $words <= $count; $words++) {
                    $run = self::cleaned($glued.' '.implode(' ', array_slice($parts, $at + 1, $words - 1)));
                    $candidates[$run] = ($candidates[$run] ?? false) || self::checks($run, true);
                }
            }
        }

        return array_values(array_map('strval', array_keys(array_filter($candidates))));
    }

    /**
     * A token or a run without the punctuation around it and a trailing
     * possessive ("07's", "L-2019-0042’s"), as LindaFinder::nameWords() reads
     * a name.
     */
    private static function cleaned(string $value): string
    {
        $trimmed = self::trimmed($value);
        $bare = preg_replace("/['’]s$/iu", '', $trimmed);

        if ($bare === null) {
            throw new UnexpectedValueException('possessive');
        }

        return $bare === $trimmed ? $trimmed : self::trimmed($bare);
    }

    /**
     * The value glued to a loan word ("No.BC-07" gives "BC-07"), or null when
     * the part doesn't start with a loan word and a glue mark.
     */
    private static function gluedToLoanWord(string $part): ?string
    {
        $matched = preg_match(self::GLUED, self::trimmed($part), $glued);

        if ($matched === false) {
            throw new UnexpectedValueException('glued');
        }

        if ($matched !== 1 || ! in_array(mb_strtolower($glued['word']), self::LOAN_WORDS, true)) {
            return null;
        }

        return self::cleaned($glued['value']);
    }

    /** @return list<string> */
    private static function split(string $pattern, string $subject): array
    {
        $parts = preg_split($pattern, $subject, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            throw new UnexpectedValueException('split');
        }

        return $parts;
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
        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        // No digit: only right after a loan word, and never a loan word itself.
        if (preg_match('/\d/u', $value) !== 1) {
            return $afterLoanWord && ! in_array(mb_strtolower($value), self::LOAN_WORDS, true);
        }

        // The app's own numbers are the parser's (LN-, RCP- and the rest).
        if (LindaLookup::isKnownCode($value)) {
            return false;
        }

        if (preg_match('/^\d+$/u', $value) !== 1) {
            return true;
        }

        return strlen($value) >= self::BARE_DIGITS_MIN || $afterLoanWord;
    }
}
