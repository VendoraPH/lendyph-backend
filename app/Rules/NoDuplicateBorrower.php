<?php

namespace App\Rules;

use App\Models\User;
use App\Services\BorrowerDuplicateDetector;
use App\Services\BranchAccess;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a staff borrower create/update if a similar borrower already exists.
 *
 * Attached to the `first_name` field so the error surfaces there in the standard
 * `{errors: {first_name: [...]}}` validation envelope. Staff can pass
 * `force=true` to bypass this rule; on a create it takes `borrowers:create`
 * (StoreBorrowerRequest::forced()).
 *
 * Not for the anonymous public registration (finish run round 2, decision 3).
 * Even a refusal that names nobody let anyone confirm that a given person is on
 * file and probe their date of birth, a probe that left no trace. So a public
 * registration is never refused as a duplicate: BorrowerRegistration saves it
 * pending, linked to the record it may duplicate, for the reviewer.
 *
 * The failure message is two-tiered. Staff who may see the match's branch get
 * the detail they need to resolve the collision; anyone else learns only that
 * a similar member exists, and the default fails closed to that.
 */
class NoDuplicateBorrower implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param  bool  $revealDetails  Whether the caller may see who matched. Defaults
     *                               to false so a new call site fails closed.
     */
    public function __construct(
        private readonly ?int $ignoreId = null,
        private readonly bool $revealDetails = false,
        private readonly ?User $viewer = null,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $firstName = $this->data['first_name'] ?? $value;
        $middleName = $this->data['middle_name'] ?? null;
        $lastName = $this->data['last_name'] ?? null;
        $birthdate = $this->data['birthdate'] ?? null;

        // The check compares text. A name or birthdate sent as anything else (a
        // list, a number) is left to that field's own rules, which answer 422
        // (a birthdate is written year-month-day); read here, a list answered
        // 500.
        if (! is_string($firstName)
            || ! is_string($lastName)
            || ! (is_string($middleName) || $middleName === null)
            || ! (is_string($birthdate) || $birthdate === null)) {
            return;
        }

        if ($firstName === '' || $lastName === '') {
            return;
        }

        $duplicate = app(BorrowerDuplicateDetector::class)->findDuplicate(
            firstName: $firstName,
            middleName: $middleName,
            lastName: $lastName,
            birthdate: $birthdate ? (string) $birthdate : null,
            ignoreId: $this->ignoreId,
        );

        if (! $duplicate) {
            return;
        }

        // The match's name, code and birthdate only go to a caller who may see
        // that member's branch (BranchAccess). Anyone else learns only that a
        // similar member exists, as an anonymous applicant does.
        $mayReveal = $this->revealDetails
            && $this->viewer !== null
            && app(BranchAccess::class)->canSee($this->viewer, $duplicate->branch_id);

        if (! $mayReveal) {
            $fail('A similar member already exists. Please contact your branch to continue.');

            return;
        }

        $dob = $duplicate->birthdate?->toDateString() ?? 'unknown DOB';
        $fail(sprintf(
            'A similar member already exists: %s (%s, born %s). Pass force=true to create anyway.',
            $duplicate->full_name,
            $duplicate->borrower_code,
            $dob,
        ));
    }
}
