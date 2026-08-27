<?php

namespace App\Http\Requests\Borrower;

use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Models\Borrower;
use App\Rules\NoDuplicateBorrower;
use App\Services\BorrowerSubmissionTokenService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreBorrowerRequest extends FormRequest
{
    use HasBorrowerRules;

    /**
     * Memoised result of existingRegistration().
     *
     * rules() needs it to relax the uniqueness checks and the controller needs
     * it to decide whether to insert at all; the lookup runs once per request.
     */
    private ?Borrower $existingRegistration = null;

    private bool $existingRegistrationResolved = false;

    public function authorize(): bool
    {
        // Anonymous public-registration callers are allowed through here so
        // the response stays at 422 (validation) instead of 403 (auth) when
        // they submit the wrong status. The `status` rule below enforces the
        // pending-only constraint for unauthenticated requests.
        if (! $this->user()) {
            return true;
        }

        return $this->user()->can('borrowers:create');
    }

    /**
     * The borrower a previous attempt at THIS submission already created.
     *
     * Returns a row only when every one of these holds:
     *  - the caller is anonymous — `registration_uuid` belongs to the public
     *    form, and an operator create must never be short-circuited by one;
     *  - the key is a present, non-empty string;
     *  - the row is still `pending` — never an approved, rejected, inactive or
     *    blacklisted member;
     *  - it was created inside the submission-token window;
     *  - the submission describes the SAME PERSON the key was issued for.
     *
     * That window is a security boundary, not an optimisation.
     * BorrowerController::store() re-issues a submission token for whatever
     * this returns, and a submission token grants write access to that
     * borrower's photo and valid-ID uploads. So a leaked key has to buy exactly
     * what a leaked token buys — fifteen minutes against a record nobody has
     * reviewed yet — and never standing access to an approved member's KYC
     * files. Hence the TTL is read from BorrowerSubmissionTokenService rather
     * than restated: the two must not be able to drift apart.
     */
    public function existingRegistration(): ?Borrower
    {
        if (! $this->existingRegistrationResolved) {
            $this->existingRegistration = $this->findExistingRegistration();
            $this->existingRegistrationResolved = true;
        }

        return $this->existingRegistration;
    }

    /**
     * Runs the lookup again after the unique index rejected our insert.
     *
     * Two retries of the same submission can both miss existingRegistration()
     * and race to the insert; the loser has to be told which id won. It
     * deliberately re-runs the SAME narrowed lookup instead of a bare
     * `where('registration_uuid', …)`: a plain re-read would hand back whatever
     * row holds the key — including an approved member — and store() would then
     * mint an upload token for them. There is one definition of a reusable
     * registration and this path must not get a wider one.
     */
    public function refreshExistingRegistration(): ?Borrower
    {
        $this->existingRegistration = $this->findExistingRegistration();
        $this->existingRegistrationResolved = true;

        return $this->existingRegistration;
    }

    public function rules(): array
    {
        // A retry whose first response was lost collides with the row that very
        // first attempt wrote. Feeding its id to the rules that already accept
        // one is what lets the retry validate instead of being rejected as its
        // own duplicate.
        $ignoreId = $this->existingRegistration()?->id;

        $firstNameRules = ['required', 'string', 'max:255'];

        // Duplicate check is bypassed when the caller explicitly sends force=true
        // (used by the frontend's "Create Anyway" confirmation dialog in PR #105).
        if (! $this->boolean('force')) {
            $firstNameRules[] = new NoDuplicateBorrower(
                ignoreId: $ignoreId,
                revealDetails: (bool) $this->user(),
            );
        }

        // Anonymous public-registration applicants may omit branch_id — the
        // public branch list can be empty or skipped, and an admin assigns the
        // branch during review. Authenticated operators must still pick one.
        $branchRules = $this->user()
            ? ['required', 'exists:branches,id']
            : ['nullable', 'exists:branches,id'];

        $rules = array_merge(
            $this->sharedBorrowerRules($ignoreId),
            [
                'first_name' => $firstNameRules,
                'last_name' => ['required', 'string', 'max:255'],
                'branch_id' => $branchRules,

                // Client-generated idempotency key. Optional: every caller that
                // predates it, and every operator create, sends nothing and is
                // unaffected.
                //
                // Pinned to version 4. A bare `uuid` accepts every version plus
                // the nil UUID `00000000-...`, and a nil or v1 key is either a
                // constant every caller would collide on or one carrying a MAC
                // address and a timestamp. The key's whole value is that it is
                // unguessable, so only the random version is acceptable — which
                // is also exactly what the browser's crypto.randomUUID() emits.
                'registration_uuid' => ['nullable', 'uuid:4'],
            ],
        );

        // Anonymous callers must submit `pending` — they cannot create active
        // or otherwise-operational borrowers.
        if (! $this->user()) {
            $rules['status'] = ['required', Rule::in(['pending'])];
        }

        return $rules;
    }

    public function messages(): array
    {
        return $this->borrowerMessages();
    }

    private function findExistingRegistration(): ?Borrower
    {
        $uuid = $this->input('registration_uuid');

        // is_string() before the query: `registration_uuid` is raw input here
        // (rules() runs before validation), so an array or an int would
        // otherwise reach the query builder.
        if ($this->user() || ! is_string($uuid) || $uuid === '') {
            return null;
        }

        $candidate = Borrower::query()
            ->where('registration_uuid', $uuid)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(BorrowerSubmissionTokenService::TTL_MINUTES))
            ->first();

        return $candidate && $this->describesSamePersonAs($candidate) ? $candidate : null;
    }

    /**
     * Does this submission describe the person the key was issued for?
     *
     * The key on its own is a bearer credential, and honouring it mints a
     * submission token — write access to that borrower's photo and valid-ID
     * uploads. Binding it to the identity means a guessed or leaked key is
     * worthless without the applicant's name and date of birth as well, so it
     * stops being something anybody can simply present.
     *
     * A mismatch returns false rather than failing validation, deliberately: a
     * dedicated "that key is not yours" error would confirm to an anonymous
     * caller that the key exists. Falling through means the request is handled
     * exactly as if the key had never been seen before.
     *
     * First name, last name and birthdate only. Middle name is optional on the
     * public form and frequently re-typed differently between attempts, so
     * including it would reject the genuine retries this whole mechanism exists
     * to rescue.
     */
    private function describesSamePersonAs(Borrower $borrower): bool
    {
        return $this->normalizedName($this->input('first_name')) === $this->normalizedName($borrower->first_name)
            && $this->normalizedName($this->input('last_name')) === $this->normalizedName($borrower->last_name)
            && $this->normalizedDate($this->input('birthdate')) === $borrower->birthdate?->toDateString();
    }

    /**
     * Case, padding and inner-whitespace insensitive, mirroring the comparison
     * BorrowerDuplicateDetector already makes on these same fields. A retry is
     * a human re-typing a form, so " juan " and "Juan" are the same applicant.
     *
     * Non-strings normalise to '' — a name that matches no stored borrower, so
     * a malformed payload fails the binding closed.
     */
    private function normalizedName(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return (string) preg_replace('/\s+/u', ' ', trim(mb_strtolower($value)));
    }

    /**
     * The submitted birthdate as `Y-m-d`, or null when absent or unparseable.
     *
     * This runs from rules(), before the `date` rule has had a chance to reject
     * anything, so it has to survive arbitrary input. Anything it cannot read
     * becomes null, which matches only a borrower whose birthdate is also null
     * — and that request then fails the `date` rule on its own merits anyway.
     */
    private function normalizedDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
