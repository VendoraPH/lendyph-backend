<?php

namespace App\Services\Linda;

use Normalizer;
use UnexpectedValueException;

/**
 * The local lookup parser: whether a question asks about one record or one
 * person, and which. A lookup is answered on the server and never sent to
 * the model, so no member name, record number, email or phone number ever
 * reaches it. Pure: no database access.
 *
 * The question is first NFKC-normalised, with invisible format characters
 * dropped, every Unicode dash read as "-" and every space as a plain space,
 * so fullwidth digits and letters, figure and minus dashes, non-breaking
 * spaces and zero-width characters read like their plain forms.
 *
 * It reads English, Tagalog/Taglish and Bisaya/Cebuano, in this order:
 * 1. A record number: member and co-maker codes (BRW-, CMK-), loan numbers
 *    (LA-, LN-, PN-, SOA-), receipts and the other transaction codes (RCP-,
 *    SC-, SCV-, JE-, ADJ-, GC-, SAV-, SVT-, SVI-, TD-, REQ-, MC-) with any
 *    number of digits after a dash, a hash or a space ("LN-42", "ln#42",
 *    "RCP 7"), the six common ones with 3 or more digits and nothing between
 *    ("LN000042"), and any other letters-dash-digits code with 3 or more
 *    digits (a membership number).
 * 2. An email, a Philippine mobile number, or any phone number whose digits
 *    are split by spaces, dots, dashes or brackets ("(0917) 123 4567",
 *    "(032) 234-5678"): a member lookup, but never passed on as a name.
 *    Dates ("2026-10-01", "1-15 2026") are not phone numbers.
 * 3. A run of 7 or more digits, or a record word with a number ("loan 42",
 *    "member 45"): a lookup with no target, so the server asks for the
 *    full number.
 * 4. Lookup wording: find, search, look up, hanapin, pangitaa, "sino si",
 *    "kinsa si", "details of", "detalye sa", "profile of"; "find out" only
 *    with a name after it; and "who is" (any case) before a name.
 * 5. A person marker (ni, si, kang, kay, nina, sina, kina) before a
 *    name-like word, with or without a money word ("Kumusta si Juan?"), or
 *    with a balance, loan, payment or collection word and nothing after it
 *    ("Pila ang utang ni?"). A marker before a common word ("kay wala",
 *    "ni isa") is no marker.
 * 6. English: "<name>'s balance", "balance of <name>", "collections from
 *    <name>", "how much does <name> owe", "did <name> pay".
 * 7. A follow-up phrase and only name-like words ("how about Juan Dela
 *    Cruz?").
 * 8. Name-like words right before or after a money word ("Juan Dela Cruz
 *    balance", "Rosa Santos utang").
 *
 * A name is a member's name, so a name lookup is a member lookup, except
 * that a name with a payment-made word ("Did Pedro pay?", "Nakabayad na ba
 * si Rosa?", "payments of Ana") asks for that member's latest payments: a
 * receipt lookup. The name is the words as typed, up to the first stop word,
 * period, subject or record word, case and spaces kept. A short capitalised
 * stop word straight after a name word stays in it ("Henry Ang", "Pedro
 * Tan"), an inner apostrophe is kept ("O'Neil") and a trailing "'s" is
 * dropped. A leading title ("Aling", "Mang", "Mr.") is left out, a name made
 * only of month names ("June") is no name, and category words ("by
 * product", "of the month") are never names.
 *
 * It fails closed: invalid UTF-8, or any regular-expression failure while
 * parsing, reads as a lookup with no target, so the question stays local.
 */
final class LindaLookup
{
    /** Record-number prefixes, by the kind of lookup they belong to. */
    private const MEMBER_CODES = ['BRW', 'CMK'];

    private const LOAN_CODES = ['LA', 'LN', 'PN', 'SOA'];

    private const RECEIPT_CODES = ['RCP', 'SC', 'SCV', 'JE', 'ADJ', 'GC', 'SAV', 'SVT', 'SVI', 'TD', 'REQ', 'MC'];

    /** Every known prefix, longest first for the regular expressions. */
    private const KNOWN_PREFIXES = 'SOA|SCV|SVT|SVI|SAV|REQ|ADJ|BRW|CMK|RCP|LA|LN|PN|SC|JE|GC|TD|MC';

    /** A known prefix (optionally two, "SC-RCP"), then a dash, a hash or a space, then any number of digits. */
    private const KNOWN_CODE_PATTERN = '/(?<![\pL\pN])('.self::KNOWN_PREFIXES.')(?:-('.self::KNOWN_PREFIXES.'))?(?:[ \t]*+[-#][ \t]*+|[ \t]++)(\d++(?:-\d++)*+)(?![\pL\pN])/iu';

    /** Any other code: letters, a dash and 3 or more digits. */
    private const CODE_PATTERN = '/(?<![\pL\pN])([A-Za-z]{1,5})(?:-[A-Za-z]{2,5})?-\d{3,}+(?:-\d++)*+(?![\pL\pN])/u';

    /** The common codes written with nothing between the prefix and 3 or more digits. */
    private const LOOSE_CODE_PATTERN = '/(?<![\pL\pN])(BRW|CMK|LN|LA|PN|RCP)(\d{3,}+)(?![\pL\pN])/iu';

    private const EMAIL_PATTERN = '/[\pL\pN._%+\-]++@[\pL\pN.\-]+\.\pL{2,}/u';

    private const MOBILE_PATTERN = '/(?<!\d)(?:\+?63|0)[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)/u';

    /** A run of digits split by spaces, dots, dashes or brackets, read as one number. */
    private const DIGIT_GROUPS_PATTERN = '/[(\[]?+\d[\d \t.()\[\]\-]*+/u';

    private const DIGIT_RUN_PATTERN = '/\d{7,}/u';

    /**
     * A record word with a number: "loan 42", "member no. 45", "receipt #123".
     * Not a year, an amount, a percentage or a length ("loan 12 months").
     */
    private const NUMBERED_RECORD_PATTERN = '/(?<![\pL\pN])(loan|receipt|resibo|reference|ref|transaction|account|member|borrower|application)\s*+(?:no\.?|number|num|#)?\s*+#?\s*+(?!(?:19|20)\d{2}(?!\d))\d{2,}+(?![\d,.%])(?!\s*(?:months?|days?|weeks?|years?|yrs?|buwan|bulan|adlaw|araw|semana|linggo|tuig|taon|ka|pesos?|php|k)(?![\pL]))/iu';

    private const LOOKUP_PATTERN = '/(?<![\pL\pN])(find|search|look\s++up|look\s++for|lookup|hanapin|hanapan|pangitaa|pangitaon|pangitai|pangita|sino\s++si|kinsa\s++si|details?\s++of|detalye\s++(?:sa|ni|ng|kang)|profile\s++(?:of|ni)|info(?:rmation)?\s++(?:on|about|of)|impormasyon\s++(?:sa|ni|ng|kang))(?![\pL\pN])/iu';

    /** "Who is", in any case: "who is Juan Dela Cruz?". */
    private const WHO_IS_PATTERN = '/(?<![\pL\pN])who\s++is(?![\pL\pN])/iu';

    /** Words that make a lookup with no name about a receipt or a loan; any other is about a member. */
    private const RECEIPT_WORDS = ['receipt', 'receipts', 'resibo', 'payment', 'payments', 'reference', 'ref', 'transaction', 'transactions', 'deposit', 'journal'];

    private const LOAN_WORDS = ['loan', 'loans', 'pautang', 'application'];

    /** Markers before a person's name. */
    private const PERSON_MARKERS = ['ni', 'si', 'kang', 'kay', 'nina', 'sina', 'kina'];

    /** Collection words: they count as money words, and take "from" before a name. */
    private const COLLECTION_WORDS = [
        'collect', 'collected', 'collection', 'collections', 'nakolekta', 'kolekta', 'nakubra', 'kubra',
        'koleksyon', 'koleksiyon', 'singil', 'nasingil',
    ];

    /** Payment words a name follows with "of", "by" or "from", or "'s": a receipt lookup. */
    private const PAYMENT_NOUNS = ['payment', 'payments', 'receipt', 'receipts'];

    /** Words that say a payment was made: with a name, a receipt lookup ("Nakabayad na ba si Rosa?"). */
    private const PAYMENT_MADE_WORDS = ['nagbayad', 'nakabayad', 'mibayad', 'nibayad', 'payments'];

    /** Balance, loan and payment words: one beside a name, or with a marker and no name, makes a lookup. */
    private const MONEY_WORDS = [
        'balance', 'balances', 'balanse', 'balansi', 'outstanding', 'debt', 'debts', 'owe', 'owes', 'owed',
        'utang', 'utangan', 'pautang', 'loan', 'loans', 'arrears', 'atraso', 'due', 'dues', 'status',
        'account', 'accounts', 'record', 'records', 'details', 'detalye', 'profile', 'info',
        'payment', 'payments', 'paid', 'pay', 'bayad', 'bayarin', 'bayaran', 'nabayad', 'nabayaran',
        'binayad', 'gibayad', 'mibayad', 'nibayad', 'nakabayad', 'nagbayad', 'hulog', 'hulugan',
        'receipt', 'receipts', 'resibo', 'amortization', 'schedule', 'savings', 'share',
        ...self::COLLECTION_WORDS,
    ];

    /** Verbs a name comes before in English: "how much does Juan owe", "did Pedro pay". */
    private const OWE_VERBS = ['owe', 'owes', 'owed', 'pay', 'paid', 'pays', 'borrow', 'borrowed'];

    /** The verbs among them that say a payment was made. */
    private const PAY_VERBS = ['pay', 'paid', 'pays'];

    /** Words between a name and its verb: "does Juan still owe". */
    private const ADVERBS = ['still', 'already', 'yet', 'ever', 'just', 'recently', 'actually', 'really'];

    /** "balance of Juan", "payments by Pedro". */
    private const OF_WORDS = ['of', 'by', 'ng'];

    /** Branch words: a "name" just before one names a branch ("payments of Main branch"). */
    private const BRANCH_WORDS = ['branch', 'branches', 'sanga', 'sangay'];

    /** "Look up the member named …": the name runs to the end, generic words included. */
    private const NAMED_WORDS = ['named', 'called', 'ginganlan', 'nagngangalang', 'pangalan', 'ngalan'];

    /** Follow-up phrases a name may follow: "how about Juan Dela Cruz?". */
    private const FOLLOW_UP_PHRASES = [
        'and how about', 'and what about', 'how about', 'what about', 'paano naman', 'pano naman', 'kumusta kaha',
        'kamusta kaha', 'kumusta', 'kamusta', 'unsa kaha', 'unsa man', 'eh di', 'eh', 'unya', 'pero',
    ];

    /** Words a reply may end with after a name: "Juan naman?". */
    private const TRAILING_PARTICLES = ['naman', 'po', 'ba', 'pud', 'pod', 'sad', 'sab', 'kaha', 'diay', 'man', 'lang', 'please', 'pls', 'na'];

    /** What a reply to "Which member?" may start with before the name. */
    private const REPLY_LEADS = ['how about', 'what about', 'kumusta si', 'kamusta si', 'si', 'ni', 'kay', 'kang'];

    /** The prefix a bare number takes in a reply to "Which loan?" and the others. */
    private const REPLY_PREFIXES = [LindaLookupRequest::MEMBER => 'BRW', LindaLookupRequest::LOAN => 'LN', LindaLookupRequest::RECEIPT => 'RCP'];

    /** Titles before a name, left out of it. */
    private const TITLES = [
        'mr', 'mrs', 'ms', 'miss', 'sir', 'maam', 'mam', 'madam', 'dr', 'atty', 'engr', 'mang', 'aling',
        'manong', 'manang', 'nanay', 'tatay', 'kuya', 'ate', 'lola', 'lolo', 'tito', 'tita', 'nang', 'nong',
        'g', 'gng', 'bb', 'ginoo', 'ginang', 'binibini',
    ];

    /**
     * Short stop words that are also surnames ("Henry Ang", "Pedro Tan"): one
     * capitalised straight after a name word stays in the name, unless it is
     * one of these function words.
     */
    private const NEVER_IN_A_NAME = [
        'and', 'the', 'for', 'of', 'to', 'in', 'at', 'on', 'or', 'is', 'are', 'was', 'by', 'as', 'if', 'so', 'do',
        'did', 'has', 'had', 'how', 'who', 'why', 'can', 'now', 'any', 'all', 'our', 'my', 'me', 'we', 'us', 'it',
        'its', 'sa', 'ng', 'ug', 'og', 'ni', 'si', 'kay', 'po', 'ba', 'pa', 'na',
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
     * and category words, markers and common verbs in English, Tagalog and
     * Bisaya, and payment methods.
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
        'yet', 'you', 'your', 'there', 'thanks', 'thank', 'ok', 'okay', 'never', 'mind', 'nevermind', 'cancel',
        'yes', 'wait', 'later', 'and', 'top', 'best', 'worst', 'highest', 'lowest', 'least', 'responsible',
        'charge', 'around', 'today', 'whether',
        // English contractions, apostrophes dropped
        'dont', 'doesnt', 'didnt', 'isnt', 'arent', 'wasnt', 'werent', 'hasnt', 'havent', 'hadnt', 'cant',
        'couldnt', 'wont', 'wouldnt', 'shouldnt', 'im', 'ive', 'youre', 'theyre', 'weve', 'well', 'thats',
        'theres', 'whos', 'hows', 'lets',
        // English record, money, report and category words
        'account', 'accounts', 'amortization', 'amount', 'amounts', 'application', 'applications', 'arrears',
        'balance', 'balances', 'borrow', 'borrowed', 'borrower', 'borrowers', 'branch', 'branches', 'client',
        'clients', 'code', 'collect', 'collected', 'collection', 'collections', 'customer', 'customers', 'debt',
        'debts', 'delayed', 'delinquent', 'deposit', 'detail', 'details', 'disbursed', 'disbursement',
        'disbursements', 'due', 'dues', 'efficiency', 'expense', 'expenses', 'fee', 'fees', 'id', 'income',
        'info', 'information', 'interest', 'journal', 'late', 'loan', 'loans', 'member', 'members', 'method',
        'methods', 'name', 'named', 'number', 'officer', 'officers', 'outstanding', 'overdue', 'overview', 'owe',
        'owed', 'owes', 'paid', 'par', 'pay', 'paying', 'payment', 'payments', 'pays', 'penalties', 'penalty',
        'performance', 'portfolio', 'principal', 'profile', 'rate', 'receipt', 'receipts', 'record', 'records',
        'ref', 'reference', 'release', 'released', 'releases', 'report', 'reports', 'savings', 'schedule',
        'share', 'status', 'statuses', 'summary', 'total', 'totals', 'transaction', 'transactions', 'called',
        'resibo', 'transaksyon', 'kwarta', 'pera', 'product', 'products', 'type', 'types', 'purpose',
        'purposes', 'area', 'areas', 'aging', 'bucket', 'buckets', 'term', 'terms', 'collector', 'collectors',
        'capital', 'insurance', 'unpaid', 'partial', 'full', 'advance', 'monthly', 'weekly', 'daily', 'annual',
        'yearly', 'gross', 'net', 'update', 'updates', 'history', 'count', 'breakdown', 'trend', 'trends',
        'growth', 'received', 'receive', 'made', 'make', 'went', 'came', 'recorded', 'posted', 'missed', 'left',
        'remaining', 'pending', 'upcoming', 'recent', 'latest', 'first', 'second', 'third', 'next', 'cycle',
        'money', 'cash', 'officers', 'staff', 'ao', 'aos', 'performing', 'ranking', 'rank', 'compare',
        'active', 'inactive', 'open', 'closed', 'ongoing', 'running', 'completed', 'defaulted', 'restructured',
        'approved', 'rejected', 'voided', 'void', 'draft', 'big', 'small', 'large', 'bad', 'good', 'high', 'low',
        'one', 'ones', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        // Ranking words (LindaMatcher's): "pinakamaraming atraso" names no one
        'ranked', 'rankings', 'comparison', 'versus', 'vs', 'perform', 'ikumpara', 'ikompara', 'kumpara', 'itandi',
        'pinaka', 'pinakadaghan', 'pinakagamay', 'pinakadako', 'pinakamaayo', 'pinakataas', 'pinakaubos',
        'pinakamarami', 'pinakamaraming', 'pinakamaliit', 'pinakamalaki', 'pinakamalaking', 'pinakamagaling',
        'pinakamataas', 'pinakamababa', 'dako', 'dakong', 'maayo', 'magaling', 'marami', 'maraming', 'malaki',
        'malaking', 'nanguna', 'nangunguna', 'nangungunang', 'una',
        // Loan kinds and report words (re-review)
        'kabuuang', 'agent', 'agents', 'team', 'target', 'delinquency', 'proceeds', 'size', 'volume', 'opening',
        'closing', 'ending', 'regular', 'emergency', 'salary', 'business', 'fully', 'great', 'nice', 'wow',
        // English periods
        'tonight', 'tomorrow', 'yesterday', 'day', 'days', 'week', 'weeks', 'month', 'months', 'quarter',
        'quarters', 'year', 'years', 'ytd', 'date', 'dates',
        // Payment methods
        'bank', 'cheque', 'check', 'gcash', 'maya', 'paymaya', 'online', 'transfer', 'remittance',
        // Tagalog
        'ako', 'akin', 'amin', 'ang', 'ano', 'anong', 'araw', 'at', 'atin', 'ba', 'bakit', 'bawat',
        'bayad', 'bayaran', 'bayarin', 'bigay', 'binayad', 'buwan', 'din', 'eh', 'gaano', 'hanapin',
        'hindi', 'hulog', 'hulugan', 'ilan', 'ilang', 'ito', 'iyan', 'kabuuan', 'kahapon', 'kailan', 'kami',
        'kanila', 'kanya', 'kaniya', 'kasi', 'kay', 'kina', 'ko', 'koleksyon', 'koleksiyon', 'kumusta', 'kamusta',
        'kung', 'lahat', 'lamang', 'linggo', 'magkano', 'may', 'mayroon', 'meron', 'mga', 'mo', 'mula', 'na',
        'nabayaran', 'naka', 'nakabayad', 'nakakabayad', 'nakaraang', 'naman', 'namin', 'nang', 'natin',
        'nating', 'ng', 'ngayon', 'ngayong', 'ni', 'nila', 'nina', 'niya', 'noong', 'nung', 'nya', 'o', 'oo',
        'opo', 'pa', 'paano', 'paki', 'pakibigay', 'pakita', 'po', 'rin', 'sa', 'saan', 'si', 'sina', 'sino',
        'sinong', 'sinu', 'siya', 'sya', 'taon', 'tayo', 'tungkol', 'ung', 'utang', 'wala', 'walang', 'yung',
        'sige', 'salamat', 'detalye', 'impormasyon', 'pautang', 'atraso', 'nakolekta', 'kolekta', 'nailabas',
        'nagbayad', 'isa', 'kanina', 'mamaya', 'bukas', 'dito', 'doon', 'talaga', 'muna', 'pala', 'kaya',
        'sana', 'singil', 'nasingil', 'pumasok', 'kita', 'gastos',
        // Bisaya
        'aduna', 'adunay', 'adlaw', 'ako', 'akong', 'amo', 'among', 'ani', 'asa', 'ato', 'atong', 'bai',
        'balanse', 'balansi', 'bay', 'bulan', 'dayon', 'dili', 'diay', 'dong', 'gahapon', 'gibayad', 'gikan',
        'gyud', 'hangtod', 'iya', 'iyang', 'ila', 'imo', 'imong', 'ipakita', 'jud', 'ka', 'kabuok', 'kadtong',
        'kagahapon', 'kaha', 'kamusta', 'kana', 'kanang', 'kang', 'karon', 'karong', 'kini', 'kining', 'kinsa',
        'kita', 'kuan', 'kumusta', 'lang', 'man', 'mibayad', 'miaging', 'naa', 'naay', 'nabayad', 'nagbayad',
        'nako', 'namo', 'nato', 'nga', 'niaging', 'nibayad', 'niadtong', 'nimo', 'ning', 'ninyo', 'niini',
        'og', 'palihug', 'pangita', 'pangitaa', 'pero', 'pila', 'pod', 'pud', 'ra', 'sab', 'sad', 'sadtong',
        'semana', 'semanaha', 'sila', 'ta', 'taliwala', 'tan', 'tanan', 'tanang', 'tuig', 'tuiga', 'ug',
        'unsa', 'unsang', 'unsay', 'unya', 'usab', 'utangan', 'bulana', 'adlawa', 'daghan', 'daghang',
        'gamay', 'ngano', 'nganong', 'tungod', 'ubos', 'taas', 'ganina', 'ugma', 'sunod', 'dapit', 'diri',
        'dinhi', 'didto', 'ingon', 'mao', 'gani', 'nakubra', 'kubra', 'nadawat',
    ];

    /** Month names and abbreviations: a name made only of these is no name ("payments of June"). */
    private const MONTH_WORDS = [
        'january', 'jan', 'enero', 'february', 'feb', 'pebrero', 'febrero', 'march', 'mar', 'marso', 'april',
        'apr', 'abril', 'may', 'mayo', 'june', 'jun', 'hunyo', 'july', 'jul', 'hulyo', 'august', 'aug',
        'agosto', 'september', 'sept', 'sep', 'setyembre', 'septiyembre', 'septyembre', 'october', 'oct',
        'oktubre', 'november', 'nov', 'nobyembre', 'december', 'dec', 'disyembre',
    ];

    /** Bisaya and Tagalog verb prefixes, for "kay" (because) before a verb. */
    private const VERB_PREFIXES = ['gipa', 'gipang', 'gi', 'mi', 'ni', 'nag', 'mag', 'naka', 'maka', 'nanga', 'na', 'ma', 'mo', 'ga', 'ka', 'pag', 'ipa'];

    /** The longest name taken, in words. */
    private const MAX_NAME_WORDS = 6;

    /** The fewest digits a phone number has. */
    private const PHONE_DIGITS_MIN = 7;

    public static function parse(#[\SensitiveParameter] string $question): ?LindaLookupRequest
    {
        if (trim($question) === '') {
            return null;
        }

        if (! mb_check_encoding($question, 'UTF-8')) {
            return self::request(LindaLookupRequest::MEMBER);
        }

        try {
            $request = self::read(self::normalise($question));
        } catch (UnexpectedValueException) {
            return self::request(LindaLookupRequest::MEMBER);
        }

        return preg_last_error() === PREG_NO_ERROR ? $request : self::request(LindaLookupRequest::MEMBER);
    }

    /**
     * A short reply right after a lookup turn ("Which member?"): a record
     * number (a bare number takes the lookup's own prefix), or 1–6 name-like
     * words, optionally after "how about", "kumusta si", "si", "ni", "kay"
     * or "kang". A name is a member lookup; anything else is no reply to it.
     *
     * @param  'member'|'loan'|'receipt'  $kind  the kind of the lookup turn it answers
     */
    public static function reply(#[\SensitiveParameter] string $question, string $kind): ?LindaLookupRequest
    {
        if (trim($question) === '' || ! mb_check_encoding($question, 'UTF-8')) {
            return null;
        }

        try {
            $question = self::normalise($question);

            if (self::match('/^\s*+#?\s*+(\d{1,12}+)\s*+[?.!]?\s*+$/u', $question, $number)) {
                return self::request($kind, (self::REPLY_PREFIXES[$kind] ?? 'BRW').'-'.$number[1]);
            }

            $byCode = self::byCode($question, LindaText::words(self::cleaned($question)));
            if ($byCode !== null) {
                return $byCode;
            }

            $tokens = self::tokens($question);
            $at = self::afterPhrase($tokens, self::REPLY_LEADS);
            $name = self::onlyName($question, $tokens, $at);
        } catch (UnexpectedValueException) {
            return null;
        }

        return $name === null || preg_last_error() !== PREG_NO_ERROR ? null : self::request(LindaLookupRequest::MEMBER, null, $name);
    }

    /**
     * Whether a word could be part of a member's name: not a stop word,
     * period, subject, record or category word, and no digit. For the
     * full-name check (LindaNameCheck).
     */
    public static function isNameLike(#[\SensitiveParameter] string $word): bool
    {
        try {
            return mb_check_encoding($word, 'UTF-8') && self::isNameWord(mb_strtolower($word));
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /**
     * NFKC, with invisible format characters (zero-width spaces, soft
     * hyphens) dropped, every Unicode dash read as "-" and every space as " ".
     */
    private static function normalise(#[\SensitiveParameter] string $question): string
    {
        $normal = Normalizer::normalize($question, Normalizer::FORM_KC);

        if (! is_string($normal)) {
            throw new UnexpectedValueException('normalise');
        }

        $normal = self::replace('/\p{Cf}/u', '', $normal);
        $normal = self::replace('/[\p{Pd}\x{2212}]/u', '-', $normal);

        return self::replace('/[\p{Zs}\x{00A0}\x{2007}\x{202F}]/u', ' ', $normal);
    }

    private static function read(#[\SensitiveParameter] string $question): ?LindaLookupRequest
    {
        $words = LindaText::words(self::cleaned($question));

        $byCode = self::byCode($question, $words);
        if ($byCode !== null) {
            return $byCode;
        }

        if (self::match(self::EMAIL_PATTERN, $question) || self::match(self::MOBILE_PATTERN, $question) || self::hasPhoneNumber($question)) {
            return self::request(LindaLookupRequest::MEMBER);
        }

        if (self::match(self::DIGIT_RUN_PATTERN, $question)) {
            return self::request(self::kindByWords($words));
        }

        if (self::match(self::NUMBERED_RECORD_PATTERN, $question, $numbered)) {
            return self::request(self::kindByWords([mb_strtolower($numbered[1])]));
        }

        $tokens = self::tokens($question);

        return self::byLookupWording($question, $tokens, $words)
            ?? self::byWhoIs($question, $tokens)
            ?? self::byPersonMarker($question, $tokens)
            ?? self::byPossessive($question, $tokens)
            ?? self::byOf($question, $tokens)
            ?? self::byVerb($question, $tokens)
            ?? self::byFollowUpPhrase($question, $tokens)
            ?? self::byMoneyWord($question, $tokens);
    }

    /** LindaText::clean(), failing closed. */
    private static function cleaned(#[\SensitiveParameter] string $question): string
    {
        $clean = LindaText::clean($question);

        if (preg_last_error() !== PREG_NO_ERROR) {
            throw new UnexpectedValueException('clean');
        }

        return $clean;
    }

    /**
     * A record number: the loan one first, then a receipt, then a member, then
     * any other code, whose lookup the question's words decide.
     *
     * @param  list<string>  $words
     */
    private static function byCode(#[\SensitiveParameter] string $question, array $words): ?LindaLookupRequest
    {
        $codes = [];

        if (self::matchAll(self::KNOWN_CODE_PATTERN, $question, $known) > 0) {
            foreach ($known[1] as $index => $prefix) {
                $prefix = strtoupper($prefix);
                $second = strtoupper($known[2][$index]);
                $codes[] = [$prefix, $prefix.($second === '' ? '' : '-'.$second).'-'.$known[3][$index]];
            }
        }

        if (self::matchAll(self::CODE_PATTERN, $question, $found) > 0) {
            foreach ($found[0] as $index => $code) {
                // A month and a year ("Nov-2026") is a period, never a code.
                if (in_array(mb_strtolower($found[1][$index]), self::MONTH_WORDS, true) && self::match('/^[A-Za-z]++-(?:19|20)\d{2}$/u', $code)) {
                    continue;
                }

                $codes[] = [strtoupper($found[1][$index]), strtoupper($code)];
            }
        }

        if (self::matchAll(self::LOOSE_CODE_PATTERN, $question, $loose) > 0) {
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
     * A phone number whose digits are split by spaces, dots, dashes or
     * brackets: 7 or more digits that don't read as a date ("2026-10-01",
     * "1-15 2026") or as a list of small numbers ("30 60 90 180").
     */
    private static function hasPhoneNumber(#[\SensitiveParameter] string $question): bool
    {
        self::matchAll(self::DIGIT_GROUPS_PATTERN, $question, $runs);

        foreach ($runs[0] ?? [] as $run) {
            $groups = array_values(array_filter(self::split('/\D++/u', $run), fn (string $group) => $group !== ''));

            // One unbroken run is the digit-run rule's (DIGIT_RUN_PATTERN).
            if (count($groups) < 2 || strlen(implode('', $groups)) < self::PHONE_DIGITS_MIN) {
                continue;
            }

            $isYear = fn (string $group): bool => strlen($group) === 4 && in_array(substr($group, 0, 2), ['19', '20'], true);
            $years = array_filter($groups, $isYear);
            $small = array_filter($groups, fn (string $group) => strlen($group) <= 2);

            if ($years !== [] && count($years) + count($small) === count($groups)) {
                continue;
            }

            $marked = self::match('/[().\[\]\-]/u', trim($run));
            $long = array_filter($groups, fn (string $group) => strlen($group) >= 4 && ! $isYear($group));

            if (strlen($groups[0]) >= 3 || $marked || $long !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * "find Juan Dela Cruz", "details of the loan of Rosa Santos", "hanapin
     * si Aling Nena": the name after the wording and its lead-in words. "Find
     * out" counts only with a name after it ("Find out about Juan").
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     * @param  list<string>  $words
     */
    private static function byLookupWording(#[\SensitiveParameter] string $question, array $tokens, array $words): ?LindaLookupRequest
    {
        if (! self::match(self::LOOKUP_PATTERN, $question, $found, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $at = self::tokenAfter($tokens, $found[0][1] + strlen($found[0][0]));
        $findOut = mb_strtolower($found[1][0]) === 'find' && ($tokens[$at]['lower'] ?? null) === 'out';
        $at += $findOut ? 1 : 0;
        $named = false;

        while ($at < count($tokens) && in_array($tokens[$at]['lower'], self::LEAD_INS, true)) {
            $named = $named || in_array($tokens[$at]['lower'], self::NAMED_WORDS, true);
            $at++;
        }

        [$name] = self::nameFrom($question, $tokens, $at, $named);

        if ($name !== null) {
            return self::request(LindaLookupRequest::MEMBER, null, $name);
        }

        return $findOut ? null : self::request(self::kindByWords($words));
    }

    /**
     * "Who is Juan Dela Cruz?", in any case, with a name-like word after it,
     * so "who is behind on payments?" stays a question for the model.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byWhoIs(#[\SensitiveParameter] string $question, array $tokens): ?LindaLookupRequest
    {
        if (! self::match(self::WHO_IS_PATTERN, $question, $found, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        [$name] = self::nameFrom($question, $tokens, self::tokenAfter($tokens, $found[0][1] + strlen($found[0][0])));

        return $name === null ? null : self::request(LindaLookupRequest::MEMBER, null, $name);
    }

    /**
     * A person marker before a name-like word ("Kumusta si Juan?", "utang ni
     * Juan"), or with a money word and nothing after it ("Pila ang utang
     * ni?"). A marker before a common word ("kay wala", "ni isa") is no
     * marker. A payment-made word makes it a receipt lookup.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byPersonMarker(#[\SensitiveParameter] string $question, array $tokens): ?LindaLookupRequest
    {
        $lowers = array_column($tokens, 'lower');
        $hasMoneyWord = array_intersect($lowers, self::MONEY_WORDS) !== [];
        $kind = array_intersect($lowers, self::PAYMENT_MADE_WORDS) !== [] ? LindaLookupRequest::RECEIPT : LindaLookupRequest::MEMBER;

        foreach ($tokens as $at => $token) {
            if (! in_array($token['lower'], self::PERSON_MARKERS, true)) {
                continue;
            }

            $next = $tokens[$at + 1]['lower'] ?? null;

            if ($next === null) {
                if ($hasMoneyWord) {
                    return self::request($kind);
                }

                continue;
            }

            // A common word after the marker: Bisaya "kay wala" ("because"), "ni isa".
            if (self::isStopWord($next) && ! self::isTitle($next)) {
                continue;
            }

            // Bisaya "kay" ("because") before a verb typed in lower case: "kay gikinahanglan nako".
            if ($token['lower'] === 'kay' && self::isLowerCaseVerb($tokens[$at + 1]['text'])) {
                continue;
            }

            [$name] = self::nameFrom($question, $tokens, $at + 1);

            if ($name !== null) {
                return self::request($kind, null, $name);
            }

            if ($hasMoneyWord) {
                return self::request($kind);
            }
        }

        return null;
    }

    /**
     * "Juan Dela Cruz's balance", "Maria’s loans", "Ana Lim's payments": a
     * name-like word with "'s" and a balance, loan or payment word. Payments
     * make it a receipt lookup.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byPossessive(#[\SensitiveParameter] string $question, array $tokens): ?LindaLookupRequest
    {
        if (array_intersect(array_column($tokens, 'lower'), self::MONEY_WORDS) === []) {
            return null;
        }

        foreach ($tokens as $at => $token) {
            $base = self::possessiveBase($token['lower']);

            if ($base === null || ! (self::isNameWord($base) || ($at > 0 && self::isSurnameStopWord($question, $tokens, $at, self::possessiveBase($token['text']) ?? '')))) {
                continue;
            }

            $end = $token['start'] + strlen((string) self::possessiveBase($token['text']));
            $start = $at;
            while ($start > 0 && $at - $start < self::MAX_NAME_WORDS - 1 && self::isNameWord($tokens[$start - 1]['lower'])) {
                $start--;
            }

            $name = self::cleanName(substr($question, $tokens[$start]['start'], $end - $tokens[$start]['start']));
            if ($name !== null) {
                $kind = in_array($tokens[$at + 1]['lower'] ?? null, self::PAYMENT_NOUNS, true) ? LindaLookupRequest::RECEIPT : LindaLookupRequest::MEMBER;

                return self::request($kind, null, $name);
            }
        }

        return null;
    }

    /**
     * "balance of Pedro Reyes", "payments by Ana", "collections from Juan": a
     * balance, loan, payment or collection word, then of, by or ng (or "from"
     * after a payment or collection word), then a name. Payments and
     * collections make it a receipt lookup. One that names a branch
     * ("payments of Main branch") or a category ("payments by product") is
     * not a lookup; one with nothing after "of" ("what's the balance of?")
     * is, with no name.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byOf(#[\SensitiveParameter] string $question, array $tokens): ?LindaLookupRequest
    {
        foreach ($tokens as $at => $token) {
            if (! in_array($token['lower'], self::MONEY_WORDS, true)) {
                continue;
            }

            $paying = in_array($token['lower'], [...self::PAYMENT_NOUNS, ...self::COLLECTION_WORDS], true);
            $of = $tokens[$at + 1]['lower'] ?? null;

            if (! in_array($of, self::OF_WORDS, true) && ! ($paying && $of === 'from')) {
                continue;
            }

            if (! isset($tokens[$at + 2])) {
                return self::request(LindaLookupRequest::MEMBER);
            }

            [$name, $end] = self::nameFrom($question, $tokens, $at + 2);

            if ($name !== null && ! in_array($tokens[$end]['lower'] ?? null, self::BRANCH_WORDS, true)) {
                return self::request($paying ? LindaLookupRequest::RECEIPT : LindaLookupRequest::MEMBER, null, $name);
            }
        }

        return null;
    }

    /**
     * "How much does Juan Dela Cruz still owe?", "Did Pedro pay?": the name
     * just before an owe or pay verb. A pay verb makes it a receipt lookup.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byVerb(#[\SensitiveParameter] string $question, array $tokens): ?LindaLookupRequest
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
            while ($start > 0 && $end - $start < self::MAX_NAME_WORDS
                && (self::isNameWord($tokens[$start - 1]['lower']) || ($start - 1 > 0 && self::isSurnameStopWord($question, $tokens, $start - 1)))) {
                $start--;
            }

            if ($start < $end) {
                $name = self::cleanName(substr($question, $tokens[$start]['start'], $tokens[$end - 1]['end'] - $tokens[$start]['start']));

                if ($name !== null) {
                    return self::request(in_array($token['lower'], self::PAY_VERBS, true) ? LindaLookupRequest::RECEIPT : LindaLookupRequest::MEMBER, null, $name);
                }
            }
        }

        return null;
    }

    /**
     * A follow-up phrase and only name-like words: "how about Juan Dela
     * Cruz?", "What about Pedro naman?".
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byFollowUpPhrase(#[\SensitiveParameter] string $question, array $tokens): ?LindaLookupRequest
    {
        $at = self::afterPhrase($tokens, self::FOLLOW_UP_PHRASES);

        if ($at === 0) {
            return null;
        }

        $name = self::onlyName($question, $tokens, $at);

        return $name === null ? null : self::request(LindaLookupRequest::MEMBER, null, $name);
    }

    /**
     * Name-like words right before or after a balance, loan, payment or
     * collection word: "Juan Dela Cruz balance", "Rosa Santos utang",
     * "balance Juan Dela Cruz". The name must be capitalised as typed, a
     * capital on the question's first word not counting, so ordinary words
     * ("salary loans", "Pangkabuhayan loans") are no name. Payments and
     * collections make it a receipt lookup; a name followed by a branch word
     * is a branch.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function byMoneyWord(#[\SensitiveParameter] string $question, array $tokens): ?LindaLookupRequest
    {
        foreach ($tokens as $at => $token) {
            if (! in_array($token['lower'], self::MONEY_WORDS, true)) {
                continue;
            }

            $kind = in_array($token['lower'], [...self::PAYMENT_NOUNS, ...self::COLLECTION_WORDS], true) ? LindaLookupRequest::RECEIPT : LindaLookupRequest::MEMBER;

            $start = $at;
            while ($start > 0 && $at - $start < self::MAX_NAME_WORDS
                && (self::isNameWord($tokens[$start - 1]['lower']) || ($start - 1 > 0 && self::isSurnameStopWord($question, $tokens, $start - 1)))) {
                $start--;
            }

            if ($start < $at && self::capitalisedName($tokens, $start, $at)) {
                $name = self::cleanName(substr($question, $tokens[$start]['start'], $tokens[$at - 1]['end'] - $tokens[$start]['start']));

                if ($name !== null) {
                    return self::request($kind, null, $name);
                }
            }

            [$name, $end] = self::nameFrom($question, $tokens, $at + 1);
            $nameStart = $end - count(self::tokens((string) $name));

            if ($name !== null && self::capitalisedName($tokens, $nameStart, $end) && ! in_array($tokens[$end]['lower'] ?? null, self::BRANCH_WORDS, true)) {
                return self::request($kind, null, $name);
            }
        }

        return null;
    }

    /**
     * Whether the tokens from $start to before $end are all capitalised as
     * typed, the question's first word aside (its capital proves nothing), with
     * at least one other word.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function capitalisedName(array $tokens, int $start, int $end): bool
    {
        $checked = 0;

        for ($i = max($start, 1); $i < $end; $i++) {
            if (! self::isCapitalised($tokens[$i]['text'])) {
                return false;
            }

            $checked++;
        }

        return $checked > 0;
    }

    /**
     * The name starting at a token, and the index of the token after it:
     * titles skipped, then name-like words up to the first stop word, as
     * typed. A trailing "'s" ends the name and is dropped. After "named" the
     * name runs on through generic words ("Test Member One") to a period, a
     * marker or the end.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     * @return array{0: ?string, 1: int}
     */
    private static function nameFrom(#[\SensitiveParameter] string $question, array $tokens, int $at, bool $named = false): array
    {
        while ($at < count($tokens) && self::isTitle($tokens[$at]['lower'])) {
            $at++;
        }

        $end = $at;
        $cut = null;

        while ($end < count($tokens) && $end - $at < self::MAX_NAME_WORDS) {
            $token = $tokens[$end];
            $base = $named ? null : self::possessiveBase($token['text']);

            if ($base !== null) {
                if (self::isNameWord(mb_strtolower($base)) || ($end > $at && self::isSurnameStopWord($question, $tokens, $end, $base))) {
                    $cut = $token['start'] + strlen($base);
                    $end++;
                }

                break;
            }

            $fits = $named
                ? self::isNamedWord($token['lower'])
                : self::isNameWord($token['lower']) || ($end > $at && self::isSurnameStopWord($question, $tokens, $end));

            if (! $fits) {
                break;
            }

            $end++;
        }

        if ($end === $at) {
            return [null, $at];
        }

        $stop = $cut ?? $tokens[$end - 1]['end'];

        return [self::cleanName(substr($question, $tokens[$at]['start'], $stop - $tokens[$at]['start'])), $end];
    }

    /**
     * The name that makes up the rest of a question from a token: 1–6
     * name-like words, a trailing particle ("naman", "po") and a trailing "'s"
     * left out; null when anything else is there.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function onlyName(#[\SensitiveParameter] string $question, array $tokens, int $at): ?string
    {
        $last = count($tokens);
        while ($last > $at && in_array($tokens[$last - 1]['lower'], self::TRAILING_PARTICLES, true)) {
            $last--;
        }

        if ($last === $at || $last - $at > self::MAX_NAME_WORDS) {
            return null;
        }

        [$name, $end] = self::nameFrom($question, $tokens, $at);

        return $end === $last ? $name : null;
    }

    /**
     * The index of the token after a phrase the question starts with, or 0.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     * @param  list<string>  $phrases  longest first
     */
    private static function afterPhrase(array $tokens, array $phrases): int
    {
        $lowers = array_column($tokens, 'lower');

        foreach ($phrases as $phrase) {
            $parts = explode(' ', $phrase);

            if (array_slice($lowers, 0, count($parts)) === $parts) {
                return count($parts);
            }
        }

        return 0;
    }

    /** A name as typed, trimmed and its spaces squeezed; null when it is only month names. */
    private static function cleanName(#[\SensitiveParameter] string $name): ?string
    {
        $name = trim(self::replace('/\s++/u', ' ', $name), " \t\n\r\0\x0B.,");
        $words = array_map(fn (array $token) => $token['lower'], self::tokens($name));

        if ($words === [] || array_diff($words, self::MONTH_WORDS) === []) {
            return null;
        }

        return $name;
    }

    /** A word that can be part of a member's name: no digit, no trailing "'s", and no stop word. */
    private static function isNameWord(#[\SensitiveParameter] string $lower): bool
    {
        if (self::match('/\d/u', $lower) || self::possessiveBase($lower) !== null) {
            return false;
        }

        if (self::isStopWord(str_replace(["'", '’'], '', $lower))) {
            return false;
        }

        foreach (self::split('/-/u', $lower) as $part) {
            if (self::isStopWord($part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A short stop word that is also a surname ("Ang", "Tan"): capitalised as
     * typed, straight after a name word with only spaces between, and not a
     * function word, a marker or a money word.
     *
     * @param  list<array{text: string, lower: string, start: int, end: int}>  $tokens
     */
    private static function isSurnameStopWord(#[\SensitiveParameter] string $question, array $tokens, int $at, #[\SensitiveParameter] ?string $text = null): bool
    {
        $text ??= $tokens[$at]['text'];
        $lower = mb_strtolower($text);
        $previous = $tokens[$at - 1] ?? null;

        if ($previous === null || mb_strlen($lower) > 3 || ! self::isStopWord($lower)
            || in_array($lower, [...self::NEVER_IN_A_NAME, ...self::PERSON_MARKERS, ...self::MONEY_WORDS], true)) {
            return false;
        }

        $first = mb_substr($text, 0, 1);
        $between = substr($question, $previous['end'], $tokens[$at]['start'] - $previous['end']);

        return $first !== mb_strtolower($first) && trim($between, " \t") === '' && self::isNameWord($previous['lower']);
    }

    /**
     * A Bisaya or Tagalog verb typed in lower case: a verb prefix (gi-, mi-,
     * nag-, naka-, …) on a word of 5 or more letters, never a title.
     */
    private static function isLowerCaseVerb(#[\SensitiveParameter] string $text): bool
    {
        $lower = mb_strtolower($text);

        if ($text !== $lower || mb_strlen($lower) < 5 || self::isTitle($lower)) {
            return false;
        }

        foreach (self::VERB_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a word starts with a capital letter as typed. */
    private static function isCapitalised(#[\SensitiveParameter] string $text): bool
    {
        $first = mb_substr($text, 0, 1);

        return $first !== mb_strtolower($first);
    }

    /** The word before a trailing "'s" ("Cruz's" → "Cruz"), or null. */
    private static function possessiveBase(#[\SensitiveParameter] string $word): ?string
    {
        return self::match("/^(.+)['’][sS]$/u", $word, $found) ? $found[1] : null;
    }

    /** A word that can be part of a name given after "named": generic words too, never a period, marker or number. */
    private static function isNamedWord(#[\SensitiveParameter] string $lower): bool
    {
        $hardStops = ['in', 'at', 'sa', 'from', 'for', 'with', 'and', 'ug', 'og', 'as', 'on', 'to', 'ni', 'si', 'kang', 'kay',
            'today', 'yesterday', 'this', 'last', 'karon', 'gahapon', 'kahapon', 'ngayon', 'ngayong', 'please', 'pls', 'po', 'palihug'];

        return ! self::match('/\d/u', $lower) && ! in_array($lower, $hardStops, true);
    }

    private static function isStopWord(#[\SensitiveParameter] string $lower): bool
    {
        return in_array(rtrim($lower, '.'), self::STOP_WORDS, true);
    }

    private static function isTitle(#[\SensitiveParameter] string $lower): bool
    {
        return in_array(rtrim($lower, '.'), self::TITLES, true);
    }

    /**
     * The words of the raw question with their byte offsets, so a name keeps
     * its case and spacing. A word may hold inner dots, dashes and apostrophes
     * ("Ma.", "Jean-Paul", "O'Neil", "Cruz's").
     *
     * @return list<array{text: string, lower: string, start: int, end: int}>
     */
    private static function tokens(#[\SensitiveParameter] string $text): array
    {
        self::matchAll("/[\\pL\\pM\\pN]++(?:[.'’\\-][\\pL\\pM\\pN]++)*+\\.?/u", $text, $found, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match) => [
            'text' => $match[0],
            'lower' => mb_strtolower($match[0], 'UTF-8'),
            'start' => $match[1],
            'end' => $match[1] + strlen($match[0]),
        ], $found[0] ?? []);
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
    private static function kindByWords(#[\SensitiveParameter] array $words): string
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

    /**
     * preg_match() that fails closed: a regular-expression error throws, and
     * parse() reads the question as a lookup.
     *
     * @param  array<int|string, mixed>|null  $found
     */
    private static function match(string $pattern, #[\SensitiveParameter] string $subject, ?array &$found = null, int $flags = 0): bool
    {
        $result = preg_match($pattern, $subject, $found, $flags);

        if ($result === false) {
            throw new UnexpectedValueException('preg_match');
        }

        return $result === 1;
    }

    /**
     * @param  array<int|string, mixed>|null  $found
     */
    private static function matchAll(string $pattern, #[\SensitiveParameter] string $subject, ?array &$found = null, int $flags = PREG_PATTERN_ORDER): int
    {
        $result = preg_match_all($pattern, $subject, $found, $flags);

        if ($result === false) {
            throw new UnexpectedValueException('preg_match_all');
        }

        return $result;
    }

    private static function replace(string $pattern, string $replacement, #[\SensitiveParameter] string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        if ($result === null) {
            throw new UnexpectedValueException('preg_replace');
        }

        return $result;
    }

    /** @return list<string> */
    private static function split(string $pattern, #[\SensitiveParameter] string $subject): array
    {
        $result = preg_split($pattern, $subject);

        if ($result === false) {
            throw new UnexpectedValueException('preg_split');
        }

        return $result;
    }
}
