<?php

namespace App\Services\CreditScoring\Api;

use App\Models\Borrower;
use App\Models\CreditApplicationProfile;
use App\Models\CreditDecision;
use App\Models\CreditPolicyFlag;
use App\Models\CreditScore;
use App\Models\CreditScoringSetting;
use App\Models\Loan;
use App\Models\User;
use App\Services\CreditScoring\Recalculator;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * A loan application's credit assessment: its application details, its
 * latest assessment score with the capacity block, the member's and the
 * loan's open flags, and the loan's decisions.
 *
 * The application's money figures (income, expenses, outside debt) show
 * only to a caller who may read the member's details (`borrowers:view` and
 * the member's branch, round fz); `application_figures_hidden` says so.
 *
 * Advisory only. Saving writes the application details
 * (credit_application_profiles) and, through the Recalculator, a score and
 * flags; never the loan, its schedule, approvals or payments, or the member.
 * Only a draft or for-approval application's assessment can be saved.
 */
final class AssessmentService
{
    /** The employment statuses an application can record, with their labels. */
    public const EMPLOYMENT_STATUSES = [
        'employed' => 'Employed',
        'self_employed' => 'Self-employed',
        'business_owner' => 'Business owner',
        'overseas_worker' => 'Overseas Filipino worker',
        'retired' => 'Retired',
        'not_employed' => 'Not employed',
        'other' => 'Other',
    ];

    /** How many of the loan's decisions the assessment lists, newest first. */
    public const DECISIONS_SHOWN = 50;

    /** How long a save waits for a member being scored before answering 409. */
    public const LOCK_WAIT_SECONDS = 3;

    public function __construct(
        private readonly Presenter $presenter,
        private readonly Recalculator $recalculator,
        private readonly CreditScope $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $viewer, Loan $loan): array
    {
        $member = Borrower::query()->select(CreditScope::MEMBER_COLUMNS)->findOrFail($loan->borrower_id);
        $settings = CreditScoringSetting::current();
        $profile = CreditApplicationProfile::query()->where('loan_id', $loan->id)->first();
        $score = CreditScore::query()
            ->with(['factors', 'revision:id,validated'])
            ->where('loan_id', $loan->id)
            ->where('context', CreditScore::CONTEXT_ASSESSMENT)
            ->orderByDesc('id')
            ->first();

        return [
            'loan_id' => (int) $loan->id,
            'loan_status' => $loan->status,
            'editable' => (bool) $loan->is_editable,
            'borrower_id' => (int) $member->id,
            'borrower_name' => $member->full_name,
            'borrower_code' => $member->borrower_code,
            'notice_version' => $settings->notice_version,
            'privacy_notice' => $settings->privacy_notice,
            'application_profile' => $profile === null ? null : $this->profile($profile, $settings, $this->scope->seesMemberDetails($viewer, $member)),
            'application_figures_hidden' => ! $this->scope->seesMemberDetails($viewer, $member),
            'score' => $score === null ? null : $this->presenter->score($viewer, $member, $score),
            'capacity' => $this->presenter->capacity($viewer, $member, $score),
            'policy_flags' => CreditPolicyFlag::query()
                ->open()
                ->where('borrower_id', $loan->borrower_id)
                ->where(fn ($q) => $q->whereNull('loan_id')->orWhere('loan_id', $loan->id))
                ->orderByRaw("type = 'hard_flag' DESC")
                ->orderByDesc('first_triggered_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (CreditPolicyFlag $flag) => $this->presenter->flag($viewer, $flag, $member))
                ->all(),
            'decisions' => CreditDecision::query()
                ->with('decidedByUser:id,first_name,last_name')
                ->where('loan_id', $loan->id)
                ->orderByDesc('decided_at')
                ->orderByDesc('id')
                ->limit(self::DECISIONS_SHOWN)
                ->get()
                ->map(fn (CreditDecision $decision) => $this->presenter->decision($decision))
                ->all(),
            'decisions_total' => CreditDecision::query()->where('loan_id', $loan->id)->count(),
            'employment_statuses' => array_map(
                fn (string $value, string $label) => ['value' => $value, 'label' => $label],
                array_keys(self::EMPLOYMENT_STATUSES),
                self::EMPLOYMENT_STATUSES,
            ),
        ];
    }

    /**
     * Save the sent details and the notice acknowledgement, and assess the
     * application, in one transaction under the member's lock: details the
     * assessment fails on are rolled back with it.
     *
     * 422 once the loan is past approval (checked again under the lock), or
     * for a registration that isn't a member. 409 when the member is still
     * being scored after LOCK_WAIT_SECONDS, and when two first saves of the
     * same application collide; nothing is saved either way.
     *
     * @param  array<string, mixed>  $fields
     */
    public function save(User $user, Loan $loan, array $fields, ?bool $noticeAcknowledged): void
    {
        $this->assertEditable($loan);

        if (! $this->recalculator->isScorable((int) $loan->borrower_id)) {
            throw ValidationException::withMessages(['loan' => 'Only a member\'s loan can be assessed; this registration is not approved.']);
        }

        try {
            $this->recalculator->assessLoanAfter(
                $loan,
                'Assessment saved',
                fn () => $this->writeProfile($user, $loan, $fields, $noticeAcknowledged),
                self::LOCK_WAIT_SECONDS,
            );
        } catch (LockTimeoutException) {
            abort(409, 'The member is being scored right now. Try again in a moment.');
        } catch (UniqueConstraintViolationException) {
            abort(409, 'These application details were just saved by someone else. Reload and try again.');
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function writeProfile(User $user, Loan $loan, array $fields, ?bool $noticeAcknowledged): void
    {
        $this->assertEditable(Loan::query()->select(['id', 'status'])->lockForUpdate()->findOrFail($loan->id));

        $profile = CreditApplicationProfile::query()->firstOrNew(['loan_id' => $loan->id]);
        $profile->fill($fields);

        if ($noticeAcknowledged === true) {
            $version = CreditScoringSetting::current()->notice_version;

            if ($profile->notice_version !== $version || $profile->notice_acknowledged_at === null) {
                $profile->fill(['notice_version' => $version, 'notice_acknowledged_at' => Carbon::now()]);
            }
        } elseif ($noticeAcknowledged === false) {
            $profile->fill(['notice_version' => null, 'notice_acknowledged_at' => null]);
        }

        if (! $profile->exists) {
            $profile->created_by = $user->id;
        }

        if ($profile->isDirty()) {
            $profile->updated_by = $user->id;
            $profile->save();
        }
    }

    private function assertEditable(Loan $loan): void
    {
        if (! $loan->is_editable) {
            throw ValidationException::withMessages(['loan' => 'Only a draft or for-approval loan\'s credit assessment can be saved.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(CreditApplicationProfile $profile, CreditScoringSetting $settings, bool $figures): array
    {
        return [
            'other_income' => $figures ? $profile->other_income : null,
            'business_income' => $figures ? $profile->business_income : null,
            'household_expenses' => $figures ? $profile->household_expenses : null,
            'outside_debt_payments' => $figures ? $profile->outside_debt_payments : null,
            'outside_lenders_count' => $profile->outside_lenders_count,
            'employment_status' => $profile->employment_status,
            'business_start_date' => $profile->business_start_date?->toDateString(),
            'years_at_residence' => $profile->years_at_residence,
            'verified_references_count' => $profile->verified_references_count,
            'income_verification' => $profile->income_verification,
            'notice_version' => $profile->notice_version,
            'notice_acknowledged_at' => Presenter::time($profile->notice_acknowledged_at),
            'notice_acknowledged' => $profile->notice_acknowledged_at !== null && $profile->notice_version === $settings->notice_version,
        ];
    }
}
