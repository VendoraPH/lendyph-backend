<?php

namespace App\Services;

use App\Models\Borrower;

class BorrowerDuplicateDetector
{
    /**
     * Max Levenshtein distance (character edits) considered a "slight spelling difference".
     * A distance ≤ 2 catches typos like "Jaun" vs "Juan" while rejecting clearly different names.
     */
    private const FUZZY_MAX_DISTANCE = 2;

    /**
     * Upper bound on fuzzy candidates scanned per request.
     * SOUNDEX pre-filter narrows the set; this cap keeps worst-case cost bounded.
     */
    private const FUZZY_CANDIDATE_LIMIT = 200;

    /**
     * Find an existing borrower that duplicates the provided name/birthdate.
     *
     * Tier 1 — exact: normalized first + middle + last match → always a duplicate.
     * Tier 2 — fuzzy: Levenshtein ≤ 2 on the joined full name AND birthdate matches.
     *
     * @param  int|null  $ignoreId  Exclude this borrower id (used on update to skip the row being edited).
     */
    public function findDuplicate(
        string $firstName,
        ?string $middleName,
        string $lastName,
        ?string $birthdate,
        ?int $ignoreId = null,
    ): ?Borrower {
        $normalizedFirst = $this->normalize($firstName);
        $normalizedMiddle = $this->normalize($middleName ?? '');
        $normalizedLast = $this->normalize($lastName);

        if ($normalizedFirst === '' || $normalizedLast === '') {
            return null;
        }

        if ($exact = $this->exactMatch($normalizedFirst, $normalizedMiddle, $normalizedLast, $ignoreId)) {
            return $exact;
        }

        return $this->fuzzyMatch($normalizedFirst, $normalizedMiddle, $normalizedLast, $birthdate, $ignoreId);
    }

    private function exactMatch(string $first, string $middle, string $last, ?int $ignoreId): ?Borrower
    {
        return Borrower::query()
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$first])
            ->whereRaw("LOWER(TRIM(COALESCE(middle_name, ''))) = ?", [$middle])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$last])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();
    }

    private function fuzzyMatch(
        string $first,
        string $middle,
        string $last,
        ?string $birthdate,
        ?int $ignoreId,
    ): ?Borrower {
        // Fuzzy matching only rejects when birthdate also matches — without it we can't distinguish
        // different people who happen to have similar names.
        if (! $birthdate) {
            return null;
        }

        $candidates = Borrower::query()
            ->whereNotNull('birthdate')
            ->whereDate('birthdate', $birthdate)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($first, $last) {
                $q->whereRaw('SOUNDEX(last_name) = SOUNDEX(?)', [$last])
                    ->orWhereRaw('SOUNDEX(first_name) = SOUNDEX(?)', [$first]);
            })
            ->limit(self::FUZZY_CANDIDATE_LIMIT)
            ->get();

        $inputFull = trim("{$first} {$middle} {$last}");

        foreach ($candidates as $candidate) {
            $candidateFull = trim(sprintf(
                '%s %s %s',
                $this->normalize($candidate->first_name),
                $this->normalize($candidate->middle_name ?? ''),
                $this->normalize($candidate->last_name),
            ));

            // levenshtein() has a 255-char limit per arg — long names fall back to "not a match".
            if (strlen($candidateFull) > 255 || strlen($inputFull) > 255) {
                continue;
            }

            $distance = levenshtein($candidateFull, $inputFull);

            if ($distance <= self::FUZZY_MAX_DISTANCE) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Normalize a name fragment for comparison:
     * - lowercase (multi-byte safe)
     * - trim whitespace
     * - collapse internal whitespace runs
     */
    private function normalize(string $value): string
    {
        $lowered = mb_strtolower($value);
        $trimmed = trim($lowered);

        return (string) preg_replace('/\s+/u', ' ', $trimmed);
    }
}
