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
use App\Services\CreditScoring\Exposure;
use App\Services\CreditScoring\MemberCreditData;
use App\Services\LoanListTotals;
use App\Services\LoanOrigination\ApplicationTransitions;
use App\Services\LoanOrigination\Assessment\RequiredDocuments;
use App\Services\LoanOrigination\DraftRules;
use App\Services\LoanService;
use App\Services\LoanWriteTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
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
 * When: an application under assessment, not handed off yet (409 naming its
 * loan otherwise), at the version the client read (409), with a current
 * assessment that still matches its request and member, and every required
 * document verified (422 in words).
 *
 * Step one, the member: the linked member, or a new pending registration
 * (ApplicantMember). It is its own write, so when step two fails the member
 * stays linked and a retry resumes from step two.
 *
 * Step two, the loan, under the member's branch lock
 * (BranchWriteGuard::whileTakingNew(), as LoanController::store()) and in one
 * loan transaction (LoanService::createAndSubmitForHandOff()):
 * - the draft loan from StoreLoanRequest's own rules: the product's terms,
 *   the requested amount, term and frequency, a start date of today (or the
 *   one sent), the product's fees, and the assigned officer;
 * - the application's link to the loan, re-checked under its row lock;
 * - the verified documents copied to the member's documents;
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

    /** The policy exception details column holds 2,000 characters. */
    private const DETAILS_MAX = 2000;

    public function __construct(
        private readonly ApplicationTransitions $transitions,
        private readonly ApplicantMember $applicantMember,
        private readonly RequiredDocuments $documents,
        private readonly DraftRules $draftRules,
        private readonly LoanService $loans,
        private readonly BranchWriteGuard $branches,
        private readonly LoanListTotals $totals,
    ) {}

    /**
     * @param  array{version: int, start_date?: ?string, account_officer_id?: ?int, scb_amount?: string|int|float|null, force_new_member?: bool}  $input
     */
    public function handOff(OriginationApplication $application, User $user, array $input): HandOffResult
    {
        $this->authorize($user, $application->borrower_id === null);
        $this->assertReady($application, (int) $input['version']);
        $assessment = $this->currentAssessment($application);

        $member = $application->borrower_id === null
            ? $this->createMember($application, $user, $assessment, (bool) ($input['force_new_member'] ?? false))
            : Borrower::query()->findOrFail($application->borrower_id);
        $application->refresh();

        $validated = $this->loanData($application, $member, $assessment, $input, $user);
        $copied = [];

        try {
            $loan = $this->branches->whileTakingNew(
                (int) $member->branch_id,
                BranchWriteGuard::LOANS,
                function () use ($validated, $application, $member, $assessment, $user, &$copied): Loan {
                    return $this->loans->createAndSubmitForHandOff($validated, $user, function (Loan $loan) use ($application, $member, $assessment, $user, &$copied): void {
                        $this->alongside($loan, $application, $member, $assessment, $user, $copied);
                    });
                },
            );
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
            throw ValidationException::withMessages(['assessment' => 'The application changed after it was assessed. Run the assessment again.']);
        }

        $unverified = $this->documents->unverifiedLabels($application);

        if ($unverified !== []) {
            throw ValidationException::withMessages(['documents' => 'Verify these documents first: '.implode(', ', $unverified).'.']);
        }

        return $assessment;
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
    private function loanData(OriginationApplication $application, Borrower $member, OriginationAssessment $assessment, array $input, User $user): array
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
            'start_date' => $input['start_date'] ?? Carbon::now()->toDateString(),
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

        if ($locked->version !== $application->version || $locked->status !== 'under_assessment' || $locked->borrower_id !== $member->id) {
            throw LoanWriteTransaction::conflict(ApplicationTransitions::STALE);
        }

        $locked->loan_id = $loan->id;
        $locked->save();

        $this->copyDocuments($locked, $member, $user, $copied);
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

    /** @param  list<string>  $copied */
    private function copyDocuments(OriginationApplication $application, Borrower $member, User $user, array &$copied): void
    {
        $labels = collect(OriginationSetting::current()->document_types ?? [])->pluck('label', 'type');
        $reference = $application->reference_number ?? '#'.$application->id;
        $verified = OriginationDocument::query()->where('application_id', $application->id)->where('status', 'verified')
            ->with('currentVersion')->orderBy('id')->get();

        foreach ($verified as $document) {
            $version = $document->currentVersion;
            $label = (string) ($labels[$document->type] ?? ucfirst(str_replace('_', ' ', $document->type)));
            $bytes = $version === null ? null : Storage::disk($version->disk)->get($version->path);

            if ($bytes === null) {
                throw ValidationException::withMessages(['documents' => "The file of {$label} can't be read. Ask the applicant to upload it again."]);
            }

            $extension = pathinfo($version->file_name, PATHINFO_EXTENSION) ?: 'pdf';
            $path = "documents/{$document->type}/borrower/{$member->id}/".$version->public_id.'.'.strtolower($extension);
            Storage::disk('private')->put($path, $bytes);
            $copied[] = $path;

            $member->documents()->create([
                'type' => $document->type,
                'label' => "{$label} (online application {$reference})",
                'file_path' => $path,
                'original_filename' => Document::cleanOriginalFilename($version->file_name),
                'mime_type' => $version->mime_type,
                'file_size' => $version->size_bytes,
                'uploaded_by' => $user->id,
            ]);
        }
    }

    /**
     * The application's figures for Credit Scoring (credit_application_profiles):
     * household expenses, the debt payments counted outside Lendy, other
     * regular income (when the income wasn't verified as a whole) and the
     * stability details. Credit Scoring then reads this loan's capacity on
     * the same figures the assessment used.
     */
    private function writeProfile(Loan $loan, OriginationApplication $application, OriginationAssessment $assessment, User $user): void
    {
        $draft = $this->draftRules->normalize($application->draft ?? []);
        $declared = $this->draftRules->declaredTotals($draft);
        $verified = $assessment->inputs['verified_monthly_income'] ?? null;
        $other = collect($declared['income'])->firstWhere('key', 'other_regular_income');
        $years = trim((string) $draft['personal']['years_at_residence']);
        $loansCount = $draft['expenses']['has_existing_loans'] === true ? count($draft['expenses']['existing_loans']) : 0;

        CreditApplicationProfile::query()->create([
            'loan_id' => $loan->id,
            'other_income' => $verified === null ? ($other['monthly'] ?? null) : null,
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
