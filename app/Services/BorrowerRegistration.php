<?php

namespace App\Services;

use App\Http\Requests\Borrower\StoreBorrowerRequest;
use App\Models\Borrower;
use App\Rules\NoDuplicateBorrower;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

/**
 * The one way a member is created from a validated StoreBorrowerRequest:
 * POST /borrowers (BorrowerController::store()) and the online application
 * hand-off (LoanOrigination\HandOff\ApplicantMember, slice LO-B4), which
 * builds the same request with the same rules. Extracted from
 * BorrowerController unchanged, so both paths number the member, create the
 * share capital pledge, stamp the membership date and check for a duplicate
 * the same way.
 *
 * A staff create refuses a duplicate. A public registration never does
 * (finish run round 2, decision 3): it is saved pending, linked to the record
 * it may duplicate, for the reviewer.
 */
final class BorrowerRegistration
{
    public function __construct(private readonly BorrowerDuplicateDetector $duplicates) {}

    /**
     * Inserts the borrower, settling a retry race against the unique index.
     *
     * Two retries of one submission can both miss existingRegistration() and
     * reach the insert. `borrowers.registration_uuid` is unique, so the index —
     * not the read — is what decides which one wins; the loser reads the
     * winner's row back rather than failing.
     */
    public function createBorrower(StoreBorrowerRequest $request): Borrower
    {
        $attributes = $request->safe()->except('force');

        // Staff creating a member makes them a member today (CDA design §1):
        // the membership date is today in Manila, and Borrower::booted() gives
        // the number. A public registration is an application, not yet a
        // membership; approving it sets both.
        if ($request->user() !== null && ! in_array($attributes['status'] ?? 'active', Borrower::NON_MEMBER_STATUSES, true)) {
            $attributes['membership_date'] = now()->toDateString();
        }

        $key = $attributes['registration_uuid'] ?? null;

        try {
            // In one transaction so the borrower insert and the Borrower::created
            // hook (which creates the ShareCapitalPledge) are atomic — if either
            // fails, neither row is left behind. Tried again when another create
            // takes the same `borrower_code` or the two deadlock; 409 if it keeps
            // happening.
            return GeneratedNumberWrite::run(
                fn (): Borrower => $this->insertAfterDuplicateCheck($request, $attributes, $key),
                GeneratedNumberWrite::MEMBER_CONFLICT,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Not an idempotency-key collision: some other unique column clashed.
            if (! is_string($key) || ! Borrower::where('registration_uuid', $key)->exists()) {
                throw $e;
            }

            $existing = $request->refreshExistingRegistration();

            if ($existing) {
                return $existing;
            }

            /*
             * The key is held by a row this caller may not be given: an approved
             * member, a rejected applicant, a submission older than the token
             * window, or somebody else entirely. Returning it would turn a
             * leaked key into a live upload token against another applicant's
             * KYC files — the very thing the window and the identity binding in
             * existingRegistration() exist to prevent.
             *
             * So the key is discarded and the submission is handled as what it
             * is: a new registration that happened to quote a key it has no
             * claim to, checked again under the lock as one with no key. That
             * keeps this branch INDISTINGUISHABLE from sending a key nobody has
             * ever used — both produce a 201 carrying a brand-new id, linked to
             * the record it may duplicate when the person is already on file.
             * An error naming `registration_uuid` would instead have confirmed to
             * an anonymous caller that the key exists, which is an existence
             * oracle over a value whose only defence is being unguessable.
             */
            unset($attributes['registration_uuid']);

            return GeneratedNumberWrite::run(
                fn (): Borrower => $this->insertAfterDuplicateCheck($request, $attributes, null),
                GeneratedNumberWrite::MEMBER_CONFLICT,
            );
        }
    }

    /**
     * Saves the borrower once the duplicate check under the lock has passed: a
     * staff create it refuses, a public registration it links.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertAfterDuplicateCheck(StoreBorrowerRequest $request, array $attributes, ?string $key): Borrower
    {
        $possibleDuplicate = $this->checkDuplicateUnderLock($request, $key);

        $borrower = new Borrower($attributes);

        // Server-set, never mass-assigned. The match rides along as the
        // relation, so the audit row names its code without another query.
        if ($possibleDuplicate !== null) {
            $borrower->possible_duplicate_of_id = $possibleDuplicate['borrower']->id;
            $borrower->possible_duplicate_reason = $possibleDuplicate['reason'];
            $borrower->setRelation('possibleDuplicateOf', $possibleDuplicate['borrower']);
        }

        $borrower->save();

        return $borrower;
    }

    /**
     * Run the duplicate check again under the lock on the newest member, so two
     * saves of one person that both passed validation can't both create them,
     * whether the second waited on that lock or was tried again after a
     * collision. The lock comes first: a plain read before it would fix a
     * snapshot from before the other save committed.
     *
     * A staff create is refused, as at validation. A public registration is
     * never refused (finish run round 2, decision 3): refusing it let anyone
     * confirm that a person, or an email, is on file and probe their
     * birthdate. It is saved with the record it may duplicate instead (by
     * name, or else by email), which this returns. It is the only duplicate
     * check a public registration gets, and it runs every tier whether or not
     * one matches, so the answer is the same either way.
     *
     * Not for staff's "Create Anyway" (StoreBorrowerRequest::forced(); an
     * anonymous `force` is ignored). That is settled before the lock is taken,
     * so its permission lookup never runs under it.
     *
     * A `registration_uuid` ($key) steps aside only when a row already holds
     * it: then the unique index decides, and createBorrower() reads a retry's
     * own row back, or drops a key the caller may not use and comes back here
     * with none. Any other key is checked like no key. The public form always
     * sends one, so two overlapping submissions of one new person, each with
     * its own key, would otherwise both be saved unlinked.
     *
     * @return array{borrower: Borrower, reason: string}|null the possible duplicate of a public registration
     */
    private function checkDuplicateUnderLock(StoreBorrowerRequest $request, ?string $key): ?array
    {
        if ($request->forced()) {
            return null;
        }

        self::lockForDuplicateCheck();

        if ($key !== null && Borrower::where('registration_uuid', $key)->exists()) {
            return null;
        }

        $identity = $request->safe()->only(['first_name', 'middle_name', 'last_name', 'birthdate']);

        if ($request->user() === null) {
            return $this->duplicates->findPossibleDuplicate(
                firstName: (string) $identity['first_name'],
                middleName: $identity['middle_name'] ?? null,
                lastName: (string) $identity['last_name'],
                birthdate: $identity['birthdate'] ?? null,
                email: $request->validated('email'),
            );
        }

        Validator::make($identity, [
            'first_name' => [new NoDuplicateBorrower(revealDetails: true, viewer: $request->user())],
        ])->validate();

        return null;
    }

    /**
     * The lock a member's duplicate check runs under: the newest member's row,
     * FOR UPDATE. Two writes that take it check one after the other, each once
     * the other has committed. checkDuplicateUnderLock() takes it for a staff
     * create, a public registration and the online hand-off's member, and
     * approving a registration takes it before its email check
     * (RegistrationApproval), so that check waits for a member still being
     * created and sees them.
     *
     * A locking read: it comes before the transaction's first plain read, or
     * that read would fix a snapshot from before the other write committed.
     */
    public static function lockForDuplicateCheck(): void
    {
        Borrower::query()->orderByDesc('id')->lockForUpdate()->first(['id']);
    }
}
