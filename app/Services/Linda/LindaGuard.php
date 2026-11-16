<?php

namespace App\Services\Linda;

/**
 * The local guard (security review S3), run after the matcher misses and
 * before any model call: a question that names a record or asks to look one
 * up is never sent to the model. v1 can't answer lookups, so the assistant
 * answers it with LindaAnswerer::notYet() for the fitting intent, locally.
 *
 * It catches:
 * - the app's own record numbers: application (LA-), loan account (LN-),
 *   promissory note (PN-) and statement (SOA-) numbers; receipts (RCP-),
 *   share capital entries and receipts (SC-, SCV-), journals (JE-),
 *   adjustments (ADJ-), GCash (GC-), savings accounts, transactions,
 *   interest lines and time deposits (SAV-, SVT-, SVI-, TD-), portal
 *   requests (REQ-), mediation cases (MC-), member and co-maker codes (BRW-,
 *   CMK-), and any other letters-dash-digits code (a membership number);
 * - an email, a Philippine mobile number, or a run of 7 or more digits;
 * - lookup wording: find, search, look up, lookup, hanapin, "sino si",
 *   "details of", "profile of".
 *
 * Dates, amounts and the period words the matcher reads never trip it.
 */
final class LindaGuard
{
    /** Record-number prefixes, by the lookup they belong to. */
    private const LOAN_CODES = ['LA', 'LN', 'PN', 'SOA'];

    private const TRANSACTION_CODES = ['RCP', 'SC', 'SCV', 'JE', 'ADJ', 'GC', 'SAV', 'SVT', 'SVI', 'TD', 'REQ', 'MC'];

    private const MEMBER_CODES = ['BRW', 'CMK'];

    /** Words that make a lookup about a payment or record rather than a member. */
    private const TRANSACTION_WORDS = ['receipt', 'receipts', 'payment', 'payments', 'reference', 'transaction', 'transactions', 'resibo', 'deposit', 'journal'];

    private const LOAN_WORDS = ['loan', 'loans', 'utang', 'pautang'];

    private const LOOKUP_PATTERN = '/(?<![\pL\pN])(find|search|look\s+up|lookup|hanapin|sino\s+si|details\s+of|profile\s+of)(?![\pL\pN])/iu';

    private const EMAIL_PATTERN = '/[\pL\pN._%+\-]+@[\pL\pN.\-]+\.\pL{2,}/u';

    private const MOBILE_PATTERN = '/(?<!\d)(?:\+?63|0)[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)/';

    private const DIGIT_RUN_PATTERN = '/\d{7,}/';

    private const CODE_PATTERN = '/(?<![\pL\pN])([A-Za-z]{1,5})(?:-[A-Za-z]{2,5})?-\d{3,}(?:-\d+)*(?![\pL\pN])/u';

    /**
     * The lookup intent a question asks for, or null when it isn't one.
     */
    public static function check(#[\SensitiveParameter] string $question): ?LindaIntent
    {
        if (preg_match_all(self::CODE_PATTERN, $question, $codes) > 0) {
            return self::byCode(array_map('strtoupper', $codes[1]), $question);
        }

        if (preg_match(self::EMAIL_PATTERN, $question) === 1 || preg_match(self::MOBILE_PATTERN, $question) === 1) {
            return LindaIntent::BorrowerLookup;
        }

        if (preg_match(self::DIGIT_RUN_PATTERN, $question) === 1 || preg_match(self::LOOKUP_PATTERN, $question) === 1) {
            return self::byWords($question);
        }

        return null;
    }

    /** @param  list<string>  $prefixes  the codes' first parts, upper case */
    private static function byCode(array $prefixes, string $question): LindaIntent
    {
        foreach ($prefixes as $prefix) {
            if (in_array($prefix, self::LOAN_CODES, true)) {
                return LindaIntent::LoanDetails;
            }
        }

        foreach ($prefixes as $prefix) {
            if (in_array($prefix, self::TRANSACTION_CODES, true)) {
                return LindaIntent::TransactionLookup;
            }
        }

        foreach ($prefixes as $prefix) {
            if (in_array($prefix, self::MEMBER_CODES, true)) {
                return LindaIntent::BorrowerLookup;
            }
        }

        return self::byWords($question);
    }

    /** A lookup with no record number: about a payment, a loan, else a member. */
    private static function byWords(string $question): LindaIntent
    {
        $words = LindaText::words(LindaText::clean($question));

        return match (true) {
            array_intersect($words, self::TRANSACTION_WORDS) !== [] => LindaIntent::TransactionLookup,
            array_intersect($words, self::LOAN_WORDS) !== [] => LindaIntent::LoanDetails,
            default => LindaIntent::BorrowerLookup,
        };
    }
}
