<?php

namespace App\Services\Linda\Model;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaText;

/**
 * The `fake` driver, for tests and local runs: it walks the model path with
 * no network.
 *
 * It is deterministic. Keyword rules map a question to an intent, the
 * period it names to period words and a "sa X" or "X branch" phrase to a
 * branch, and a question with no subject after an earlier one follows on
 * from it. Its answers then go through the same LindaUnderstanding as the
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
        ['unsupported_question', ['ignore', 'you are now', 'pretend', 'system prompt', 'instructions', 'weather', 'joke', 'poem', 'delete', 'send']],
        ['collection_efficiency', ['efficiency', 'collection rate']],
        ['income_summary', ['income', 'interest earned', 'earn', 'earned', 'revenue', 'kita', 'kinita']],
        ['expense_summary', ['expense', 'expenses', 'gastos']],
        ['officer_performance', ['officer', 'officers', 'collector', 'collectors']],
        ['branch_performance', ['branches', 'compare', 'ikumpara', 'best branch', 'top branch']],
        ['portfolio_summary', ['portfolio', 'par', 'par 30']],
        ['transaction_lookup', ['receipt', 'transaction', 'reference']],
        ['loan_details', ['loan ln', 'loan number', 'schedule of loan']],
        ['borrower_lookup', ['find member', 'find', 'hanapin', 'look up', 'contact']],
        ['borrower_summary', ['new members', 'how many members', 'ilan ang members', 'repeat members']],
        ['overdue_accounts', ['overdue', 'past due', 'atraso', 'delayed', 'late']],
        ['collection_details', ['list of payments', 'who paid', 'nagbayad', 'payments list']],
        ['collection_summary', ['collect', 'collected', 'collection', 'collections', 'koleksyon', 'nakolekta']],
        ['disbursement_summary', ['disburse', 'disbursed', 'disbursement', 'disbursements', 'release', 'released', 'nailabas']],
        ['loan_summary', ['active loans', 'active loan', 'outstanding']],
        ['general_lending_summary', ['summary', 'overview', 'buod']],
    ];

    /** Words that aren't a branch name after "sa". */
    private const NOT_BRANCH = ['mga', 'ating', 'atin', 'amin', 'lahat', 'ngayon', 'ngayong', 'kahapon', 'nakaraang', 'isang', 'loob'];

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

    /** A fake that fails every question with a reason code. */
    public static function failingWith(string $reason): self
    {
        $fake = new self;
        $fake->failure = (new LindaModelUnavailable($reason))->reason;

        return $fake;
    }

    public function understand(LindaModelRequest $request): LindaUnderstanding
    {
        $this->requests[] = $request;
        $usage = new LindaUsage($request->model, self::INPUT_TOKENS, self::OUTPUT_TOKENS);

        if ($this->failure !== null) {
            throw new LindaModelUnavailable($this->failure);
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
        if (preg_match('/(?:^|\s)(?:in|at|sa)?\s*(?:the\s+)?([\pL]+(?:\s[\pL]+)?)\s+branch(?:$|\s)/u', $clean, $found) === 1) {
            return $found[1];
        }

        if (preg_match('/(?:^|\s)sa\s+([\pL]+(?:\s[\pL]+)?)\s*$/u', $clean, $found) === 1 || preg_match('/(?:^|\s)sa\s+([\pL]+)/u', $clean, $found) === 1) {
            return in_array(explode(' ', $found[1])[0], self::NOT_BRANCH, true) ? null : $found[1];
        }

        return null;
    }
}
