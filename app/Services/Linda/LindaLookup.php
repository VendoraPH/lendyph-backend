<?php

namespace App\Services\Linda;

/**
 * The local lookup parser: whether a question asks about one record or one
 * person, and which. A lookup is answered on the server and never sent to
 * the model, so no member name, record number, email or phone number ever
 * reaches it. Pure: no database access.
 *
 * It reads English, Tagalog/Taglish and Bisaya/Cebuano, in this order:
 * 1. A record number: member and co-maker codes (BRW-, CMK-), loan numbers
 *    (LA-, LN-, PN-, SOA-), receipts and the other transaction codes (RCP-,
 *    SC-, SCV-, JE-, ADJ-, GC-, SAV-, SVT-, SVI-, TD-, REQ-, MC-), any other
 *    letters-dash-digits code (a membership number), and the common codes
 *    written with a space, a hash or nothing ("LN 000042", "rcp#123").
 * 2. An email or a Philippine mobile number: a member lookup, but never
 *    passed on as a name.
 * 3. A run of 7 or more digits, or a record word with a number ("loan 42",
 *    "member 45"): a lookup with no target, so the server asks for the
 *    full number.
 * 4. Lookup wording: find, search, look up, hanapin, pangitaa, "sino si",
 *    "kinsa si", "details of", "detalye sa", "profile of", and "who is"
 *    before a capitalised name.
 * 5. A person marker (ni, si, kang, kay, nina, sina, kina) with a balance,
 *    loan or payment word anywhere: "utang ni Juan", "bayad kang Pedro". It
 *    leans towards local: the marker and the word make it a lookup even
 *    when no name follows. Only "kay" before a common word reads as
 *    "because" (Bisaya) and is no marker.
 * 6. English: "<name>'s balance", "balance of <name>", "how much does
 *    <name> owe", "did <name> pay".
 *
 * A name is a member's name, so a name lookup is always a member lookup.
 * The name is the words after the marker (or before "'s"), up to the first
 * filler, period, subject or record word, as typed: case and spaces kept.
 * A leading title ("Aling", "Mang", "Mr.") is left out, and a name made only
 * of month names ("June") is no name.
 */
final class LindaLookup
{
    /** Record-number prefixes, by the kind of lookup they belong to. */
    private const MEMBER_CODES = ['BRW', 'CMK'];

    private const LOAN_CODES = ['LA', 'LN', 'PN', 'SOA'];

    private const RECEIPT_CODES = ['RCP', 'SC', 'SCV', 'JE', 'ADJ', 'GC', 'SAV', 'SVT', 'SVI', 'TD', 'REQ', 'MC'];

    private const CODE_PATTERN = '/(?<![\pL\pN])([A-Za-z]{1,5})(?:-[A-Za-z]{2,5})?-\d{3,}(?:-\d+)*(?![\pL\pN])/u';

    /** The common codes written with a space, a hash or nothing between the prefix and the number. */
    private const LOOSE_CODE_PATTERN = '/(?<![\pL\pN])(BRW|CMK|LN|LA|PN|RCP)[ \t]*#?[ \t]*(\d{3,})(?![\pL\pN])/iu';

    private const EMAIL_PATTERN = '/[\pL\pN._%+\-]+@[\pL\pN.\-]+\.\pL{2,}/u';

    private const MOBILE_PATTERN = '/(?<!\d)(?:\+?63|0)[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)/';

    private const DIGIT_RUN_PATTERN = '/\d{7,}/';

    /**
     * A record word with a number: "loan 42", "member no. 45", "receipt #123".
     * Not a year, an amount, a percentage or a length ("loan 12 months").
     */
    private const NUMBERED_RECORD_PATTERN = '/(?<![\pL\pN])(loan|receipt|resibo|reference|ref|transaction|account|member|borrower|application)\s*(?:no\.?|number|num|#)?\s*#?\s*(?!(?:19|20)\d{2}(?!\d))\d{2,}(?![\d,.%])(?!\s*(?:months?|days?|weeks?|years?|yrs?|buwan|bulan|adlaw|araw|semana|linggo|tuig|taon|ka|pesos?|php|k)(?![\pL]))/iu';

    private const LOOKUP_PATTERN = '/(?<![\pL\pN])(find(?!\s+out)|search|look\s+up|look\s+for|lookup|hanapin|hanapan|pangitaa|pangitaon|pangitai|pangita|sino\s+si|kinsa\s+si|details?\s+of|detalye\s+(?:sa|ni|ng|kang)|profile\s+(?:of|ni)|info(?:rmation)?\s+(?:on|about|of)|impormasyon\s+(?:sa|ni|ng|kang))(?![\pL\pN])/iu';

    /** "Who is" before a capitalised word: "Who is Juan Dela Cruz?". */
    private const WHO_IS_PATTERN = '/(?<![\pL\pN])(?i:who\s+is)\s+(?=\p{Lu})/u';

    /** Words that make a lookup with no name about a receipt or a loan; any other is about a member. */
    private const RECEIPT_WORDS = ['receipt', 'receipts', 'resibo', 'payment', 'payments', 'reference', 'ref', 'transaction', 'transactions', 'deposit', 'journal'];

    private const LOAN_WORDS = ['loan', 'loans', 'pautang', 'application'];

    /** Markers before a person's name. */
    private const PERSON_MARKERS = ['ni', 'si', 'kang', 'kay', 'nina', 'sina', 'kina'];

    /** Balance, loan and payment words: one beside a person marker makes a lookup. */
    private const MONEY_WORDS = [
        'balance', 'balances', 'balanse', 'balansi', 'outstanding', 'debt', 'debts', 'owe', 'owes', 'owed',
        'utang', 'utangan', 'pautang', 'loan', 'loans', 'arrears', 'atraso', 'due', 'dues', 'status',
        'account', 'accounts', 'record', 'records', 'details', 'detalye', 'profile', 'info',
        'payment', 'payments', 'paid', 'pay', 'bayad', 'bayarin', 'bayaran', 'nabayad', 'nabayaran',
        'binayad', 'gibayad', 'mibayad', 'nibayad', 'nakabayad', 'nagbayad', 'hulog', 'hulugan',
        'receipt', 'receipts', 'resibo', 'amortization', 'schedule', 'savings', 'share',
    ];

    /** Verbs a name comes before in English: "how much does Juan owe", "did Pedro pay". */
    private const OWE_VERBS = ['owe', 'owes', 'owed', 'pay', 'paid', 'pays', 'borrow', 'borrowed'];

    /** Words between a name and its verb: "does Juan still owe". */
    private const ADVERBS = ['still', 'already', 'yet', 'ever', 'just', 'recently', 'actually', 'really'];

    /** "balance of Juan", "payments by Pedro". */
    private const OF_WORDS = ['of', 'by', 'ng'];

    /** "Look up the member named …": the name runs to the end, generic words included. */
    private const NAMED_WORDS = ['named', 'called', 'ginganlan', 'nagngangalang', 'pangalan', 'ngalan'];

    /** Titles before a name, left out of it. */
    private const TITLES = [
        'mr', 'mrs', 'ms', 'miss', 'sir', 'maam', 'mam', 'madam', 'dr', 'atty', 'engr', 'mang', 'aling',
        'manong', 'manang', 'nanay', 'tatay', 'kuya', 'ate', 'lola', 'lolo', 'tito', 'tita', 'nang', 'nong',
        'g', 'gng', 'bb', 'ginoo', 'ginang', 'binibini',
    ];

    /**
     * Words after the lookup wording that may come before the name: "find the
     * member named", "details of the loan of", "hanapin si". A branch marker
     * ("in", "sa") is not one of them, so "search for loans in Main" is no
     * name lookup.
     */
    private const LEAD_INS = [
        'the', 'a', 'an', 'for', 'of', 'about', 'on', 'with', 'me', 'us', 'please', 'pls', 'kindly',
        'member', 'members', 'borrower', 'client', 'customer', 'account', 'accounts', 'loan', 'loans',
        'record', 'records', 'receipt', 'receipts', 'payment', 'payments', 'details', 'detail', 'profile',
        'info', 'information', 'balance', 'status', 'code', 'id', 'whose', 'name', 'named', 'called',
        'si', 'ni', 'kang', 'kay', 'nina', 'sina', 'kina', 'ang', 'ng', 'mga', 'na', 'nga', 'ug', 'og',
        'po', 'ba', 'palihug', 'paki', 'kanang', 'kadtong', 'ning', 'utang', 'balanse', 'bayad', 'detalye',
        'pangalan', 'ngalan', 'ginganlan', 'nagngangalang', 'ginoong', 'ginang',
    ];

    /**
     * Words that are never part of a name: fillers, periods, subjects, record
     * words and markers in English, Tagalog and Bisaya, and payment methods.
     */
    private const STOP_WORDS = [
        // English
        'a', 'about', 'above', 'after', 'again', 'all', 'already', 'also', 'am', 'an', 'and', 'any', 'anyone',
        'are', 'as', 'at', 'average', 'be', 'been', 'before', 'behind', 'being', 'below', 'between', 'both', 'but',
        'by', 'can', 'check', 'could', 'current', 'currently', 'did', 'do', 'does', 'doing', 'done', 'during',
        'each', 'either', 'else', 'ever', 'every', 'everybody', 'everyone', 'far', 'few', 'find', 'for', 'from',
        'get', 'give', 'got', 'had', 'has', 'have', 'having', 'he', 'hello', 'her', 'here', 'hers', 'hi',
        'him', 'his', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'just', 'kindly', 'know', 'last',
        'let', 'like', 'list', 'look', 'lookup', 'many', 'me', 'more', 'most', 'much', 'my', 'need', 'new',
        'next', 'no', 'nobody', 'not', 'now', 'of', 'off', 'old', 'on', 'once', 'only', 'or', 'other', 'our',
        'out', 'over', 'own', 'past', 'people', 'person', 'please', 'pls', 'previous', 'really', 'recently',
        'right', 'same', 'search', 'see', 'she', 'should', 'show', 'since', 'so', 'some', 'someone', 'still',
        'such', 'tell', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this',
        'those', 'through', 'to', 'too', 'under', 'until', 'up', 'us', 'very', 'was', 'we', 'were', 'what',
        'whats', 'when', 'where', 'which', 'while', 'who', 'whom', 'whose', 'why', 'will', 'with', 'would',
        'yet', 'you', 'your',
        // English record, money and report words
        'account', 'accounts', 'amortization', 'amount', 'amounts', 'application', 'applications', 'arrears',
        'balance', 'balances', 'borrow', 'borrowed', 'borrower', 'borrowers', 'branch', 'branches', 'client',
        'clients', 'code', 'collect', 'collected', 'collection', 'collections', 'customer', 'customers', 'debt',
        'debts', 'delayed', 'delinquent', 'deposit', 'detail', 'details', 'disbursed', 'disbursement',
        'disbursements', 'due', 'dues', 'efficiency', 'expense', 'expenses', 'fee', 'fees', 'id', 'income',
        'info', 'information', 'interest', 'journal', 'late', 'loan', 'loans', 'member', 'members', 'method',
        'name', 'named', 'number', 'officer', 'officers', 'outstanding', 'overdue', 'overview', 'owe', 'owed',
        'owes', 'paid', 'par', 'pay', 'paying', 'payment', 'payments', 'pays', 'penalties', 'penalty',
        'performance', 'portfolio', 'principal', 'profile', 'rate', 'receipt', 'receipts', 'record', 'records',
        'ref', 'reference', 'release', 'released', 'releases', 'report', 'reports', 'savings', 'schedule',
        'share', 'status', 'summary', 'total', 'totals', 'transaction', 'transactions', 'called', 'resibo',
        'transaksyon', 'kwarta', 'pera',
        // English periods
        'today', 'tonight', 'tomorrow', 'yesterday', 'day', 'days', 'week', 'weeks', 'month', 'months',
        'quarter', 'quarters', 'year', 'years', 'ytd', 'date',
        // Payment methods
        'bank', 'cash', 'cheque', 'gcash', 'maya', 'paymaya', 'online', 'transfer', 'remittance',
        // Tagalog
        'ako', 'akin', 'amin', 'ang', 'ano', 'anong', 'araw', 'at', 'atin', 'ba', 'bakit', 'bawat',
        'bayad', 'bayaran', 'bayarin', 'bigay', 'binayad', 'buwan', 'din', 'eh', 'gaano', 'hanapin',
        'hindi', 'hulog', 'hulugan', 'ilan', 'ilang', 'ito', 'iyan', 'kabuuan', 'kahapon', 'kailan', 'kami',
        'kanila', 'kanya', 'kaniya', 'kasi', 'kay', 'kina', 'ko', 'koleksyon', 'kumusta', 'kung', 'lahat',
        'lamang', 'linggo', 'magkano', 'may', 'mayroon', 'meron', 'mga', 'mo', 'mula', 'na', 'nabayaran',
        'naka', 'nakabayad', 'nakakabayad', 'nakaraang', 'naman', 'namin', 'nang', 'natin', 'nating', 'ng',
        'ngayon', 'ngayong', 'ni', 'nila', 'nina', 'niya', 'noong', 'nung', 'nya', 'o', 'oo', 'opo', 'pa',
        'paano', 'paki', 'pakibigay', 'pakita', 'po', 'rin', 'sa', 'saan', 'si', 'sina', 'sino', 'sinong',
        'sinu', 'siya', 'sya', 'taon', 'tayo', 'tungkol', 'ung', 'utang', 'wala', 'walang', 'yung', 'sige',
        'salamat', 'detalye', 'impormasyon', 'pautang', 'atraso', 'nakolekta', 'nailabas',
        // Bisaya
        'aduna', 'adunay', 'adlaw', 'ako', 'akong', 'amo', 'among', 'ani', 'asa', 'ato', 'atong', 'bai',
        'balanse', 'balansi', 'bay', 'bulan', 'dayon', 'dili', 'diay', 'dong', 'gahapon', 'gibayad', 'gikan',
        'gyud', 'hangtod', 'iya', 'iyang', 'ila', 'imo', 'imong', 'ipakita', 'jud', 'ka', 'kabuok', 'kadtong',
        'kagahapon', 'kaha', 'kamusta', 'kana', 'kanang', 'kang', 'karon', 'karong', 'kini', 'kining', 'kinsa',
        'kita', 'kuan', 'kumusta', 'lang', 'man', 'mibayad', 'miaging', 'naa', 'naay', 'nabayad', 'nagbayad',
        'nako', 'namo', 'nato', 'nga', 'niaging', 'nibayad', 'niadtong', 'nimo', 'ning', 'ninyo', 'niini',
        'og', 'palihug', 'pangita', 'pangitaa', 'pero', 'pila', 'pod', 'pud', 'ra', 'sab', 'sad', 'sadtong',
        'semana', 'semanaha', 'sila', 'ta', 'taliwala', 'tan', 'tanan', 'tanang', 'tuig', 'tuiga', 'ug',
        'unsa', 'unsang', 'unsay', 'unya', 'usab', 'utangan', 'bulana', 'adlawa',
    ];

    /** Month names and abbreviations: a name made only of these is no name ("payments of June"). */
    private const MONTH_WORDS = [
        'january', 'jan', 'enero', 'february', 'feb', 'pebrero', 'febrero', 'march', 'mar', 'marso', 'april',
        'apr', 'abril', 'may', 'mayo', 'june', 'jun', 'hunyo', 'july', 'jul', 'hulyo', 'august', 'aug',
        'agosto', 'september', 'sept', 'sep', 'setyembre', 'septiyembre', 'septyembre', 'october', 'oct',
        'oktubre', 'november', 'nov', 'nobyembre', 'december', 'dec', 'disyembre',
    ];

    /** The longest name taken, in words. */
    private const MAX_NAME_WORDS = 6;

    public static function parse(#[\SensitiveParameter] string $question): ?LindaLookupRequest
    {
        if (trim($question) === '') {
            return null;
        }

        $words = LindaText::words(LindaText::clean($question));

        $byCode = self::byCode($question, $words);
        if ($byCode !== null) {
            return $byCode;
        }

        if (preg_match(self::EMAIL_PATTERN, $question) === 1 || preg_match(self::MOBILE_PATTERN, $question) === 1) {
            return self::request(LindaLookupRequest::MEMBER);
        }

        if (preg_match(self::DIGIT_RUN_PATTERN, $question) === 1) {
            return self::request(self::kindByWords($words));
        }

        if (preg_match(self::NUMBERED_RECORD_PATTERN, $question, $numbered) === 1) {
            return self::request(self::kindByWords([mb_strtolower($numbered[1])]));
        }

        $tokens = self::tokens($question);

        return self::byLookupWording($question, $tokens, $words)
            ?? self::byWhoIs($question, $tokens)
            ?? self::byPersonMarker($question, $tokens)
            ?? self::byPossessive($question, $tokens)
            ?? self::byOf($question, $tokens)
            ?? self::byVerb($question, $tokens);
    }

    /**
     * A record number: the loan one first, then a receipt, then a member, then
     * any other code, whose lookup the question's words decide.
     *
     * @param  list<string>  $words
     */
    private static function byCode(string $question, array $words): ?LindaLookupRequest
    {
        $codes = [];

        if (preg_match_all(self::CODE_PATTERN, $question, $found) > 0) {
            foreach ($found[0] as $index => $code) {
                $codes[] = [strtoupper($found[1][$index]), strtoupper($code)];
            }
        }

        if (preg_match_all(self::LOOSE_CODE_PATTERN, $question, $loose) > 0) {
            foreach ($loose[1] as $index => $prefix) {
                $codes[] = [strtoupper($prefix), strtoupper($prefix).'-'.$loose[2][$index]];
            }
        }

        if ($codes === []) {
            return null;
        }

        foreach ([[self::LOAN_CODES, LindaLookupRequest::LOAN], [self::RECEIPT_CODES, LindaLookupRequest::RECEIPT], [self::MEMBER_CODES, LindaLookupRequest::MEMBER]] as [$prefixes, $kind]) {
            foreach ($codes as [$prefix, $code]) {
                if (in_array($prefix, $prefixes, true)) {
                    return self::request($kind, $code);
                }
            }
        }

        return self::request(self::kindByWords($words), $codes[0][1]);
    }

    /**
     * "find Juan Dela Cruz", "details of the loan of Rosa Santos", "hanapin
     * si Aling Nena": the name after the wording and its lead-in words.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     * @param  list<string>  $words
     */
    private static function byLookupWording(string $question, array $tokens, array $words): ?LindaLookupRequest
    {
        if (preg_match(self::LOOKUP_PATTERN, $question, $found, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $at = self::tokenAfter($tokens, $found[0][1] + strlen($found[0][0]));
        $named = false;

        while ($at < count($tokens) && in_array($tokens[$at]['lower'], self::LEAD_INS, true)) {
            $named = $named || in_array($tokens[$at]['lower'], self::NAMED_WORDS, true);
            $at++;
        }

        $name = self::nameFrom($question, $tokens, $at, $named);

        return $name !== null
            ? self::request(LindaLookupRequest::MEMBER, null, $name)
            : self::request(self::kindByWords($words));
    }

    /**
     * "Who is Juan Dela Cruz?", with a capitalised name only, so "who is
     * behind on payments?" stays a question for the model.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byWhoIs(string $question, array $tokens): ?LindaLookupRequest
    {
        if (preg_match(self::WHO_IS_PATTERN, $question, $found, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $name = self::nameFrom($question, $tokens, self::tokenAfter($tokens, $found[0][1] + strlen($found[0][0])));

        return $name === null ? null : self::request(LindaLookupRequest::MEMBER, null, $name);
    }

    /**
     * A person marker and a balance, loan or payment word anywhere: "utang
     * ni Juan", "Nakabayad na ba si Rosa?". A lookup even with no name.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byPersonMarker(string $question, array $tokens): ?LindaLookupRequest
    {
        $lowers = array_column($tokens, 'lower');

        if (array_intersect($lowers, self::MONEY_WORDS) === []) {
            return null;
        }

        foreach ($tokens as $at => $token) {
            if (! in_array($token['lower'], self::PERSON_MARKERS, true)) {
                continue;
            }

            $next = $tokens[$at + 1]['lower'] ?? null;

            // Bisaya "kay" before a common word is "because": "atraso kay wala pa nakabayad".
            if ($token['lower'] === 'kay' && ($next === null || self::isStopWord($next))) {
                continue;
            }

            return self::request(LindaLookupRequest::MEMBER, null, self::nameFrom($question, $tokens, $at + 1));
        }

        return null;
    }

    /**
     * "Juan Dela Cruz's balance", "Maria’s loans": a name-like word with
     * "'s" and a balance, loan or payment word.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byPossessive(string $question, array $tokens): ?LindaLookupRequest
    {
        if (array_intersect(array_column($tokens, 'lower'), self::MONEY_WORDS) === []) {
            return null;
        }

        foreach ($tokens as $at => $token) {
            if (preg_match("/^(.+)['’]s$/u", $token['lower'], $base) !== 1 || ! self::isNameWord($base[1])) {
                continue;
            }

            $end = $token['start'] + strlen((string) preg_replace("/['’]s$/u", '', $token['text']));
            $start = $at;
            while ($start > 0 && $at - $start < self::MAX_NAME_WORDS - 1 && self::isNameWord($tokens[$start - 1]['lower'])) {
                $start--;
            }

            $name = self::cleanName(substr($question, $tokens[$start]['start'], $end - $tokens[$start]['start']));
            if ($name !== null) {
                return self::request(LindaLookupRequest::MEMBER, null, $name);
            }
        }

        return null;
    }

    /**
     * "balance of Pedro Reyes", "payments by Ana": a balance, loan or payment
     * word, then of, by or ng, then a name. One that names a branch ("payments
     * of Main branch") is not a lookup; one with nothing after "of" ("what's
     * the balance of?") is, with no name.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byOf(string $question, array $tokens): ?LindaLookupRequest
    {
        foreach ($tokens as $at => $token) {
            if (! in_array($token['lower'], self::MONEY_WORDS, true) || ! in_array($tokens[$at + 1]['lower'] ?? null, self::OF_WORDS, true)) {
                continue;
            }

            if (! isset($tokens[$at + 2])) {
                return self::request(LindaLookupRequest::MEMBER);
            }

            $name = self::nameFrom($question, $tokens, $at + 2);
            $after = $tokens[$at + 2 + ($name === null ? 0 : count(self::tokens($name)))]['lower'] ?? null;

            if ($name !== null && ! in_array($after, ['branch', 'branches', 'sanga', 'sangay'], true)) {
                return self::request(LindaLookupRequest::MEMBER, null, $name);
            }
        }

        return null;
    }

    /**
     * "How much does Juan Dela Cruz still owe?", "Did Pedro pay?": the name
     * just before an owe or pay verb.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byVerb(string $question, array $tokens): ?LindaLookupRequest
    {
        foreach ($tokens as $at => $token) {
            if (! in_array($token['lower'], self::OWE_VERBS, true)) {
                continue;
            }

            $end = $at;
            while ($end > 0 && in_array($tokens[$end - 1]['lower'], self::ADVERBS, true)) {
                $end--;
            }

            $start = $end;
            while ($start > 0 && $end - $start < self::MAX_NAME_WORDS && self::isNameWord($tokens[$start - 1]['lower'])) {
                $start--;
            }

            if ($start < $end) {
                $name = self::cleanName(substr($question, $tokens[$start]['start'], $tokens[$end - 1]['end'] - $tokens[$start]['start']));

                if ($name !== null) {
                    return self::request(LindaLookupRequest::MEMBER, null, $name);
                }
            }
        }

        return null;
    }

    /**
     * The name starting at a token: titles skipped, then name-like words up to
     * the first stop word, as typed. After "named" the name runs on through
     * generic words ("Test Member One") to a period, a marker or the end.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function nameFrom(string $question, array $tokens, int $at, bool $named = false): ?string
    {
        while ($at < count($tokens) && in_array(rtrim($tokens[$at]['lower'], '.'), self::TITLES, true)) {
            $at++;
        }

        $end = $at;
        while ($end < count($tokens) && $end - $at < self::MAX_NAME_WORDS
            && ($named ? self::isNamedWord($tokens[$end]['lower']) : self::isNameWord($tokens[$end]['lower']))) {
            $end++;
        }

        if ($end === $at) {
            return null;
        }

        return self::cleanName(substr($question, $tokens[$at]['start'], $tokens[$end - 1]['end'] - $tokens[$at]['start']));
    }

    /** A name as typed, trimmed; null when it is only month names. */
    private static function cleanName(string $name): ?string
    {
        $name = trim($name, " \t\n\r\0\x0B.,");
        $words = array_map(fn (array $token) => $token['lower'], self::tokens($name));

        if ($words === [] || array_diff($words, self::MONTH_WORDS) === []) {
            return null;
        }

        return $name;
    }

    /** A word that can be part of a member's name. */
    private static function isNameWord(string $lower): bool
    {
        if (preg_match('/\d/', $lower) === 1 || preg_match("/['’]/u", $lower) === 1) {
            return false;
        }

        foreach (preg_split('/-/', $lower) ?: [] as $part) {
            if (self::isStopWord($part)) {
                return false;
            }
        }

        return true;
    }

    /** A word that can be part of a name given after "named": generic words too, never a period, marker or number. */
    private static function isNamedWord(string $lower): bool
    {
        $hardStops = ['in', 'at', 'sa', 'from', 'for', 'with', 'and', 'ug', 'og', 'as', 'on', 'to', 'ni', 'si', 'kang', 'kay',
            'today', 'yesterday', 'this', 'last', 'karon', 'gahapon', 'kahapon', 'ngayon', 'ngayong', 'please', 'pls', 'po', 'palihug'];

        return preg_match('/\d/', $lower) !== 1 && ! in_array($lower, $hardStops, true);
    }

    private static function isStopWord(string $lower): bool
    {
        return in_array(rtrim($lower, '.'), self::STOP_WORDS, true);
    }

    /**
     * The words of the raw question with their byte offsets, so a name keeps
     * its case and spacing. A word may hold inner dots, dashes and apostrophes
     * ("Ma.", "Jean-Paul", "Cruz's").
     *
     * @return list<array{text: string, lower: string, start: int, end: int}>
     */
    private static function tokens(string $text): array
    {
        preg_match_all("/[\\pL\\pM\\pN]+(?:[.'’\\-][\\pL\\pM\\pN]+)*\\.?/u", $text, $found, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match) => [
            'text' => $match[0],
            'lower' => mb_strtolower($match[0], 'UTF-8'),
            'start' => $match[1],
            'end' => $match[1] + strlen($match[0]),
        ], $found[0]);
    }

    /**
     * The index of the first token that starts at or after a byte offset.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function tokenAfter(array $tokens, int $offset): int
    {
        foreach ($tokens as $at => $token) {
            if ($token['start'] >= $offset) {
                return $at;
            }
        }

        return count($tokens);
    }

    /**
     * A lookup with no name: about a receipt, a loan, else a member.
     *
     * @param  list<string>  $words
     */
    private static function kindByWords(array $words): string
    {
        return match (true) {
            array_intersect($words, self::RECEIPT_WORDS) !== [] => LindaLookupRequest::RECEIPT,
            array_intersect($words, self::LOAN_WORDS) !== [] => LindaLookupRequest::LOAN,
            default => LindaLookupRequest::MEMBER,
        };
    }

    /** @param  'member'|'loan'|'receipt'  $kind */
    private static function request(string $kind, ?string $code = null, ?string $name = null): LindaLookupRequest
    {
        $intent = match ($kind) {
            LindaLookupRequest::LOAN => LindaIntent::LoanDetails,
            LindaLookupRequest::RECEIPT => LindaIntent::TransactionLookup,
            default => LindaIntent::BorrowerLookup,
        };

        return new LindaLookupRequest($kind, $intent, $code, $name);
    }
}
