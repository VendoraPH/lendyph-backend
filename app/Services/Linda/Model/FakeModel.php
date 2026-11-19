<?php

namespace App\Services\Linda\Model;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaText;

/**
 * The `fake` driver, for tests and local runs: it walks the model path with
 * no network.
 *
 * It is deterministic. Keyword rules in English, Tagalog and Bisaya map a
 * question to an intent, the period it names to period words and a "sa X",
 * "in X" or "X branch" phrase to a branch, and a question with no subject
 * follows on from the latest earlier topic (it sees topics only, never an
 * earlier question's text, as the real model does). Its answers then go through the same LindaUnderstanding as the
 * real model's. It reports fixed usage (1,000 input and 100 output tokens on
 * the requested model) and keeps every request it received.
 */
final class FakeModel implements LindaModel
{
    /** Fixed usage per call. */
    public const INPUT_TOKENS = 1000;

    public const OUTPUT_TOKENS = 100;

    /**
     * Keyword rules, first match wins. Each keyword is matched as whole words
     * in the cleaned question.
     *
     * @var list<array{string, list<string>}>
     */
    private const RULES = [
        ['unsupported_question', ['ignore', 'you are now', 'pretend', 'system prompt', 'instructions', 'weather', 'joke', 'poem', 'delete', 'send', 'kalimti']],
        ['collection_efficiency', ['efficiency', 'collection rate', 'porsyento', 'porsiyento', 'percent', 'percentage']],
        ['income_summary', ['income', 'interest earned', 'earn', 'earned', 'revenue', 'kita', 'kinita', 'ganansya', 'kinitaan']],
        ['expense_summary', ['expense', 'expenses', 'gastos', 'gasto', 'gigasto', 'gastu']],
        ['officer_performance', ['officer', 'officers', 'collector', 'collectors', 'ao', 'aos', 'kolektor']],
        ['branch_performance', ['branches', 'compare', 'ikumpara', 'itandi', 'best branch', 'top branch', 'which branch', 'unsang branch', 'anong branch', 'asa nga branch', 'by branch', 'per branch', 'kada branch']],
        ['portfolio_summary', ['portfolio', 'par', 'par 30']],
        ['share_capital_summary', ['share capital', 'capital build up', 'cbu', 'kapital', 'capital']],
        ['savings_summary', ['savings', 'deposits', 'time deposit', 'time deposits', 'ipon', 'impok', 'tinigom', 'tigom']],
        ['transaction_lookup', ['receipt', 'transaction', 'reference', 'resibo']],
        ['loan_details', ['loan ln', 'loan number', 'schedule of loan']],
        ['borrower_lookup', ['find member', 'find', 'hanapin', 'pangitaa', 'pangita', 'look up', 'contact']],
        ['borrower_summary', ['new members', 'how many members', 'ilan ang members', 'repeat members', 'bag ong members', 'pila ka members', 'active members', 'miyembro', 'repeat borrowers']],
        ['overdue_accounts', ['overdue', 'past due', 'atraso', 'naatraso', 'nalangan', 'lapas', 'delayed', 'late', 'wala pa nakabayad', 'dili pa bayad', 'hindi pa nakakabayad']],
        ['collection_details', ['list of payments', 'who paid', 'nagbayad', 'mibayad', 'nibayad', 'payments list']],
        ['collection_summary', ['collect', 'collected', 'collection', 'collections', 'koleksyon', 'koleksiyon', 'nakolekta', 'nakubra', 'nadawat', 'bayad']],
        ['disbursement_summary', ['disburse', 'disbursed', 'disbursement', 'disbursements', 'release', 'released', 'nailabas', 'gi release', 'na release', 'gipagawas', 'napagawas', 'nahatag', 'naibigay']],
        ['loan_summary', ['active loans', 'active loan', 'outstanding', 'aktibo', 'nagdagan', 'tumatakbo']],
        ['general_lending_summary', ['summary', 'overview', 'buod', 'kinatibuk an', 'kinatibuk ang']],
    ];

    /** Words that aren't a branch name after "sa" or "in", or before "branch". */
    private const NOT_BRANCH = [
        'mga', 'ating', 'atin', 'amin', 'lahat', 'ngayon', 'ngayong', 'kahapon', 'nakaraang', 'isang', 'loob',
        'the', 'which', 'what', 'anong', 'unsang', 'asa', 'nga', 'each', 'every', 'by', 'per', 'kada', 'best', 'top', 'my', 'our', 'all',
        'niaging', 'miaging', 'karong', 'karon', 'kining', 'niining', 'gahapon', 'kagahapon', 'atong', 'among', 'tanan', 'tanang', 'ilang',
        'koleksyon', 'nakolekta', 'bayad', 'atraso', 'loan', 'loans', 'interest', 'income', 'penalties', 'penalty', 'fees', 'pesos',
        'peso', 'gcash', 'cash', 'total', 'full', 'advance', 'arrears', 'percent',
    ];

    /** @var list<LindaModelRequest> */
    public array $requests = [];

    private bool $refuses = false;

    private ?string $failure = null;

    /** A fake that declines every question, as a model refusal does. */
    public static function refusing(): self
    {
        $fake = new self;
        $fake->refuses = true;

        return $fake;
    }

    /** A fake that fails every question with a reason code (max_tokens and bad_output keep their usage). */
    public static function failingWith(string $reason): self
    {
        $fake = new self;
        $fake->failure = (new LindaModelUnavailable($reason))->reason;

        return $fake;
    }

    public function understand(#[\SensitiveParameter] LindaModelRequest $request): LindaUnderstanding
    {
        $this->requests[] = $request;
        $usage = new LindaUsage($request->model, self::INPUT_TOKENS, self::OUTPUT_TOKENS);

        if ($this->failure !== null) {
            // Like the real model, a call that was answered but couldn't be
            // used carries the usage it was billed.
            throw new LindaModelUnavailable($this->failure, in_array($this->failure, ['max_tokens', 'bad_output'], true) ? $usage : null);
        }

        if ($this->refuses) {
            return LindaUnderstanding::refusal($usage);
        }

        $clean = LindaText::clean($request->question);
        [$periodWords, $rest] = self::periodWords($clean);
        $intent = self::intent($clean);
        $branch = self::branch($rest);
        $followUp = false;

        if ($intent === null) {
            $previous = $request->previousIntent();
            $followUp = $previous !== null && $previous !== LindaIntent::UnsupportedQuestion && ($periodWords !== null || $branch !== null);
            $intent = $followUp ? $previous : LindaIntent::UnsupportedQuestion;
        }

        if ($intent === LindaIntent::UnsupportedQuestion) {
            return LindaUnderstanding::fromAnswer($intent, null, null, false, $usage);
        }

        return LindaUnderstanding::fromAnswer($intent, $periodWords, $branch, $followUp, $usage);
    }

    private static function intent(string $clean): ?LindaIntent
    {
        foreach (self::RULES as [$intent, $keywords]) {
            foreach ($keywords as $keyword) {
                if (preg_match('/(?<![\pL\pN])'.preg_quote($keyword, '/').'(?![\pL\pN])/u', $clean) === 1) {
                    return LindaIntent::from($intent);
                }
            }
        }

        return null;
    }

    /**
     * The words of the period the question names, as written, and the
     * question without them.
     *
     * @return array{?string, string}
     */
    private static function periodWords(string $clean): array
    {
        $found = LindaPeriod::extract($clean);

        if ($found['period'] === null && ! $found['refused']) {
            return [null, $clean];
        }

        $words = LindaText::words($clean);
        $rest = LindaText::words($found['rest']);

        $start = 0;
        while ($start < count($rest) && $words[$start] === $rest[$start]) {
            $start++;
        }

        $end = 0;
        while ($end < count($rest) - $start && $words[count($words) - 1 - $end] === $rest[count($rest) - 1 - $end]) {
            $end++;
        }

        $span = array_slice($words, $start, count($words) - $start - $end);

        return [$span === [] ? null : implode(' ', $span), $found['rest']];
    }

    private static function branch(string $clean): ?string
    {
        if (preg_match('/(?:^|\s)(?:in|at|sa)?\s*(?:the\s+)?([\pL]+(?:\s[\pL]+)?)\s+(?:nga\s+)?branch(?:$|\s)/u', $clean, $found) === 1) {
            $words = array_values(array_diff(explode(' ', $found[1]), self::NOT_BRANCH));

            return $words === [] ? null : implode(' ', $words);
        }

        if (preg_match('/(?:^|\s)(?:sa|in)\s+([\pL]+(?:\s[\pL]+)?)\s*$/u', $clean, $found) === 1 || preg_match('/(?:^|\s)(?:sa|in)\s+([\pL]+)/u', $clean, $found) === 1) {
            return in_array(explode(' ', $found[1])[0], self::NOT_BRANCH, true) ? null : $found[1];
        }

        return null;
    }
}
