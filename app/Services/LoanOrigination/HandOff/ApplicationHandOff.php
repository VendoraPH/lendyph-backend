<?php

namespace App\Services\LoanOrigination\HandOff;

use App\Http\Requests\Loan\StoreLoanRequest;
use App\Models\Borrower;
use App\Models\CreditApplicationProfile;
use App\Models\Document;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\OriginationApplication;
use App\Models\OriginationAssessment;
use App\Models\OriginationDocument;
use App\Models\OriginationSetting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Branches\BranchWriteGuard;
use App\Services\Branches\MemberLock;
use App\Services\CreditScoring\Exposure;
use App\Services\CreditScoring\MemberCreditData;
use App\Services\LoanListTotals;
use App\Services\LoanOrigination\ApplicationTransitions;
use App\Services\LoanOrigination\Assessment\CapacityAssessor;
use App\Services\LoanOrigination\Assessment\RequiredDocuments;
use App\Services\LoanOrigination\DraftRules;
use App\Services\LoanService;
use App\Services\LoanWriteTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Submit for approval": an online application becomes a loan in Lendy's own
 * approval chain (origination decisions 11 and 12, Phase 6 decisions 9 and
 * 24). #615's separate "Convert" is gone.
 *
 * Who: `loan_origination:convert` and `loans:create`, plus
 * `borrowers:create` when the hand-off creates the member (403 in words).
 *
 * When: one hand-off of an application at a time (a per-application lock;
 * a second answers 409), for an application under assessment, not handed off
 * yet (409 naming its loan otherwise), at the version the client read (409),
 * with every required document verified, each verified ID recording its ID
 * type and number and, when the hand-off makes the member, a verified ID
 * among them (422 in words), and a current assessment that still matches its
 * request and member and still holds for the loan: assessed again at the
 * loan's start date and the product's current rate on its verified figures,
 * it lists the same conflicts and reaches the same result and instalment
 * (422, "Run the assessment again", otherwise).
 *
 * Step one, the member: the linked member, or a new pending registration
 * (ApplicantMember). It is its own write, so when step two fails the member
 * stays linked and a retry resumes from step two.
 *
 * Step two, the loan, as LoanController::store() makes one: under the
 * member's lock (MemberLock, MB-BE4), then their branch's lock
 * (BranchWriteGuard::whileTakingNew()) on the branch read under the first,
 * and in one loan transaction (LoanService::createAndSubmitForHandOff()):
 * - the draft loan from StoreLoanRequest's own rules: the product's terms,
 *   the requested amount, term and frequency, a start date of today (or the
 *   one sent), the product's fees, and the assigned officer;
 * - the application's link to the loan, re-checked under its row lock with
 *   the assessment revision and the required documents;
 * - the verified documents copied to the member's documents, each under its
 *   member document type: the ID as the member's valid ID (MemberDocuments),
 *   unless the assessment found the member's record different from the
 *   application;
 * - the Credit Scoring application profile from the assessed figures;
 * - the application's move to For Approval (actor `handoff`);
 * - then the loan's submit, which seeds the approval chain. An above-capacity
 *   or insufficient-information assessment sends it to the Policy Exception
 *   chain, with the explanation as its details.
 * Copied files are removed again if the transaction fails.
 */
final class ApplicationHandOff
{
    public const MEMBER_REFUSAL = 'Handing this application off creates its member, which needs the permission to create members.';

    public const BUSY = 'This application is being submitted for approval right now. Refresh to see its current status.';

    public const CHANGED = 'The application changed after it was assessed. Run the assessment again.';

    public const NO_VALID_ID = 'Approving the new member\'s registration needs a valid ID. Verify the applicant\'s ID, with its ID type and number, before submitting for approval.';

    /**
     * Longest a hand-off holds its application's lock: a hand-off slowed by
     * lock waits finishes well inside it, and one killed midway frees the
     * application after it at most.
     */
    public const LOCK_SECONDS = 120;

    /** The policy exception details column holds 2,000 characters. */
    private const DETAILS_MAX = 2000;

    /** @var array<string, string>|null the settings' document labels, by type, read once */
    private ?array $labels = null;

    public function __construct(
        private readonly ApplicationTransitions $transitions,
        private readonly ApplicantMember $applicantMember,
        private readonly RequiredDocuments $documents,
        private readonly DraftRules $draftRules,
        private readonly LoanService $loans,
        private readonly BranchWriteGuard $branches,
        private readonly LoanListTotals $totals,
        private readonly CapacityAssessor $assessor,
    ) {}

    /** The cache lock one hand-off of an application holds. */
    public static function lockKey(int $applicationId): string
    {
        return "loan-origination:hand-off:{$applicationId}";
    }

    /**
     * @param  array{version: int, start_date?: ?string, account_officer_id?: ?int, scb_amount?: string|int|float|null, force_new_member?: bool}  $input
     */
    public function handOff(OriginationApplication $application, User $user, array $input): HandOffResult
    {
        $lock = Cache::lock(self::lockKey((int) $application->getKey()), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw LoanWriteTransaction::conflict(self::BUSY);
        }

        try {
            return $this->handOffHoldingLock($application->refresh(), $user, $input);
        } finally {
            $lock->release();
        }
    }

    /** @param  array{version: int, start_date?: ?string, account_officer_id?: ?int, scb_amount?: string|int|float|null, force_new_member?: bool}  $input */
    private function handOffHoldingLock(OriginationApplication $application, User $user, array $input): HandOffResult
    {
        $this->authorize($user, $application->borrower_id === null);
        $this->assertReady($application, (int) $input['version']);
        $assessment = $this->currentAssessment($application);
        $startDate = $input['start_date'] ?? Carbon::now()->toDateString();
        $this->assertStillHolds($application, $assessment, $startDate);
        $this->assertNewMemberHasValidId($application, $assessment);

        $member = $application->borrower_id === null
            ? $this->createMember($application, $user, $assessment, (bool) ($input['force_new_member'] ?? false))
            : Borrower::query()->findOrFail($application->borrower_id);
        $application->refresh();

        $copied = [];

        try {
            // As LoanController::store(): the member's lock first (MB-BE4), so
            // a member transfer can't move them between the branch read and
            // the loan; the branch and the loan's rules read under it.
            $loan = MemberLock::whileHeld($member->id, function () use ($application, $member, $assessment, $input, $user, $startDate, &$copied): Loan {
                $branchId = (int) Borrower::query()->whereKey($member->id)->value('branch_id');
                $validated = $this->loanData($application, $member, $assessment, $input, $user, $startDate);

                return $this->branches->whileTakingNew(
                    $branchId,
                    BranchWriteGuard::LOANS,
                    function () use ($validated, $application, $member, $assessment, $user, &$copied): Loan {
                        return $this->loans->createAndSubmitForHandOff($validated, $user, function (Loan $loan) use ($application, $member, $assessment, $user, &$copied): void {
                            $this->alongside($loan, $application, $member, $assessment, $user, $copied);
                        });
                    },
                );
            });
        } catch (Throwable $e) {
            foreach ($copied as $path) {
                Storage::disk('private')->delete($path);
            }

            throw $e;
        }

        return new HandOffResult($application->refresh(), $loan->refresh());
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, bool $createsMember): void
    {
        if (! collect($this->transitions->permissionsFor('pending_approval'))->every(fn (string $p) => $user->can($p))) {
            throw new AuthorizationException('Moving an application to For Approval needs the permission to hand applications off and the permission to create loans.');
        }

        if ($createsMember && ! $user->can('borrowers:create')) {
            throw new AuthorizationException(self::MEMBER_REFUSAL);
        }
    }

    private function assertReady(OriginationApplication $application, int $version): void
    {
        if ($application->loan_id !== null) {
            throw $this->alreadyHandedOff($application);
        }

        if ($application->version !== $version) {
            throw LoanWriteTransaction::conflict(ApplicationTransitions::STALE);
        }

        if ($application->status !== 'under_assessment') {
            throw ValidationException::withMessages(['status' => 'Only an application under assessment can be submitted for approval.']);
        }
    }

    private function currentAssessment(OriginationApplication $application): OriginationAssessment
    {
        $assessment = OriginationAssessment::currentFor($application);

        if ($assessment === null) {
            throw ValidationException::withMessages(['assessment' => 'Run the assessment before submitting the application for approval.']);
        }

        if (! $this->stillMatches($assessment, $application)) {
            throw ValidationException::withMessages(['assessment' => self::CHANGED]);
        }

        $this->assertDocumentsReady($application);

        return $assessment;
    }

    /**
     * Every required document verified, and each verified ID recording its
     * ID type and number (422 in words). Checked before the member is made
     * and again under the application's lock in the loan transaction.
     */
    private function assertDocumentsReady(OriginationApplication $application): void
    {
        $unverified = $this->documents->unverifiedLabels($application);

        if ($unverified !== []) {
            throw ValidationException::withMessages(['documents' => 'Verify these documents first: '.implode(', ', $unverified).'.']);
        }

        foreach ($this->verifiedDocuments($application) as $document) {
            if (MemberDocuments::isId($document->type) && MemberDocuments::idDetails($document) === null) {
                throw ValidationException::withMessages(['documents' => MemberDocuments::unrecordedIdMessage($this->documentLabel($document->type))]);
            }
        }
    }

    /**
     * The assessment still holds for the loan this hand-off makes: assessed
     * again on its verified figures, at the loan's start date and the
     * product's current rate, it lists the same conflicts and reaches the
     * same result and the same instalment.
     *
     * - The conflicts name every difference between the application and the
     *   member's record, so a different person's pending registration linked
     *   after an assessment that had no member needs a new run (CHANGED).
     * - The busiest month of a weekly, bi-weekly or daily loan moves with its
     *   start date, and a rate change moves every instalment, so either can
     *   take a loan above capacity past the Policy Exception chain.
     */
    private function assertStillHolds(OriginationApplication $application, OriginationAssessment $assessment, string $startDate): void
    {
        $again = $this->assessor->assess(
            $application,
            $assessment->inputs['verified_monthly_income'] ?? null,
            $assessment->inputs['verified_debt_payments'] ?? null,
            $startDate,
        );

        if ($again->conflicts !== $assessment->conflicts) {
            throw ValidationException::withMessages(['assessment' => self::CHANGED]);
        }

        if ($again->result !== $assessment->result || $again->figures['proposed_monthly_installment'] !== ($assessment->figures['proposed_monthly_installment'] ?? null)) {
            throw ValidationException::withMessages(['assessment' => sprintf(
                'The result or the instalment is different for a loan starting on %s at the product\'s current rate. Run the assessment again with that start date.',
                Carbon::parse($startDate)->format('M j, Y'),
            )]);
        }
    }

    /**
     * A member this hand-off makes (the assessment counted none) is a pending
     * registration, which can't be approved, nor its loan released, without
     * a valid ID. So one of the verified documents must be an ID
     * (MemberDocuments::isId()) recording its ID type and number, even when
     * the settings make the ID optional or name it differently (422 in
     * words). Checked before the member is made and again in the loan
     * transaction.
     */
    private function assertNewMemberHasValidId(OriginationApplication $application, OriginationAssessment $assessment): void
    {
        if ($assessment->borrower_id !== null) {
            return;
        }

        $ids = $this->verifiedDocuments($application)
            ->filter(fn (OriginationDocument $document) => MemberDocuments::isId($document->type) && MemberDocuments::idDetails($document) !== null);

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['documents' => self::NO_VALID_ID]);
        }
    }

    /** Whether the assessment found the member's record different from the application in any way. */
    private static function recordDiffers(OriginationAssessment $assessment): bool
    {
        return array_intersect($assessment->conflicts, CapacityAssessor::MEMBER_RECORD_CONFLICTS) !== [];
    }

    /**
     * The assessment still describes the application: the same product,
     * amount and term, and the same member. A member created by an earlier
     * attempt of this hand-off holds no collectible loan, so an assessment
     * made before it existed counted the same Lendy debt (none) and still
     * matches; any other member needs a new run.
     */
    private function stillMatches(OriginationAssessment $assessment, OriginationApplication $application): bool
    {
        $sameRequest = $assessment->loan_product_id === $application->loan_product_id
            && (string) $assessment->requested_amount === (string) $application->requested_amount
            && $assessment->requested_term === $application->requested_term;

        if (! $sameRequest) {
            return false;
        }

        if ($assessment->borrower_id === $application->borrower_id) {
            return true;
        }

        if ($assessment->borrower_id !== null || $application->borrower_id === null) {
            return false;
        }

        $member = Borrower::query()->whereKey($application->borrower_id)->first(['id', 'status']);

        return $member !== null
            && $member->status === 'pending'
            && Exposure::from(MemberCreditData::load((int) $member->id, null, Carbon::now()), $this->totals)->activeLoans === 0;
    }

    private function createMember(OriginationApplication $application, User $user, OriginationAssessment $assessment, bool $force): Borrower
    {
        $member = $this->applicantMember->create($application, $user, $assessment->inputs['verified_monthly_income'] ?? null, $force);

        DB::transaction(function () use ($application, $member, $user): void {
            /** @var OriginationApplication $locked */
            $locked = OriginationApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->borrower_id !== null || $locked->loan_id !== null) {
                throw LoanWriteTransaction::conflict(ApplicationTransitions::STALE);
            }

            $locked->borrower_id = $member->id;
            $locked->save();

            AuditLogService::log(
                'member_linked',
                $locked,
                newValues: ['borrower_id' => $member->id, 'created' => true],
                description: "Pending member #{$member->id} created for online application #{$locked->id}",
                userId: $user->id,
            );
        });

        return $member;
    }

    /**
     * The loan the hand-off creates, put through StoreLoanRequest's rules as
     * the staff member handing it off.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function loanData(OriginationApplication $application, Borrower $member, OriginationAssessment $assessment, array $input, User $user, string $startDate): array
    {
        $draft = $this->draftRules->normalize($application->draft ?? []);
        $product = LoanProduct::query()->findOrFail($application->loan_product_id);
        $details = 'Online application '.($application->reference_number ?? '#'.$application->id).': '.$assessment->explanation;

        $data = [
            'borrower_id' => $member->id,
            'loan_product_id' => $product->id,
            'principal_amount' => (string) $application->requested_amount,
            'term' => $application->requested_term,
            'frequency' => $assessment->frequency,
            'purpose' => trim($draft['loan']['purpose']) === '' ? null : mb_substr(trim($draft['loan']['purpose']), 0, 500),
            'start_date' => $startDate,
            'account_officer_id' => array_key_exists('account_officer_id', $input) ? $input['account_officer_id'] : $application->assigned_officer_id,
            'scb_amount' => $input['scb_amount'] ?? ($product->scb_required ? (string) ($product->min_scb ?? 0) : 0),
            'policy_exception' => $assessment->goesToPolicyException(),
            'policy_exception_details' => $assessment->goesToPolicyException() ? mb_substr($details, 0, self::DETAILS_MAX) : null,
        ];

        $form = new StoreLoanRequest;
        $form->setUserResolver(fn () => $user);

        return Validator::make($data, $form->rules(), $form->messages())->validate();
    }

    /**
     * The application's writes inside the loan transaction.
     *
     * @param  list<string>  $copied  the member document files written, for the clean-up
     */
    private function alongside(Loan $loan, OriginationApplication $application, Borrower $member, OriginationAssessment $assessment, User $user, array &$copied): void
    {
        /** @var OriginationApplication $locked */
        $locked = OriginationApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->loan_id !== null) {
            throw $this->alreadyHandedOff($locked);
        }

        // A locking read sees the latest revision, not the transaction's
        // snapshot, so a run saved after the snapshot is never missed.
        $latestRevision = (int) OriginationAssessment::query()->where('application_id', $locked->id)->orderByDesc('version')->lockForUpdate()->value('id');

        if ($locked->version !== $application->version || $locked->status !== 'under_assessment' || $locked->borrower_id !== $member->id
            || $latestRevision !== (int) $assessment->id) {
            throw LoanWriteTransaction::conflict(ApplicationTransitions::STALE);
        }

        $this->assertDocumentsReady($locked);
        $this->assertNewMemberHasValidId($locked, $assessment);

        $locked->loan_id = $loan->id;
        $locked->save();

        $this->copyDocuments($locked, $member, $assessment, $user, $copied);
        $this->writeProfile($loan, $locked, $assessment, $user);

        $this->transitions->move(
            $locked,
            'pending_approval',
            ApplicationTransitions::ACTOR_HANDOFF,
            $user,
            "Submitted for approval as loan {$loan->application_number}.",
            $locked->version,
        );

        AuditLogService::log(
            'handed_off',
            $locked,
            newValues: ['loan_id' => $loan->id, 'borrower_id' => $member->id, 'policy_exception' => (bool) $loan->policy_exception],
            description: "Online application #{$locked->id} submitted for approval as loan {$loan->application_number}",
            userId: $user->id,
        );
    }

    /**
     * Each verified document, as the member document type it maps to
     * (MemberDocuments): the ID as the member's valid ID, labelled with its
     * ID type key and carrying its number, where ValidIdService keeps valid
     * IDs; any other under its own type, labelled with the application.
     *
     * The ID becomes the member's valid ID only when the assessment found no
     * difference between the application and the member's record; otherwise
     * it is kept under its own type, so one person's ID is never filed as
     * another's.
     *
     * @param  list<string>  $copied
     */
    private function copyDocuments(OriginationApplication $application, Borrower $member, OriginationAssessment $assessment, User $user, array &$copied): void
    {
        $reference = $application->reference_number ?? '#'.$application->id;
        $asValidId = ! self::recordDiffers($assessment);

        foreach ($this->verifiedDocuments($application) as $document) {
            $version = $document->currentVersion;
            $label = $this->documentLabel($document->type);
            $isId = MemberDocuments::isId($document->type);
            $id = $isId ? MemberDocuments::idDetails($document) : null;

            if ($isId && $id === null) {
                throw ValidationException::withMessages(['documents' => MemberDocuments::unrecordedIdMessage($label)]);
            }

            if (! $asValidId) {
                $id = null;
            }

            $type = $id === null ? $document->type : MemberDocuments::memberType($document->type);

            $bytes = $version === null ? null : Storage::disk($version->disk)->get($version->path);

            if ($bytes === null) {
                throw ValidationException::withMessages(['documents' => "The file of {$label} can't be read. Ask the applicant to upload it again."]);
            }

            $extension = pathinfo($version->file_name, PATHINFO_EXTENSION) ?: 'pdf';
            $path = "documents/{$type}/borrower/{$member->id}/".$version->public_id.'.'.strtolower($extension);
            Storage::disk('private')->put($path, $bytes);
            $copied[] = $path;

            $member->documents()->create([
                'type' => $type,
                'label' => $id === null ? "{$label} (online application {$reference})" : $id['type'],
                'custom_type_name' => $id['custom_type_name'] ?? null,
                'id_number' => $id['id_number'] ?? null,
                'file_path' => $path,
                'original_filename' => Document::cleanOriginalFilename($version->file_name),
                'mime_type' => $version->mime_type,
                'file_size' => $version->size_bytes,
                'uploaded_by' => $user->id,
            ]);
        }
    }

    /** @return Collection<int, OriginationDocument> */
    private function verifiedDocuments(OriginationApplication $application): Collection
    {
        return OriginationDocument::query()->where('application_id', $application->id)->where('status', 'verified')
            ->with('currentVersion')->orderBy('id')->get();
    }

    /** A document type's label in the settings, else the type in words. */
    private function documentLabel(string $type): string
    {
        $this->labels ??= collect(OriginationSetting::current()->document_types ?? [])->pluck('label', 'type')->all();

        return (string) ($this->labels[$type] ?? ucfirst(str_replace('_', ' ', $type)));
    }

    /**
     * The application's figures for Credit Scoring (credit_application_profiles):
     * the monthly income the assessment counted, verified or declared, other
     * regular income included, or ₱0.00 when it counted none (Credit Scoring
     * scores this loan on it, never on the member record's income, and reads
     * ₱0.00 as no income; `income_verification` says verified only when it
     * was), household expenses, the debt payments counted outside Lendy and
     * the stability details. Credit Scoring then reads this loan's capacity on
     * the same figures the assessment used.
     */
    private function writeProfile(Loan $loan, OriginationApplication $application, OriginationAssessment $assessment, User $user): void
    {
        $draft = $this->draftRules->normalize($application->draft ?? []);
        $verified = $assessment->inputs['verified_monthly_income'] ?? null;
        $years = trim((string) $draft['personal']['years_at_residence']);
        $loansCount = $draft['expenses']['has_existing_loans'] === true ? count($draft['expenses']['existing_loans']) : 0;

        CreditApplicationProfile::query()->create([
            'loan_id' => $loan->id,
            'monthly_income' => $assessment->figures['eligible_monthly_income'] ?? '0.00',
            'household_expenses' => $assessment->figures['monthly_expenses'],
            'outside_debt_payments' => $assessment->figures['outside_debt_payments'],
            'outside_lenders_count' => $loansCount,
            'employment_status' => match ($draft['employment']['income_source']) {
                'employed' => 'employed',
                'self_employed', 'farmer' => 'self_employed',
                'business_owner' => 'business_owner',
                'pensioner' => 'retired',
                default => 'other',
            },
            'years_at_residence' => is_numeric($years) && (float) $years >= 0 && (float) $years < 1000 ? $years : null,
            'income_verification' => $verified === null ? null : 'verified',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    private function alreadyHandedOff(OriginationApplication $application): HttpResponseException
    {
        $number = Loan::query()->whereKey($application->loan_id)->value('application_number');

        return new HttpResponseException(response()->json([
            'message' => "This application was already handed off as loan {$number}.",
            'loan_id' => $application->loan_id,
        ], 409));
    }
}
