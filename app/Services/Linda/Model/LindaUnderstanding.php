<?php

namespace App\Services\Linda\Model;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaText;

/**
 * What a model understood in a question.
 *
 * The model only returns words. The period's dates are worked out here, on
 * the server, by LindaPeriod in Manila: `period` is set when the words name
 * one, `periodRefused` says why when LindaPeriod refuses it (not started yet,
 * over 10 years, impossible), and `periodUnclear` is set when the words name
 * no period it can read. The branch is the name as written, cleaned, never
 * looked up here.
 */
final class LindaUnderstanding
{
    /**
     * Words a model may leave around a period ("so far", "the month of",
     * "noong", "niadtong sa") that don't change it, in English, Tagalog and
     * Bisaya.
     */
    private const PERIOD_FILLERS = [
        'the', 'of', 'for', 'in', 'on', 'during', 'from', 'so', 'far', 'po', 'month',
        'noong', 'nung', 'ng', 'na', 'ang', 'sa', 'nga', 'niadtong', 'sadtong', 'ba', 'lang', 'ra', 'pa',
    ];

    public function __construct(
        public readonly LindaIntent $intent,
        public readonly ?LindaPeriod $period,
        public readonly ?string $periodWords,
        public readonly bool $periodUnclear,
        public readonly ?string $periodRefused,
        public readonly ?string $branchName,
        public readonly bool $followUp,
        public readonly bool $refused,
        public readonly LindaUsage $usage,
    ) {}

    /**
     * From a model's answer: the intent, the period words, the branch name
     * and the follow-up flag.
     */
    public static function fromAnswer(LindaIntent $intent, ?string $periodWords, ?string $branch, bool $followUp, LindaUsage $usage): self
    {
        $periodWords = $periodWords === null || trim($periodWords) === '' ? null : trim($periodWords);
        [$period, $refusal] = $periodWords === null ? [null, null] : self::readPeriod($periodWords);

        return new self(
            $intent,
            $period,
            $periodWords,
            $periodWords !== null && $period === null && $refusal === null,
            $refusal,
            self::branch($branch),
            $followUp,
            false,
            $usage,
        );
    }

    /** A model that declined to answer: read as an unsupported question. */
    public static function refusal(LindaUsage $usage): self
    {
        return new self(LindaIntent::UnsupportedQuestion, null, null, false, null, null, false, true, $usage);
    }

    /**
     * @return array{?LindaPeriod, ?string} the period, or why it is refused
     */
    private static function readPeriod(string $words): array
    {
        $period = LindaPeriod::parse($words);

        if ($period !== null) {
            return [$period, null];
        }

        $found = LindaPeriod::extract(LindaText::clean($words));
        $rest = array_diff(LindaText::words($found['rest']), self::PERIOD_FILLERS);

        if ($rest !== []) {
            return [null, null];
        }

        return [$found['period'], $found['reason']];
    }

    /** The branch name, cleaned, without the word "branch"; null when empty. */
    private static function branch(?string $branch): ?string
    {
        $words = array_values(array_filter(
            LindaText::words(LindaText::clean((string) $branch)),
            fn (string $word) => $word !== 'branch',
        ));

        return $words === [] ? null : implode(' ', $words);
    }
}
