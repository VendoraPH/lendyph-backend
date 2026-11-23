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

        $key = $attributes['registration_uuid'] ?? null;

        try {
            // In one transaction so the borrower insert and the Borrower::created
            // hook (which creates the ShareCapitalPledge) are atomic — if either
            // fails, neither row is left behind. Tried again when another create
            // takes the same `borrower_code` or the two deadlock; 409 if it keeps
            // happening.
            return GeneratedNumberWrite::run(function () use ($request, $attributes, $key) {
                $this->refuseDuplicateUnderLock($request, $key);

                return Borrower::create($attributes);
            }, GeneratedNumberWrite::MEMBER_CONFLICT);
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
             * ever used — both produce a 201 carrying a brand-new id, or the
             * same refusal when the person is already registered. An error
             * naming `registration_uuid` would instead have confirmed to an
             * anonymous caller that the key exists, which is an existence
             * oracle over a value whose only defence is being unguessable.
             */
            unset($attributes['registration_uuid']);

            return GeneratedNumberWrite::run(function () use ($request, $attributes) {
                $this->refuseDuplicateUnderLock($request, null);

                return Borrower::create($attributes);
            }, GeneratedNumberWrite::MEMBER_CONFLICT);
        }
    }

    /**
     * Run the duplicate check again under the lock on the newest member, so two
     * saves of one person that both passed validation can't both create them,
     * whether the second waited on that lock or was tried again after a
     * collision. The lock comes first: a plain read before it would fix a
     * snapshot from before the other save committed.
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
     * its own key, would otherwise both be saved.
     */
    private function refuseDuplicateUnderLock(StoreBorrowerRequest $request, ?string $key): void
    {
        if ($request->forced()) {
            return;
        }

        Borrower::query()->orderByDesc('id')->lockForUpdate()->first(['id']);

        if ($key !== null && Borrower::where('registration_uuid', $key)->exists()) {
            return;
        }

        Validator::make($request->safe()->only(['first_name', 'middle_name', 'last_name', 'birthdate']), [
            'first_name' => [new NoDuplicateBorrower(revealDetails: (bool) $request->user(), viewer: $request->user())],
        ])->validate();
    }
}
