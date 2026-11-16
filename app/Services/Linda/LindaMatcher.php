<?php

namespace App\Services\Linda;

/**
 * The deterministic matcher: answers the five suggested questions, their
 * English and Taglish variants, their periods, branches and follow-ups,
 * without a model.
 *
 * It answers only when it understands every word. The question is cleaned
 * (LindaText), then the period, a follow-up phrase, a branch phrase and the
 * subject are taken out in that order. Every word left over must be a filler
 * word; one unknown word ("from GCash", "efficiency", "weather") and it
 * returns null, so the question goes to the model or gets the help text.
 *
 * A branch is returned as the text the user wrote ("san jose"), never looked
 * up here: the caller matches it among the user's own branches.
 */
final class LindaMatcher
{
    /**
     * Subject phrases, in cleaned text, for each intent the matcher answers.
     * A question must name exactly one subject, except a follow-up.
     *
     * @var array<string, list<string>>
     */
    private const SUBJECTS = [
        'collection_summary' => [
            'collect', 'collected', 'collecting', 'collection', 'collections',
            'koleksyon', 'koleksiyon', 'nakolekta', 'kolekta',
        ],
        'overdue_accounts' => [
            'overdue', 'past due', 'atraso', 'naatraso', 'delayed', 'delinquent', 'late payments', 'late payment',
        ],
        'loan_summary' => [
            'active loans', 'active loan', 'outstanding loans', 'outstanding loan', 'outstanding',
        ],
        'disbursement_summary' => [
            'disburse', 'disbursed', 'disbursing', 'disbursement', 'disbursements',
            'released', 'releases', 'nailabas', 'nai release', 'na release', 'nirelease',
        ],
        'general_lending_summary' => [
            'summary', 'buod', 'overview', 'lending summary',
        ],
    ];

    /**
     * Phrases that mark a question as following on from the previous one.
     *
     * @var list<string>
     */
    private const FOLLOW_UP_PHRASES = [
        'and how about', 'and what about', 'how about', 'what about', 'paano naman', 'pano naman',
        'paano kung', 'eh di', 'eh', 'e', 'naman',
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
        'whats', 'which', 'who', 'whos', 'with', 'would', 'you',
        'account', 'accounts', 'amount', 'amounts', 'loan', 'loans', 'member', 'members', 'report',
        // Tagalog
        'ako', 'ang', 'ano', 'anong', 'ba', 'bigay', 'ilan', 'ilang', 'ito', 'kabuuan', 'kami', 'ko',
        'lahat', 'magkano', 'may', 'mga', 'mo', 'na', 'namin', 'natin', 'nating', 'ng', 'nang', 'ngayon',
        'paki', 'pakibigay', 'pakita', 'pakipakita', 'po', 'sa', 'sino', 'sinong', 'sinu', 'tayo',
        'ung', 'yung',
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
        [$words, $branchName] = self::takeBranch($words);
        [$words, $subjects] = self::takeSubjects($words);

        foreach ($words as $word) {
            if (! in_array($word, self::FILLERS, true)) {
                return null;
            }
        }

        $canFollow = $previous !== null && $previous !== LindaIntent::UnsupportedQuestion;

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
     * Take a branch phrase out of the words: "<name> branch" ("in Main
     * branch", "at the San Jose branch") or "sa <name>" ("sa Cabadbaran").
     * The name is the run of unknown words next to it, at most
     * self::MAX_BRANCH_WORDS.
     *
     * @param  list<string>  $words
     * @return array{list<string>, ?string}
     */
    private static function takeBranch(array $words): array
    {
        $branchAt = array_search('branch', $words, true);

        if ($branchAt !== false) {
            $start = $branchAt;
            while ($start > 0 && $branchAt - $start < self::MAX_BRANCH_WORDS && self::isNameWord($words[$start - 1])) {
                $start--;
            }

            if ($start < $branchAt) {
                $name = implode(' ', array_slice($words, $start, $branchAt - $start));

                return [self::without($words, $start, $branchAt - $start + 1), $name];
            }

            return [$words, null];
        }

        foreach ($words as $at => $word) {
            if ($word !== 'sa') {
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
     * "Summary" beside another subject only describes it ("collection
     * summary"), so it counts only on its own.
     *
     * @param  list<string>  $words
     * @return array{list<string>, list<LindaIntent>}
     */
    private static function takeSubjects(array $words): array
    {
        $phrases = [];
        foreach (self::SUBJECTS as $intent => $list) {
            foreach ($list as $phrase) {
                $phrases[$phrase] = $intent;
            }
        }
        uksort($phrases, fn (string $a, string $b) => substr_count($b, ' ') <=> substr_count($a, ' ') ?: strlen($b) <=> strlen($a));

        $intents = [];
        foreach ($phrases as $phrase => $intent) {
            $parts = explode(' ', $phrase);
            while (($at = self::find($words, $parts)) !== null) {
                $words = self::without($words, $at, count($parts));
                $intents[$intent] = true;
            }
        }

        $summary = LindaIntent::GeneralLendingSummary->value;
        if (count($intents) > 1) {
            unset($intents[$summary]);
        }

        return [$words, array_map(fn (string $intent) => LindaIntent::from($intent), array_keys($intents))];
    }

    /** A word that can be part of a branch's name: one the matcher doesn't otherwise know. */
    private static function isNameWord(string $word): bool
    {
        if (in_array($word, self::FILLERS, true) || $word === 'branch') {
            return false;
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
