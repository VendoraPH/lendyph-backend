<?php

namespace App\Services\CsvImport;

use App\Models\Borrower;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether an incoming customer row is somebody the coop already has.
 *
 * Resolution order, and it is an order rather than a set of tests:
 *
 *   1. The account number is already on a borrower -> already_imported. This
 *      first, so re-running the importer is a no-op instead of a duplication.
 *   2. Identity match on normalised FIRST + LAST + BIRTHDATE.
 *   3. Exactly one match with no account number -> backfill it.
 *      Exactly one match with a different number -> account_no_conflict.
 *      More than one -> ambiguous_match.
 *
 * The identity triple is the same one
 * StoreBorrowerRequest::describesSamePersonAs() uses, and it is deliberately
 * NOT App\Services\BorrowerDuplicateDetector:
 *
 *   - That class's tier 1 matches first + middle + last and ignores birthdate
 *     entirely. On Philippine name triples that is a false-positive machine,
 *     and a false positive here does not warn anybody — it merges two members'
 *     loan books.
 *   - Its tier 2 is a SOUNDEX pre-filter plus a Levenshtein pass, which cannot
 *     use an index and is a guess. Guessing is appropriate when a human is
 *     about to be shown a "did you mean" prompt. It is not appropriate when the
 *     answer silently decides whose account a loan is filed under.
 *
 * DEPENDENCY: `borrowers.external_account_no` is added by Bruce's migration. It
 * should be nullable, unique, and indexed — step 1 is the hot path.
 */
class BorrowerMatcher
{
    /**
     * @param  string|null  $birthdate  `Y-m-d`
     */
    public function match(string $accountNo, string $firstName, string $lastName, ?string $birthdate): BorrowerMatch
    {
        $alreadyImported = Borrower::query()
            ->where('external_account_no', $accountNo)
            ->first();

        if ($alreadyImported !== null) {
            return BorrowerMatch::alreadyImported($alreadyImported);
        }

        $candidates = $this->identityCandidates($firstName, $lastName, $birthdate);

        if ($candidates === []) {
            return BorrowerMatch::new();
        }

        if (count($candidates) > 1) {
            return BorrowerMatch::ambiguous(
                array_map(static fn (Borrower $b): int => $b->id, $candidates),
                count($candidates).' existing borrowers share this name and birthdate, so the account number could not be assigned automatically.',
            );
        }

        $borrower = $candidates[0];

        // A name match with no birthdate on either side is not an identity, it
        // is a coincidence waiting to happen — and the coop it is about to
        // happen to already has 44 members with heavily repeated surnames.
        // Neither creating nor merging is safe, so a human decides.
        if ($birthdate === null) {
            return BorrowerMatch::ambiguous(
                [$borrower->id],
                "Borrower {$borrower->borrower_code} has the same name, but this row has no birthdate, so there is no way to confirm they are the same person.",
            );
        }

        if ($borrower->external_account_no === null) {
            return $this->backfillAccountNumber($borrower, $accountNo);
        }

        if ($borrower->external_account_no === $accountNo) {
            return BorrowerMatch::alreadyImported($borrower);
        }

        return BorrowerMatch::accountNoConflict($borrower, $accountNo);
    }

    /**
     * Borrowers whose normalised first + last + birthdate equal the row's.
     *
     * The birthdate and a case-folded, trimmed surname narrow the set in SQL;
     * the authoritative comparison is then made in PHP with the SAME
     * normalisation StoreBorrowerRequest uses, which also collapses runs of
     * inner whitespace ("Dela  Cruz" and "Dela Cruz" are one person). That
     * cannot be expressed in portable SQL, so it is not attempted there.
     *
     * The LOWER(TRIM(...)) on the left forfeits the `last_name` index. That is
     * an accepted trade at cooperative scale — tens to low thousands of
     * borrowers — and it is the correct trade: a missed match here creates a
     * duplicate member and splits their loan history in two.
     *
     * @return list<Borrower>
     */
    private function identityCandidates(string $firstName, string $lastName, ?string $birthdate): array
    {
        $normalizedFirst = $this->normalizeName($firstName);
        $normalizedLast = $this->normalizeName($lastName);

        if ($normalizedFirst === '' || $normalizedLast === '') {
            return [];
        }

        return Borrower::query()
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$normalizedLast])
            ->when(
                $birthdate !== null,
                fn ($query) => $query->whereDate('birthdate', $birthdate),
                fn ($query) => $query->whereNull('birthdate'),
            )
            ->get()
            ->filter(fn (Borrower $borrower): bool => $this->normalizeName($borrower->first_name) === $normalizedFirst
                && $this->normalizeName($borrower->last_name) === $normalizedLast)
            ->values()
            ->all();
    }

    /**
     * Write the account number onto a matched borrower and touch nothing else.
     *
     * A guarded UPDATE rather than a model save, which is the house idempotency
     * pattern from BackfillRegistrationApprovals, and it is load-bearing three
     * times over:
     *
     *   - `whereNull('external_account_no')` is re-asserted in the WHERE, so
     *     two concurrent runs cannot both believe they claimed this borrower.
     *     The read that found them is not a lock.
     *   - No `updated_at`. That column is read as "last applicant activity" by
     *     registrations:prune; moving it would reset the abandonment clock on
     *     every member this import touches.
     *   - No model save. Borrower is Auditable, so a save would copy the whole
     *     original record — name, birthdate, address, contact number, income —
     *     into audit_logs.old_values permanently, for a field the member did
     *     not change.
     */
    private function backfillAccountNumber(Borrower $borrower, string $accountNo): BorrowerMatch
    {
        $claimed = DB::table('borrowers')
            ->where('id', $borrower->id)
            ->whereNull('external_account_no')
            ->update(['external_account_no' => $accountNo]);

        if ($claimed === 0) {
            // Somebody claimed them between our read and our write. Re-read and
            // report what is actually true now rather than what we assumed.
            $fresh = $borrower->fresh();

            if ($fresh === null) {
                return BorrowerMatch::ambiguous([$borrower->id], 'The matched borrower was removed while the import was running.');
            }

            return $fresh->external_account_no === $accountNo
                ? BorrowerMatch::alreadyImported($fresh)
                : BorrowerMatch::accountNoConflict($fresh, $accountNo);
        }

        $borrower->external_account_no = $accountNo;
        $borrower->syncOriginal();

        return BorrowerMatch::backfilled($borrower);
    }

    /**
     * Case, padding and inner-whitespace insensitive — the same normalisation
     * StoreBorrowerRequest applies, so the two agree on who is who.
     */
    private function normalizeName(?string $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return (string) preg_replace('/\s+/u', ' ', trim(mb_strtolower($value)));
    }
}
