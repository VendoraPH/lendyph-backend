<?php

namespace App\Services\Linda;

/**
 * The deterministic matcher: answers the five suggested questions, collection
 * efficiency and branch and officer performance, in English, Tagalog/Taglish
 * and Bisaya/Cebuano, with their periods, branches and follow-ups, without a
 * model.
 *
 * It answers only when it understands every word. The question is cleaned
 * (LindaText), then the period, a follow-up phrase, the officer words of a
 * training question, a branch or officer performance subject, a branch phrase
 * and the subject are taken out in that order. Every word left over must be a
 * filler word, or one of the words its one subject understands (savings,
 * CDA, reminders: "net growth", "why", "failed"); one unknown word ("from
 * GCash", "interest", "weather") and it returns null, so the question goes
 * to the model or gets the help text.
 *
 * A branch is returned as the text the user wrote ("san jose"), never looked
 * up here: the caller matches it among the user's own branches.
 */
final class LindaMatcher
{
    /**
     * Subject phrases, in cleaned text, for each intent the matcher answers,
     * in English, Tagalog and Bisaya. A question must name exactly one
     * subject, except a follow-up.
     *
     * @var array<string, list<string>>
     */
    private const SUBJECTS = [
        'collection_summary' => [
            'collect', 'collected', 'collecting', 'collection', 'collections',
            'koleksyon', 'koleksiyon', 'nakolekta', 'kolekta', 'nakubra', 'kubra', 'bayad', 'nabayad', 'gibayad',
            'nabayaran', 'pumasok na bayad', 'pumasok na pera',
            'nadawat nga bayad', 'bayad nga nadawat', 'nadawat nga kwarta', 'kwarta nga nadawat', 'nadawat nga pera',
            'pera nga nadawat', 'nadawat nga cash', 'nadawat nga payment', 'nadawat nga payments',
        ],
        'overdue_accounts' => [
            'overdue', 'past due', 'atraso', 'naatraso', 'atrasado', 'delayed', 'delinquent', 'late payments', 'late payment', 'late',
            'nalangan', 'lapas na sa due', 'lapas sa due', 'lapas na due', 'wala pa nakabayad', 'wala pa makabayad', 'wala pay bayad',
            'dili pa bayad', 'hindi pa nakakabayad', 'hindi pa nakabayad', 'hindi nakabayad', 'hindi pa bayad',
        ],
        'loan_summary' => [
            'active loans', 'active loan', 'outstanding loans', 'outstanding loan', 'outstanding',
            'aktibo nga loans', 'aktibo nga loan', 'aktibong loans', 'aktibong loan', 'active nga loans', 'active nga loan', 'aktibo',
            'nagdagan pa nga loans', 'nagdagan pa nga loan', 'nagdagan nga loans', 'nagdagan nga loan', 'nagdagan',
            'tumatakbong loans', 'tumatakbong loan', 'tumatakbo',
        ],
        'disbursement_summary' => [
            'disburse', 'disbursed', 'disbursing', 'disbursement', 'disbursements',
            'released', 'releases', 'release', 'nailabas', 'nai release', 'na release', 'nirelease', 'gi release',
            'gipagawas', 'napagawas', 'nahatag nga loans', 'nahatag nga loan', 'gihatag nga loans', 'gihatag nga loan',
            'naibigay na loans', 'naibigay na loan',
        ],
        'general_lending_summary' => [
            'summary', 'buod', 'overview', 'lending summary', 'kinatibuk ang summary', 'kinatibuk an', 'kinatibuk ang',
        ],
        'collection_efficiency' => [
            'collection efficiency', 'collections efficiency', 'efficiency', 'collection rate', 'collections rate',
            'repayment rate', 'collection percentage', 'percent', 'percentage', 'porsyento sa koleksyon',
            'porsyento ng koleksyon', 'porsyento', 'porsiyento',
        ],
        'portfolio_summary' => [
            'portfolio', 'portfolio summary', 'loan portfolio', 'portfolio at risk', 'par', 'par 1', 'par 30', 'par 90',
            'principal balance', 'outstanding principal', 'portpolyo',
        ],
        'income_summary' => [
            'income', 'interest income', 'penalty income', 'fee income', 'income report', 'revenue', 'revenues', 'earn',
            'earned', 'earnings', 'kita', 'kinita', 'kinitaan', 'ganansya', 'ganansiya',
        ],
        'expense_summary' => [
            'expense', 'expenses', 'spend', 'spent', 'spending', 'gastos', 'gasto', 'gigasto', 'gastu', 'gastohan',
        ],
        'savings_summary' => [
            'savings', 'savings account', 'savings accounts', 'savings deposits', 'deposits', 'total deposits',
            'time deposit', 'time deposits', 'maturing time deposits', 'maturing', 'ipon', 'impok', 'tinipid', 'tinigom', 'tigom',
            // Round 4: money in and out over a period, and dormant accounts. Never
            // a bare "deposit" or "withdraw": "How do I withdraw?" asks for a page.
            'deposited', 'withdrawal', 'withdrawals', 'withdrawn', 'withdrew', 'deposit growth', 'savings growth',
            'dormant', 'dormant account', 'dormant accounts', 'deposito', 'nadeposito', 'gideposito', 'nideposito',
            'nadeposit', 'gideposit', 'nideposit', 'na deposit', 'nag deposit', 'gi deposit', 'ni deposit',
            'nawithdraw', 'giwithdraw', 'niwithdraw', 'na withdraw', 'nag withdraw', 'gi withdraw', 'ni withdraw',
        ],
        'share_capital_summary' => [
            'share capital', 'share capitals', 'capital build up', 'capital buildup', 'cbu', 'capital', 'kapital',
            'share capital ledger', 'share capital balance',
        ],
        'cda_summary' => [
            'cda', 'cda deadline', 'cda deadlines', 'cda readiness', 'cda report', 'cda reports', 'cda compliance',
            'compliance', 'compliance status', 'readiness', 'ready', 'deadline', 'deadlines', 'next deadline', 'next deadlines', 'next cda deadline', 'next cda deadlines',
            'sunod nga deadline', 'susunod na deadline', 'handa', 'andam',
            // Round 4: the reports by name, officers' trainings, the PESOS rating
            // and missing documents.
            'capr', 'enhanced pesos', 'pesos rating', 'pesos score', 'pesos report', 'social audit', 'performance audit',
            'sworn statement', 'atir', 'abr', 'mediation', 'training', 'trainings', 'pagsasanay', 'pagbansay',
            'cda documents', 'cda document', 'compliance documents', 'compliance document', 'missing documents', 'missing document',
        ],
        'reminder_summary' => [
            'reminder', 'reminders', 'payment reminder', 'payment reminders', 'due date reminder', 'due date reminders',
            'sms', 'sms reminder', 'sms reminders', 'email reminder', 'email reminders', 'text reminder', 'text reminders',
            'paalala', 'pahinumdom', 'pahimangno',
        ],
        'borrower_summary' => [
            'active members', 'active member', 'inactive members', 'inactive member', 'blacklisted members',
            'blacklisted member', 'new members', 'new member', 'repeat members', 'repeat member', 'repeat borrowers',
            'repeat borrower', 'membership', 'member count', 'members count',
            'aktibong miyembro', 'aktibong members', 'active na miyembro', 'active na members', 'bagong miyembro',
            'bagong members', 'bagong member',
            'aktibo nga miyembro', 'aktibo nga members', 'active nga miyembro', 'active nga members',
            'bag ong miyembro', 'bag ong members', 'bag ong member',
        ],
    ];

    /**
     * Subject phrases that count only when no other subject is named: "pila
     * ka loan" is "how many loans", unless the question also says what
     * happened to them ("pila ka loan ang na-release").
     *
     * @var array<string, list<string>>
     */
    private const WEAK_SUBJECTS = [
        'loan_summary' => ['pila ka loans', 'pila ka loan', 'pila kabuok nga loan', 'pila kabuok loan'],
        'borrower_summary' => ['members', 'member', 'miyembro', 'myembro', 'kasapi'],
        // "Which documents are missing?" asks for the CDA documents.
        'cda_summary' => ['documents', 'document', 'dokumento', 'dokumentos'],
    ];

    /**
     * Words that keep a weak subject from counting: "how many loans do our
     * members have" is about loans, and "which loan documents are missing" isn't
     * about the CDA documents.
     *
     * @var array<string, list<string>>
     */
    private const WEAK_CANCELLED_BY = [
        'borrower_summary' => ['loan', 'loans'],
        'cda_summary' => ['loan', 'loans', 'payment', 'payments', 'account', 'accounts'],
    ];

    /**
     * Words understood only in a question whose one subject is that topic
     * (round 4): "net growth", "why", "failed" mean nothing on their own.
     *
     * @var array<string, list<string>>
     */
    private const TOPIC_WORDS = [
        'savings_summary' => [
            'hold', 'held', 'holding', 'net', 'growth', 'grow', 'grew', 'grown', 'increase', 'increased', 'decrease',
            'decreased', 'movement', 'movements', 'change', 'performing', 'doing', 'mature', 'matures', 'matured',
            'maturity', 'deposit', 'withdraw', 'came', 'out', 'money', 'pera', 'kwarta',
        ],
        'cda_summary' => [
            'why', 'bakit', 'ngano', 'nganong', 'only', 'just', 'still', 'yet', 'not', 'no', 'hindi', 'dili', 'wala',
            'low', 'complete', 'completed', 'incomplete', 'kumpleto', 'kompleto', 'missing', 'lacking', 'lack', 'kulang',
            'kulangan', 'pending', 'needed', 'required', 'requires', 'requirement', 'requirements', 'kailangan',
            'kinahanglan', 'submit', 'submitted', 'submission', 'file', 'filed', 'filing', 'ipasa', 'isumite', 'rating',
            'rated', 'score', 'grade', 'enhanced', 'pesos', 'reports', 'upload', 'uploaded', 'financial', 'statements',
            'statement', 'audited', 'audit', 'social', 'performance', 'sworn', 'conciliation', 'tax', 'incentives',
            'benefits', 'annual', 'progress', 'issue', 'issues', 'critical', 'problem', 'problems', 'problema', 'status',
        ],
        'reminder_summary' => [
            'sent', 'delivered', 'failed', 'fail', 'failing', 'pending', 'logged', 'scheduled', 'queued', 'email',
            'emails', 'text', 'texts', 'message', 'messages', 'borrower', 'borrowers', 'receive', 'received', 'napadala',
            'naipadala', 'ipinadala', 'gipadala', 'nipadala', 'napadalhan', 'nasend', 'gisend', 'nag', 'nafail',
            'pumalya', 'napakyas', 'test', 'mode', 'due', 'date', 'delivery', 'status',
        ],
    ];

    /** Words for a training: an officer word beside one is about the CDA officers' trainings. */
    private const TRAINING_WORDS = ['training', 'trainings', 'pagsasanay', 'pagbansay'];

    /** The officer words a training question drops, so it is never officer performance. */
    private const TRAINED_OFFICER_WORDS = ['officer', 'officers', 'opisyal', 'opisyales'];

    /**
     * Officer words: any of them makes the question about officer
     * performance; the figure asked about ("collected", "released") only
     * ranks it.
     *
     * @var list<string>
     */
    private const OFFICER_PHRASES = [
        'loan officers', 'loan officer', 'account officers', 'account officer', 'officers', 'officer',
        'collectors', 'collector', 'kolektor', 'aos', 'ao',
    ];

    /**
     * Phrases that make a question about branch performance: a branch word
     * after "which", "by", "each" and their Tagalog and Bisaya words.
     *
     * @var list<string>
     */
    private const BRANCH_PERFORMANCE_PHRASES = [
        'which branch', 'what branch', 'anong branch', 'aling branch', 'alin na branch', 'alin branch',
        'unsang branch', 'unsa nga branch', 'asa nga branch', 'asang branch', 'hain nga branch',
        'by branch', 'per branch', 'each branch', 'every branch', 'kada branch', 'matag branch', 'bawat branch',
    ];

    /**
     * "All branches" in any language: no branch filter, and no branch name.
     *
     * @var list<string>
     */
    private const ALL_BRANCHES_PHRASES = [
        'all our branches', 'all my branches', 'all the branches', 'all branches', 'lahat ng mga branch',
        'lahat ng branches', 'lahat ng branch', 'tanang mga branch', 'tanan nga branch', 'tanang branch', 'tanan branch',
    ];

    /**
     * Ranking and comparing words. They are understood only in a branch or
     * officer performance question, which they rank.
     *
     * @var list<string>
     */
    private const RANKING_WORDS = [
        'most', 'least', 'top', 'best', 'worst', 'highest', 'lowest', 'biggest', 'largest', 'smallest', 'rank',
        'ranked', 'ranking', 'rankings', 'compare', 'comparison', 'versus', 'vs', 'performance', 'performing',
        'perform', 'doing', 'well', 'each', 'every', 'by', 'per', 'kada', 'matag', 'bawat', 'ikumpara', 'ikompara',
        'kumpara', 'itandi', 'pinaka', 'pinakadaghan', 'pinakagamay', 'pinakadako', 'pinakamaayo', 'pinakataas',
        'pinakaubos', 'pinakamarami', 'pinakamaliit', 'pinakamalaki', 'pinakamalaking', 'pinakamagaling',
        'pinakamataas', 'pinakamababa', 'daghan', 'daghang', 'dako', 'dakong', 'maayo', 'magaling', 'marami',
        'malaki', 'nanguna', 'nangunguna', 'una',
    ];

    /** Words for a branch. */
    private const BRANCH_WORDS = ['branch', 'branches', 'sanga', 'sangay'];

    /** Words after "in" that never name a branch ("in pesos", "in full"). */
    private const NOT_BRANCH_AFTER_IN = [
        'pesos', 'peso', 'php', 'cash', 'gcash', 'full', 'advance', 'arrears', 'default', 'detail', 'details', 'words',
        'english', 'tagalog', 'filipino', 'bisaya', 'binisaya', 'cebuano', 'numbers', 'short', 'general', 'particular',
        'progress', 'process', 'review', 'approval', 'draft', 'interest', 'penalties', 'penalty', 'fees', 'fee',
        'principal', 'percent', 'percentage',
    ];

    /**
     * Phrases that mark a question as following on from the previous one,
     * longest first.
     *
     * @var list<string>
     */
    private const FOLLOW_UP_PHRASES = [
        'and how about', 'and what about', 'how about', 'what about', 'paano naman', 'pano naman',
        'paano kung', 'kumusta kaha', 'kamusta kaha', 'kumusta ang', 'kamusta ang', 'kumusta', 'kamusta',
        'unsa kaha', 'unsa man', 'pero ang', 'unya ang', 'ug ang', 'unya', 'eh di', 'eh', 'e', 'naman',
    ];

    /**
     * Words that carry no meaning of their own here. Anything else left over
     * means the matcher didn't understand the question.
     *
     * @var list<string>
     */
    private const FILLERS = [
        // English
        'a', 'all', 'am', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'been', 'by', 'can', 'could',
        'current', 'currently', 'did', 'do', 'does', 'far', 'for', 'from', 'get', 'give', 'got', 'had',
        'has', 'have', 'hello', 'hi', 'how', 'i', 'in', 'is', 'it', 'kindly', 'know', 'lending', 'let',
        'list', 'many', 'me', 'much', 'my', 'need', 'now', 'number', 'of', 'on', 'operation',
        'operations', 'our', 'payment', 'payments', 'please', 'pls', 'right', 'see', 'show', 'so',
        'tell', 'the', 'there', 'this', 'to', 'total', 'totals', 'us', 'was', 'we', 'were', 'what',
        'whats', 'which', 'who', 'whos', 'with', 'would', 'you', 'big', 'size', 'soon', 'when',
        'account', 'accounts', 'amount', 'amounts', 'loan', 'loans', 'member', 'members', 'report', 'dues',
        // "Find overdue members", "Search past-due loans": aggregate questions. A
        // name or a record number after them is no filler, so a lookup still
        // falls through to the lookup parser.
        'find', 'search', 'hanapin', 'pangitaa', 'pangita',
        // Tagalog
        'ako', 'ang', 'ano', 'anong', 'ba', 'bigay', 'ilan', 'ilang', 'ito', 'kabuuan', 'kami', 'ko',
        'lahat', 'magkano', 'may', 'mga', 'mo', 'na', 'namin', 'natin', 'nating', 'ng', 'nang', 'ngayon',
        'paki', 'pakibigay', 'pakita', 'pakipakita', 'po', 'sa', 'sino', 'sinong', 'sinu', 'tayo',
        'ung', 'yung', 'pa', 'din', 'rin', 'kailan', 'kelan',
        // Bisaya
        'pila', 'ka', 'kabuok', 'nga', 'naa', 'naay', 'unsa', 'unsay', 'kinsa', 'asa', 'nato', 'namo',
        'atong', 'among', 'ato', 'amo', 'ug', 'og', 'palihug', 'ipakita', 'tan', 'awa', 'lang', 'man',
        'diay', 'kaha', 'ra', 'pud', 'pod', 'sab', 'sad', 'usab', 'kini', 'kana', 'niini', 'bai', 'bay',
        'day', 'dong', 'tanan', 'tanang', 'gyud', 'jud', 'ta', 'kanus', 'kanusa',
    ];

    /** The longest branch name taken from a branch phrase, in words. */
    private const MAX_BRANCH_WORDS = 3;

    public static function match(#[\SensitiveParameter] string $question, ?LindaIntent $previous = null): ?LindaMatch
    {
        $clean = LindaText::clean($question);

        if ($clean === '') {
            return null;
        }

        $found = LindaPeriod::extract($clean);

        if ($found['refused']) {
            return null;
        }

        $period = $found['period'];
        $words = LindaText::words($found['rest']);

        [$words, $followUpPhrase] = self::takeFollowUpPhrase($words);
        $words = self::takeTrainingOfficers($words);
        [$words, $performance] = self::takePerformance($words);
        [$words, $branchName] = self::takeBranch($words);
        [$words, $subjects] = self::takeSubjects($words, $performance);

        // Ranking words ("most", "pinakadaghan") are understood only when
        // they rank branches or officers, and a topic's own words only in a
        // question on that one topic. A CDA question may quote a figure ("only 82%").
        $understood = [
            ...self::FILLERS,
            ...(count($performance) === 1 ? self::RANKING_WORDS : []),
            ...(count($subjects) === 1 ? self::TOPIC_WORDS[$subjects[0]->value] ?? [] : []),
        ];
        $quotesFigures = $subjects === [LindaIntent::CdaSummary];

        foreach ($words as $word) {
            if (! in_array($word, $understood, true) && ! ($quotesFigures && preg_match('/^\d+(?:\.\d+)?$/', $word) === 1)) {
                return null;
            }
        }

        $canFollow = $previous !== null && $previous !== LindaIntent::UnsupportedQuestion && ! $previous->isLookup();

        if ($subjects === []) {
            // A follow-up on its own must bring something new: a period or a branch.
            if (! $canFollow || ($period === null && $branchName === null)) {
                return null;
            }

            return new LindaMatch($previous, $period, $branchName, true);
        }

        if (count($subjects) > 1) {
            return null;
        }

        $intent = $subjects[0];
        $followUp = $followUpPhrase && $canFollow;

        // Overdue and active loans are as of today, so "now", "currently" or
        // "today" on them names no period of their own. Any other period is
        // reported as read; the caller answers them as of today all the same.
        if (! $intent->takesPeriod() && $period?->key === 'today') {
            $period = null;
        }

        // A follow-up without its own period keeps the earlier one, which the
        // caller holds; a fresh question takes its intent's default.
        if ($period === null && ! $followUp && $intent->defaultsToThisMonth()) {
            $period = LindaPeriod::named('this_month');
        }

        return new LindaMatch($intent, $period, $branchName, $followUp);
    }

    /**
     * Take the follow-up phrases out of the words ("eh paano naman").
     *
     * @param  list<string>  $words
     * @return array{list<string>, bool}
     */
    private static function takeFollowUpPhrase(array $words): array
    {
        $found = false;

        foreach (self::FOLLOW_UP_PHRASES as $phrase) {
            $parts = explode(' ', $phrase);

            while (($at = self::find($words, $parts)) !== null) {
                $words = self::without($words, $at, count($parts));
                $found = true;
            }
        }

        return [$words, $found];
    }

    /**
     * Drop the officer words of a question about trainings ("Which officer
     * trainings are missing?", "Sino sa mga opisyal ang kulang ang training?"):
     * it asks about the CDA officers register, never officer performance.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function takeTrainingOfficers(array $words): array
    {
        if (array_intersect($words, self::TRAINING_WORDS) === []) {
            return $words;
        }

        return array_values(array_filter($words, fn (string $word) => ! in_array($word, self::TRAINED_OFFICER_WORDS, true)));
    }

    /**
     * Take a branch or officer performance subject out of the words, and any
     * "all branches" phrase (which names no branch).
     *
     * Any officer word makes it officer performance. A branch word makes it
     * branch performance after "which", "by" or "each" (and their Tagalog
     * and Bisaya words), in the plural ("compare the branches"), or with a
     * ranking word and no name before it ("best branch", "branch
     * performance"); "compare all branches" counts too. Both together are
     * two subjects, so the question goes to the model.
     *
     * @param  list<string>  $words
     * @return array{list<string>, list<LindaIntent>}
     */
    private static function takePerformance(array $words): array
    {
        $words = array_map(fn (string $word) => in_array($word, ['sanga', 'sangay'], true) ? 'branch' : $word, $words);
        $intents = [];

        foreach (self::OFFICER_PHRASES as $phrase) {
            $parts = explode(' ', $phrase);
            while (($at = self::find($words, $parts)) !== null) {
                $words = self::without($words, $at, count($parts));
                $intents[LindaIntent::OfficerPerformance->value] = true;
            }
        }

        $allBranches = false;
        foreach (self::ALL_BRANCHES_PHRASES as $phrase) {
            $parts = explode(' ', $phrase);
            while (($at = self::find($words, $parts)) !== null) {
                $words = self::without($words, $at, count($parts));
                $allBranches = true;
            }
        }

        $branch = LindaIntent::BranchPerformance->value;
        $ranks = array_intersect($words, self::RANKING_WORDS) !== [];

        foreach (self::BRANCH_PERFORMANCE_PHRASES as $phrase) {
            $parts = explode(' ', $phrase);
            while (($at = self::find($words, $parts)) !== null) {
                $words = self::without($words, $at, count($parts));
                $intents[$branch] = true;
            }
        }

        foreach ($words as $at => $word) {
            $plural = $word === 'branches';
            $ranked = $word === 'branch' && $ranks && ($at === 0 || ! self::isNameWord($words[$at - 1]));

            if ($plural || $ranked) {
                unset($words[$at]);
                $intents[$branch] = true;
            }
        }

        if ($allBranches && $ranks) {
            $intents[$branch] = true;
        }

        return [array_values($words), array_map(fn (string $intent) => LindaIntent::from($intent), array_keys($intents))];
    }

    /**
     * Take a branch phrase out of the words: "<name> branch" ("in Main
     * branch", "at the San Jose branch", "sa Main nga branch"), "branch sa
     * <name>", or "sa <name>" / "in <name>" ("sa Cabadbaran", "collections
     * in Main"). The name is the run of unknown words next to it, at most
     * self::MAX_BRANCH_WORDS. "In" before a word that never names a branch
     * ("in pesos", "in full") is no branch.
     *
     * @param  list<string>  $words
     * @return array{list<string>, ?string}
     */
    private static function takeBranch(array $words): array
    {
        $branchAt = array_search('branch', $words, true);

        if ($branchAt !== false) {
            // A ligature between the name and "branch": "Main nga branch", "Main na branch".
            $nameEnd = $branchAt > 0 && in_array($words[$branchAt - 1], ['nga', 'na', 'ng'], true) ? $branchAt - 1 : $branchAt;
            $start = $nameEnd;
            while ($start > 0 && $nameEnd - $start < self::MAX_BRANCH_WORDS && self::isNameWord($words[$start - 1])) {
                $start--;
            }

            if ($start < $nameEnd) {
                $name = implode(' ', array_slice($words, $start, $nameEnd - $start));

                return [self::without($words, $start, $branchAt - $start + 1), $name];
            }

            // "branch sa Main", "branch of Main".
            $at = $branchAt + 1;
            if (in_array($words[$at] ?? null, ['sa', 'ng', 'of', 'nga'], true)) {
                $at++;
            }
            $end = $at;
            while ($end < count($words) && $end - $at < self::MAX_BRANCH_WORDS && self::isNameWord($words[$end])) {
                $end++;
            }

            if ($end > $at) {
                return [self::without($words, $branchAt, $end - $branchAt), implode(' ', array_slice($words, $at, $end - $at))];
            }

            return [$words, null];
        }

        foreach ($words as $at => $word) {
            if ($word !== 'sa' && $word !== 'in') {
                continue;
            }

            if ($word === 'in' && in_array($words[$at + 1] ?? null, self::NOT_BRANCH_AFTER_IN, true)) {
                continue;
            }

            $end = $at + 1;
            while ($end < count($words) && $end - $at - 1 < self::MAX_BRANCH_WORDS && self::isNameWord($words[$end])) {
                $end++;
            }

            if ($end > $at + 1) {
                $name = implode(' ', array_slice($words, $at + 1, $end - $at - 1));

                return [self::without($words, $at, $end - $at), $name];
            }
        }

        return [$words, null];
    }

    /**
     * Take every subject phrase out of the words, longest phrases first.
     *
     * Some subjects only describe another one, so they count only on their
     * own:
     * - a branch or officer performance question ranks by the figure it
     *   names ("which branch collected the most"), so it wins over them all,
     *   except savings: "which branch has the highest savings" is a savings
     *   question, whose answer ranks the branches by their deposits;
     * - reminders win over collections and overdue ("payment reminders",
     *   "overdue reminders");
     * - collection efficiency is about collections ("porsyento sa
     *   koleksyon"), so it wins over collections;
     * - a bare member word ("members", "miyembro") only says whose figures
     *   another subject is about ("which members are overdue"), and beside a
     *   loan word it is a loan question, so it counts only on its own;
     * - "summary" beside another subject only describes it ("collection
     *   summary"), and so do the weak phrases ("pila ka loan", "documents").
     *
     * @param  list<string>  $words
     * @param  list<LindaIntent>  $performance  the performance subjects already taken
     * @return array{list<string>, list<LindaIntent>}
     */
    private static function takeSubjects(array $words, array $performance = []): array
    {
        $phrases = [];
        foreach ([self::SUBJECTS, self::WEAK_SUBJECTS] as $weak => $subjects) {
            foreach ($subjects as $intent => $list) {
                foreach ($list as $phrase) {
                    $phrases[$phrase] = [$intent, (bool) $weak];
                }
            }
        }
        uksort($phrases, fn (string $a, string $b) => substr_count($b, ' ') <=> substr_count($a, ' ') ?: strlen($b) <=> strlen($a));

        $intents = [];
        $weakIntents = [];
        foreach ($phrases as $phrase => [$intent, $weak]) {
            $parts = explode(' ', $phrase);
            while (($at = self::find($words, $parts)) !== null) {
                $words = self::without($words, $at, count($parts));
                if ($weak) {
                    $weakIntents[$intent] = true;
                } else {
                    $intents[$intent] = true;
                }
            }
        }

        if ($performance !== []) {
            if ($performance === [LindaIntent::BranchPerformance] && array_keys($intents) === [LindaIntent::SavingsSummary->value]) {
                return [$words, [LindaIntent::SavingsSummary]];
            }

            return [$words, $performance];
        }

        if (isset($intents[LindaIntent::CollectionEfficiency->value])) {
            unset($intents[LindaIntent::CollectionSummary->value]);
        }

        if (isset($intents[LindaIntent::ReminderSummary->value])) {
            unset($intents[LindaIntent::CollectionSummary->value], $intents[LindaIntent::OverdueAccounts->value]);
        }

        $summary = LindaIntent::GeneralLendingSummary->value;
        if (count($intents) > 1) {
            unset($intents[$summary]);
        }

        if ($intents === []) {
            foreach (self::WEAK_CANCELLED_BY as $intent => $cancelling) {
                if (array_intersect($words, $cancelling) !== []) {
                    unset($weakIntents[$intent]);
                }
            }

            $intents = $weakIntents;
        }

        return [$words, array_map(fn (string $intent) => LindaIntent::from($intent), array_keys($intents))];
    }

    /** A word that can be part of a branch's name: one the matcher doesn't otherwise know. */
    private static function isNameWord(string $word): bool
    {
        if (in_array($word, self::FILLERS, true) || in_array($word, self::BRANCH_WORDS, true) || in_array($word, self::RANKING_WORDS, true)) {
            return false;
        }

        foreach (self::TOPIC_WORDS as $topicWords) {
            if (in_array($word, $topicWords, true)) {
                return false;
            }
        }

        foreach ([...self::OFFICER_PHRASES, ...self::BRANCH_PERFORMANCE_PHRASES, ...self::ALL_BRANCHES_PHRASES, ...array_merge(...array_values(self::WEAK_SUBJECTS))] as $phrase) {
            if (in_array($word, explode(' ', $phrase), true)) {
                return false;
            }
        }

        foreach (self::SUBJECTS as $list) {
            foreach ($list as $phrase) {
                if (in_array($word, explode(' ', $phrase), true)) {
                    return false;
                }
            }
        }

        foreach (self::FOLLOW_UP_PHRASES as $phrase) {
            if (in_array($word, explode(' ', $phrase), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where a run of words starts, or null.
     *
     * @param  list<string>  $words
     * @param  list<string>  $parts
     */
    private static function find(array $words, array $parts): ?int
    {
        $length = count($parts);

        for ($at = 0; $at + $length <= count($words); $at++) {
            if (array_slice($words, $at, $length) === $parts) {
                return $at;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function without(array $words, int $at, int $length): array
    {
        array_splice($words, $at, $length);

        return $words;
    }
}
