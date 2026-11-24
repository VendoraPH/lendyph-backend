<?php

namespace App\Services;

use App\Models\Borrower;
use Illuminate\Database\Eloquent\Builder;

class BorrowerDuplicateDetector
{
    /** Tier 1 matched: the same first, middle and last name. */
    public const SAME_NAME = 'same_name';

    /** Tier 2 matched: a name a slight spelling apart, and the same birthdate. */
    public const SIMILAR_NAME_SAME_BIRTHDATE = 'similar_name_same_birthdate';

    /** A public registration's email matched, case and spaces ignored. */
    public const SAME_EMAIL = 'same_email';

    private const REASON_WORDS = [
        self::SAME_NAME => 'Same full name',
        self::SIMILAR_NAME_SAME_BIRTHDATE => 'Similar name and the same birthdate',
        self::SAME_EMAIL => 'Same email',
    ];

    /** A stored email as it's compared: lower case, with no spaces. */
    private const EMAIL_AS_COMPARED = "LOWER(REPLACE(email, ' ', '')) = ?";

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

        if ($exact = $this->exactMatches($normalizedFirst, $normalizedMiddle, $normalizedLast, $ignoreId)->first()) {
            return $exact;
        }

        return $this->fuzzyMatch($normalizedFirst, $normalizedMiddle, $normalizedLast, $birthdate, $ignoreId);
    }

    /**
     * The public registration's check (finish run round 2, decision 3): the
     * same two tiers as findDuplicate(), then a third, the same email as any
     * member or registration, with the tier that matched first, so the
     * registration can be saved linked to the match instead of refused.
     *
     * Every tier always runs, even once one has matched, so a registration
     * that matches costs the same queries as one that doesn't and its answer
     * can't be told apart by its timing.
     *
     * @return array{borrower: Borrower, reason: string}|null
     */
    public function findPossibleDuplicate(string $firstName, ?string $middleName, string $lastName, ?string $birthdate, ?string $email = null): ?array
    {
        $normalizedFirst = $this->normalize($firstName);
        $normalizedMiddle = $this->normalize($middleName ?? '');
        $normalizedLast = $this->normalize($lastName);

        if ($normalizedFirst === '' || $normalizedLast === '') {
            return null;
        }

        // With no SQL limit, so a check reads all its rows whether or not one
        // matches: one that stopped at its first match would answer sooner
        // when the person is on file.
        $exact = $this->exactMatches($normalizedFirst, $normalizedMiddle, $normalizedLast, null)->orderBy('id')->get()->first();
        $similar = $this->fuzzyMatch($normalizedFirst, $normalizedMiddle, $normalizedLast, $birthdate, null);
        $sameEmail = $this->emailMatch($email);

        return match (true) {
            $exact !== null => ['borrower' => $exact, 'reason' => self::SAME_NAME],
            $similar !== null => ['borrower' => $similar, 'reason' => self::SIMILAR_NAME_SAME_BIRTHDATE],
            $sameEmail !== null => ['borrower' => $sameEmail, 'reason' => self::SAME_EMAIL],
            default => null,
        };
    }

    /**
     * The member, other than $ignoreId, who holds $email, case and spaces
     * ignored: a registration is approved only once its email is no member's
     * (finish run round 2, decision 3), as a staff create refuses a taken one.
     * Registrations (pending or rejected) don't count.
     */
    public function memberWithEmail(?string $email, int $ignoreId): ?Borrower
    {
        $normalized = $this->normalizeEmail($email);

        if ($normalized === '') {
            return null;
        }

        return Borrower::query()
            ->members()
            ->whereRaw(self::EMAIL_AS_COMPARED, [$normalized])
            ->whereKeyNot($ignoreId)
            ->orderBy('id')
            ->first();
    }

    /**
     * A stored match reason in words ("Same full name"); an unknown one reads
     * as a similar name.
     */
    public static function reasonInWords(?string $reason): string
    {
        return self::REASON_WORDS[$reason] ?? 'Similar name';
    }

    /**
     * The oldest member or registration holding $email, case and spaces
     * ignored, for the public check: read with no SQL limit, so it takes as
     * long whether or not one matches.
     */
    private function emailMatch(?string $email): ?Borrower
    {
        $normalized = $this->normalizeEmail($email);

        if ($normalized === '') {
            return null;
        }

        return Borrower::query()->whereRaw(self::EMAIL_AS_COMPARED, [$normalized])->orderBy('id')->get()->first();
    }

    /** An email as it's compared: lower case, with no spaces. */
    private function normalizeEmail(?string $email): string
    {
        return $email === null ? '' : (string) preg_replace('/\s+/u', '', mb_strtolower($email));
    }

    /**
     * Borrowers with the same first, middle and last name.
     *
     * @return Builder<Borrower>
     */
    private function exactMatches(string $first, string $middle, string $last, ?int $ignoreId): Builder
    {
        return Borrower::query()
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$first])
            ->whereRaw("LOWER(TRIM(COALESCE(middle_name, ''))) = ?", [$middle])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$last])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId));
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
