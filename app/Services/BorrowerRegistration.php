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
 * share capital pledge, stamp the membership date and refuse a duplicate
 * the same way.
 */
final class BorrowerRegistration
{
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

        try {
            // In one transaction so the borrower insert and the Borrower::created
            // hook (which creates the ShareCapitalPledge) are atomic — if either
            // fails, neither row is left behind. Tried again when another create
            // takes the same `borrower_code` or the two deadlock; 409 if it keeps
            // happening.
            return GeneratedNumberWrite::run(function () use ($request, $attributes) {
                $this->refuseDuplicateUnderLock($request);

                return Borrower::create($attributes);
            }, GeneratedNumberWrite::MEMBER_CONFLICT);
        } catch (UniqueConstraintViolationException $e) {
            $uuid = $attributes['registration_uuid'] ?? null;

            // Not an idempotency-key collision: some other unique column clashed.
            if (! is_string($uuid) || ! Borrower::where('registration_uuid', $uuid)->exists()) {
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
             * claim to. That keeps this branch INDISTINGUISHABLE from sending a
             * key nobody has ever used — both produce a 201 carrying a brand-new
             * id. An error naming `registration_uuid` would instead have
             * confirmed to an anonymous caller that the key exists, which is an
             * existence oracle over a value whose only defence is being
             * unguessable.
             */
            unset($attributes['registration_uuid']);

            return GeneratedNumberWrite::run(
                fn () => Borrower::create($attributes),
                GeneratedNumberWrite::MEMBER_CONFLICT,
            );
        }
    }

    /**
     * Run the duplicate check again under the lock on the newest member, so two
     * saves of one person that both passed validation can't both create them,
     * whether the second waited on that lock or was tried again after a
     * collision. The lock comes first: a plain read before it would fix a
     * snapshot from before the other save committed.
     *
     * Not for "Create Anyway" (`force`), and not for a registration with a
     * `registration_uuid`: there the unique index decides, and the loser reads
     * the winner's row back rather than being refused.
     */
    private function refuseDuplicateUnderLock(StoreBorrowerRequest $request): void
    {
        if ($request->boolean('force') || $request->filled('registration_uuid')) {
            return;
        }

        Borrower::query()->orderByDesc('id')->lockForUpdate()->first(['id']);

        Validator::make($request->safe()->only(['first_name', 'middle_name', 'last_name', 'birthdate']), [
            'first_name' => [new NoDuplicateBorrower(revealDetails: (bool) $request->user(), viewer: $request->user())],
        ])->validate();
    }
}
