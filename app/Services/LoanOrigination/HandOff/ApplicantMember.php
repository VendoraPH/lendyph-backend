<?php

namespace App\Services\LoanOrigination\HandOff;

use App\Http\Requests\Borrower\StoreBorrowerRequest;
use App\Models\Borrower;
use App\Models\OriginationApplication;
use App\Models\User;
use App\Services\BorrowerDuplicateDetector;
use App\Services\BorrowerRegistration;
use App\Services\BranchAccess;
use App\Services\LoanOrigination\DraftRules;
use App\Support\PhilippineMobile;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;

/**
 * Step one of the hand-off for an applicant who isn't a member yet
 * (origination decision 11): a `pending` membership registration in the
 * application's branch, created the way POST /borrowers creates one. The
 * application's details are put through StoreBorrowerRequest itself (its
 * rules, the round fz required member fields, the duplicate check and the
 * branch rules, as the staff member handing it off) and saved by
 * BorrowerRegistration::createBorrower(), which checks for a duplicate again
 * under the newest member's lock. Membership approval stays as it is: a
 * pending registration gets no membership date or number until approved.
 *
 * An applicant who matches a member already on record (the duplicate
 * detector POST /borrowers uses) is refused first, in words that point to
 * linking that member; the member is named only to staff who can see their
 * branch. Only the staff member handing off may choose "Create anyway"
 * (`force_new_member`, which needs `borrowers:create`): the request is built
 * from the form's own fields, so nothing the applicant sends can skip the
 * check.
 *
 * Its monthly income is the verified income when staff entered it, else the
 * applicant's main declared income line. Nothing about the applicant's
 * spouse, photo or IDs is taken: the documents follow in step two.
 */
final class ApplicantMember
{
    public function __construct(
        private readonly DraftRules $draftRules,
        private readonly BorrowerRegistration $registration,
        private readonly BorrowerDuplicateDetector $duplicates,
        private readonly BranchAccess $branches,
    ) {}

    /** @param  bool  $force  create even when a similar member exists (staff's "Create anyway") */
    public function create(OriginationApplication $application, User $user, ?string $verifiedIncome, bool $force): Borrower
    {
        $attributes = $this->attributes($application, $verifiedIncome, $force);

        if (! $force) {
            $this->refuseKnownMember($attributes, $user);
        }

        $form = StoreBorrowerRequest::create('/api/borrowers', 'POST', $attributes);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->setUserResolver(fn () => $user);
        $form->validateResolved();

        return $this->registration->createBorrower($form);
    }

    /**
     * Refuse, in words, an applicant who matches a member already on record:
     * staff link that member instead, or choose "Create anyway".
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function refuseKnownMember(array $attributes, User $user): void
    {
        $match = $this->duplicates->findDuplicate(
            firstName: (string) ($attributes['first_name'] ?? ''),
            middleName: $attributes['middle_name'] ?? null,
            lastName: (string) ($attributes['last_name'] ?? ''),
            birthdate: $attributes['birthdate'] ?? null,
        );

        if ($match === null) {
            return;
        }

        $who = $this->branches->canSee($user, $match->branch_id === null ? null : (int) $match->branch_id)
            ? "{$match->full_name} ({$match->borrower_code}), already a member"
            : 'a member already on record';

        throw ValidationException::withMessages([
            'member' => "The applicant matches {$who}. Link that member to the application, or create a new member anyway.",
        ]);
    }

    /**
     * The member fields the application holds, in POST /borrowers' shape.
     *
     * @return array<string, mixed>
     */
    public function attributes(OriginationApplication $application, ?string $verifiedIncome, bool $force = false): array
    {
        $draft = $this->draftRules->normalize($application->draft ?? []);
        $personal = $draft['personal'];
        $declared = $this->draftRules->declaredTotals($draft);
        $main = collect($declared['income'])->first(fn (array $line) => $line['key'] !== 'other_regular_income');
        $blank = fn (string $value): ?string => trim($value) === '' ? null : trim($value);
        $employment = $draft['employment'];

        return array_filter([
            'first_name' => $blank($personal['first_name']),
            'middle_name' => $blank($personal['middle_name']),
            'last_name' => $blank($personal['last_name']),
            'suffix' => $blank($personal['suffix']),
            'birthdate' => $blank($personal['date_of_birth']),
            'gender' => $blank(mb_strtolower($personal['gender'])),
            'civil_status' => $blank(mb_strtolower($personal['civil_status'])),
            'street_address' => $blank($personal['street_address']),
            'barangay' => $blank($personal['barangay']),
            'city' => $blank($personal['city']),
            'province' => $blank($personal['province']),
            'contact_number' => $this->localMobile($application->mobile),
            'email' => $blank((string) $application->email),
            'employer_or_business' => $blank($employment['employer_name']) ?? $blank($employment['business_name']),
            'monthly_income' => $verifiedIncome ?? ($main['monthly'] ?? null),
            'branch_id' => $application->branch_id,
            'status' => 'pending',
            'force' => $force ?: null,
        ], fn ($value) => $value !== null);
    }

    /** The mobile as the member form takes it: 09XXXXXXXXX. */
    private function localMobile(?string $mobile): ?string
    {
        $normalised = PhilippineMobile::normalise($mobile);

        return $normalised === null ? null : '0'.substr($normalised, 2);
    }
}
