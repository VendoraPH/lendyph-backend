<?php

namespace App\Services\Amla\Screening;

/**
 * Scores a subject's names against a listing's (task §11: "exact and
 * appropriate approximate name matching … do not conclude that two people are
 * the same based solely on a similar name"; AMLA-B5 review).
 *
 * - Names are compared word by word, in any order, after NameNormaliser
 *   (accents, punctuation and joined surname particles). A word found in the
 *   other name counts 1; a word of at least FUZZY_MIN_LENGTH letters that is
 *   one letter off counts FUZZY_WEIGHT.
 * - The score is how much of the SHORTER name is found, so "Isnilon Hapilon"
 *   is found in "Isnilon Totoni Hapilon" (a listing without its middle name).
 *   That counts only when at least MIN_WORDS of its words are found and the
 *   subject's surname is one of them; otherwise the score falls back to the
 *   share of the longer name, so one shared word is never enough.
 * - Birth years: a listed year or range that holds the subject's year is said
 *   in the reasons; one that doesn't takes BIRTH_YEAR_PENALTY off. An exact
 *   name (the same words both ways) is kept at the threshold whatever the
 *   birth year, for a person to look at.
 * - THRESHOLD is the starting point for a match to be stored ("to verify").
 *
 * A match is only ever a potential match for a person to review.
 */
final class Matcher
{
    public const THRESHOLD = 85;

    public const FUZZY_WEIGHT = 0.85;

    public const FUZZY_MIN_LENGTH = 5;

    public const BIRTH_YEAR_PENALTY = 30;

    /** How many words of the shorter name must be found for it to count. */
    public const MIN_WORDS = 2;

    /**
     * @param  list<string>  $subjectNames
     * @param  list<string>  $entryNames
     * @param  list<int>  $entryBirthYears  every listed year (ranges expanded)
     * @param  string|null  $subjectSurname  the subject's surname; the last word of each name when null
     * @return array{score: int, reasons: list<string>, exact: bool}
     */
    public function score(array $subjectNames, ?int $subjectBirthYear, array $entryNames, array $entryBirthYears, ?string $subjectSurname = null): array
    {
        $best = ['score' => 0, 'reasons' => [], 'exact' => false];

        foreach ($subjectNames as $subjectName) {
            $mine = NameNormaliser::tokens($subjectName);
            $words = NameNormaliser::words($subjectName);
            $surname = $subjectSurname !== null ? NameNormaliser::words($subjectSurname) : array_slice($words, -1);

            foreach ($entryNames as $entryName) {
                $theirs = NameNormaliser::tokens($entryName);

                if ($mine === [] || $theirs === []) {
                    continue;
                }

                $compared = $this->compare($mine, $theirs, $surname);

                if ($compared['score'] > $best['score'] || ($compared['score'] === $best['score'] && $compared['exact'] && ! $best['exact'])) {
                    $best = $compared;
                }
            }
        }

        if ($best['score'] === 0) {
            return $best;
        }

        $years = array_values(array_unique(array_map('intval', $entryBirthYears)));
        sort($years);

        if ($subjectBirthYear !== null && $years !== []) {
            $listed = self::yearsInWords($years);

            if (in_array($subjectBirthYear, $years, true)) {
                $best['reasons'][] = count($years) > 1 && str_contains($listed, '–')
                    ? "Birth year matches ({$subjectBirthYear}, listed {$listed})"
                    : "Birth year matches ({$subjectBirthYear})";
            } elseif ($best['exact']) {
                $best['score'] = max(self::THRESHOLD, $best['score'] - self::BIRTH_YEAR_PENALTY);
                $best['reasons'][] = "Birth year differs ({$subjectBirthYear} against {$listed}); kept because the name matches exactly";
            } else {
                $best['score'] = max(0, $best['score'] - self::BIRTH_YEAR_PENALTY);
                $best['reasons'][] = "Birth year differs ({$subjectBirthYear} against {$listed})";
            }
        }

        return $best;
    }

    /**
     * Years in words, consecutive runs as ranges: [1960 … 1965, 1975] reads
     * "1960–1965 or 1975".
     *
     * @param  list<int>  $years  sorted
     */
    public static function yearsInWords(array $years): string
    {
        $parts = [];
        $start = $previous = null;

        foreach ($years as $year) {
            if ($start === null) {
                $start = $previous = $year;

                continue;
            }

            if ($year === $previous + 1) {
                $previous = $year;

                continue;
            }

            $parts[] = $start === $previous ? (string) $start : "{$start}–{$previous}";
            $start = $previous = $year;
        }

        if ($start !== null) {
            $parts[] = $start === $previous ? (string) $start : "{$start}–{$previous}";
        }

        return implode(' or ', $parts);
    }

    /**
     * Words that one letter off on a long word could match, for the index:
     * the word and, when long enough, the word with each letter dropped.
     *
     * @return list<string>
     */
    public static function lookupKeys(string $word): array
    {
        $keys = [$word];

        if (strlen($word) >= self::FUZZY_MIN_LENGTH) {
            for ($i = 0, $length = strlen($word); $i < $length; $i++) {
                $keys[] = substr($word, 0, $i).substr($word, $i + 1);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<string>  $mine
     * @param  list<string>  $theirs
     * @param  list<string>  $surname
     * @return array{score: int, reasons: list<string>, exact: bool}
     */
    private function compare(array $mine, array $theirs, array $surname): array
    {
        $found = [];
        $total = 0.0;
        $reasons = [];
        $unmatched = array_values(array_diff($theirs, $mine));

        foreach ($mine as $word) {
            if (in_array($word, $theirs, true)) {
                $found[$word] = true;
                $total += 1;

                continue;
            }

            foreach ($unmatched as $index => $candidate) {
                if (strlen($word) >= self::FUZZY_MIN_LENGTH && strlen($candidate) >= self::FUZZY_MIN_LENGTH && levenshtein($word, $candidate) === 1) {
                    $found[$word] = true;
                    $total += self::FUZZY_WEIGHT;
                    $reasons[] = "\"{$word}\" is one letter from \"{$candidate}\"";
                    unset($unmatched[$index]);

                    break;
                }
            }
        }

        $exact = $mine === $theirs;
        $shorter = min(count($mine), count($theirs));
        $longer = max(count($mine), count($theirs));
        $surnameFound = $surname === [] || array_filter(
            array_keys($found),
            fn (string $word) => array_filter($surname, fn (string $part) => $word === $part || str_ends_with($word, $part)) !== [],
        ) !== [];

        if (count($found) >= min(self::MIN_WORDS, $shorter) && $shorter >= self::MIN_WORDS && $surnameFound) {
            $score = (int) round(100 * $total / $shorter);
            $summary = $exact ? 'The name matches exactly' : count($found)." of the shorter name's {$shorter} words found";
        } else {
            $score = (int) round(100 * $total / $longer);
            $summary = $exact ? 'The name matches exactly' : count($found)." of {$longer} name words found";
        }

        array_unshift($reasons, $summary);

        return ['score' => min(100, $score), 'reasons' => $reasons, 'exact' => $exact];
    }
}
