<?php

namespace App\Services\Amla\Screening;

/**
 * Scores a subject's names against a listing's (task §11: "exact and
 * appropriate approximate name matching … do not conclude that two people are
 * the same based solely on a similar name").
 *
 * - Each pair of names is compared word by word, in any order: a word found
 *   in the other name counts 1; a word of at least FUZZY_MIN_LENGTH letters
 *   that is one letter off (one added, dropped or changed) counts
 *   FUZZY_WEIGHT. The score is the total over the longer name's word count,
 *   as a percentage. The best pair counts.
 * - A known birth year on both sides that differs takes BIRTH_YEAR_PENALTY
 *   off; one that matches is said in the reasons.
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

    /**
     * @param  list<string>  $subjectNames
     * @param  list<string>  $entryNames
     * @param  list<int>  $entryBirthYears
     * @return array{score: int, reasons: list<string>}
     */
    public function score(array $subjectNames, ?int $subjectBirthYear, array $entryNames, array $entryBirthYears): array
    {
        $best = ['score' => 0, 'reasons' => []];

        foreach ($subjectNames as $subjectName) {
            $mine = NameNormaliser::tokens($subjectName);

            foreach ($entryNames as $entryName) {
                $theirs = NameNormaliser::tokens($entryName);

                if ($mine === [] || $theirs === []) {
                    continue;
                }

                $compared = $this->compare($mine, $theirs);

                if ($compared['score'] > $best['score']) {
                    $best = $compared;
                }
            }
        }

        if ($best['score'] === 0) {
            return $best;
        }

        $years = array_values(array_unique(array_map('intval', $entryBirthYears)));

        if ($subjectBirthYear !== null && $years !== []) {
            if (in_array($subjectBirthYear, $years, true)) {
                $best['reasons'][] = "Birth year matches ({$subjectBirthYear})";
            } else {
                $best['score'] = max(0, $best['score'] - self::BIRTH_YEAR_PENALTY);
                $best['reasons'][] = "Birth year differs ({$subjectBirthYear} against ".implode(' or ', $years).')';
            }
        }

        return $best;
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
     * @return array{score: int, reasons: list<string>}
     */
    private function compare(array $mine, array $theirs): array
    {
        $unmatched = array_values(array_diff($theirs, $mine));
        $exact = count(array_intersect($mine, $theirs));
        $total = (float) $exact;
        $reasons = [];

        foreach (array_values(array_diff($mine, $theirs)) as $word) {
            foreach ($unmatched as $index => $candidate) {
                if (strlen($word) >= self::FUZZY_MIN_LENGTH && strlen($candidate) >= self::FUZZY_MIN_LENGTH && levenshtein($word, $candidate) === 1) {
                    $total += self::FUZZY_WEIGHT;
                    $reasons[] = "\"{$word}\" is one letter from \"{$candidate}\"";
                    unset($unmatched[$index]);

                    break;
                }
            }
        }

        $score = (int) round(100 * $total / max(count($mine), count($theirs)));
        $longest = max(count($mine), count($theirs));

        array_unshift($reasons, $score === 100 ? 'The name matches exactly' : "{$exact} of {$longest} name words match exactly");

        return ['score' => $score, 'reasons' => $reasons];
    }
}
