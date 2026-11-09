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
 * Advisory only. Saving writes the application details
 * (credit_application_profiles) and, through the Recalculator, a score and
 * flags; never the loan, its schedule, approvals or payments, or the member.
 * Only a draft or for-approval application's assessment can be saved.
 */
final class AssessmentService
{
    public function __construct(
        private readonly Presenter $presenter,
        private readonly Recalculator $recalculator,
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
            'application_profile' => $profile === null ? null : $this->profile($profile, $settings),
            'score' => $score === null ? null : $this->presenter->score($viewer, $member, $score),
            'capacity' => $score?->capacity,
            'policy_flags' => CreditPolicyFlag::query()
                ->open()
                ->where('borrower_id', $loan->borrower_id)
                ->where(fn ($q) => $q->whereNull('loan_id')->orWhere('loan_id', $loan->id))
                ->orderByRaw("type = 'hard_flag' DESC")
                ->orderByDesc('first_triggered_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (CreditPolicyFlag $flag) => $this->presenter->flag($viewer, $flag))
                ->all(),
            'decisions' => CreditDecision::query()
                ->with('decidedByUser:id,first_name,last_name')
                ->where('loan_id', $loan->id)
                ->orderByDesc('decided_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (CreditDecision $decision) => $this->presenter->decision($decision))
                ->all(),
        ];
    }

    /**
     * Save the sent details and the notice acknowledgement, then assess the
     * application. 422 once the loan is past approval, or for a registration
     * that isn't a member. 409 when two first saves of the same application
     * collide, and when the member is being scored and the wait for them
     * runs out (the details stay saved and are scored by the next run).
     *
     * @param  array<string, mixed>  $fields
     */
    public function save(User $user, Loan $loan, array $fields, ?bool $noticeAcknowledged): void
    {
        if (! $loan->is_editable) {
            throw ValidationException::withMessages(['loan' => 'Only a draft or for-approval loan\'s credit assessment can be saved.']);
        }

        if (! $this->recalculator->isScorable((int) $loan->borrower_id)) {
            throw ValidationException::withMessages(['loan' => 'Only a member\'s loan can be assessed; this registration is not approved.']);
        }

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

            try {
                $profile->save();
            } catch (UniqueConstraintViolationException) {
                abort(409, 'These application details were just saved by someone else. Reload and try again.');
            }
        }

        try {
            $this->recalculator->assessLoan($loan, 'Assessment saved');
        } catch (LockTimeoutException) {
            abort(409, 'The member is being scored right now. Try again in a moment.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(CreditApplicationProfile $profile, CreditScoringSetting $settings): array
    {
        return [
            'other_income' => $profile->other_income,
            'business_income' => $profile->business_income,
            'household_expenses' => $profile->household_expenses,
            'outside_debt_payments' => $profile->outside_debt_payments,
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
