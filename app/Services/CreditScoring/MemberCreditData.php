<?php

namespace App\Services\CreditScoring;

use App\Models\Borrower;
use App\Models\CreditApplicationProfile;
use App\Models\Loan;
use App\Services\ValidIdService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the engine may read about one member, loaded once, from every
 * branch: a member's history in another branch still counts. Read only.
 *
 * Only the columns a factor or a policy rule needs are selected, so the
 * member's personal traits (date of birth, sex, marital status, home, family
 * details) and share capital never reach the engine at all.
 *
 * - `loans`: every ever-released loan (released, ongoing, completed,
 *   defaulted, restructured).
 * - `inPlaceRestructuredLoanIds`: those of them restructured in place (an
 *   applied restructure adjustment), which keep their own status.
 * - `recoveredFromDefaultLoanIds`: those of them with a posted payment
 *   recorded while the loan was defaulted (`repayments.loan_status_before`),
 *   so a loan a recovery completed still counts as having ended badly.
 * - `periods`: their schedule rows, each with `allocations`, the principal
 *   and interest each posted payment put on it, in payment order.
 * - `profile`: the application details the score reads, counting only a
 *   profile that holds at least one detail
 *   (CreditApplicationProfile::DETAIL_FIELDS). An application is judged on
 *   its own details alone: its own profile, or none, never another loan's.
 *   A member's own score reads their most recent detailed profile on any
 *   loan, so one that records only the notice never hides earlier details.
 * - `noticeAcknowledged`: whether the application loan's own profile
 *   records the member's acknowledgement of the credit assessment notice.
 * - `noticeVersion`: the notice version that acknowledgement was given
 *   under, or null without one. PolicyFlags compares it with the current
 *   version, so an acknowledgement of an earlier notice raises the flag.
 * - `applicationLoan`: the loan being assessed, if any; it is never part of
 *   `loans`, which holds released loans only.
 */
final class MemberCreditData
{
    /**
     * @param  Collection<int, object>  $loans
     * @param  Collection<int, object>  $periods
     * @param  list<int>  $inPlaceRestructuredLoanIds
     * @param  list<int>  $recoveredFromDefaultLoanIds
     */
    public function __construct(
        public readonly object $member,
        public readonly Collection $loans,
        public readonly Collection $periods,
        public readonly array $inPlaceRestructuredLoanIds,
        public readonly bool $hasValidId,
        public readonly ?CreditApplicationProfile $profile,
        public readonly ?Loan $applicationLoan,
        public readonly Carbon $asOf,
        public readonly bool $noticeAcknowledged = false,
        public readonly ?string $noticeVersion = null,
        public readonly array $recoveredFromDefaultLoanIds = [],
    ) {}

    public static function load(int $borrowerId, ?Loan $applicationLoan = null, ?Carbon $asOf = null): self
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();

        $member = DB::table('borrowers')
            ->where('id', $borrowerId)
            ->select([
                'id', 'branch_id', 'status', 'first_name', 'middle_name', 'last_name', 'suffix',
                'contact_number', 'monthly_income', 'date_hired', 'approved_at', 'created_at',
            ])
            ->first();

        abort_if($member === null, 404, "Member {$borrowerId} not found.");

        $loans = DB::table('loans')
            ->where('borrower_id', $borrowerId)
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->select([
                'id', 'status', 'branch_id', 'principal_amount', 'source_loan_id', 'write_off_amount',
                'imported_arrears_baseline', 'grace_period_days', 'released_at', 'restructured_at',
            ])
            ->orderBy('id')
            ->get();

        $periods = self::periodsFor($loans->pluck('id')->all());

        $inPlaceRestructured = DB::table('loan_adjustments')
            ->whereIn('loan_id', $loans->pluck('id')->all())
            ->where('adjustment_type', 'restructure')
            ->where('status', 'applied')
            ->distinct()
            ->pluck('loan_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $recoveredFromDefault = DB::table('repayments')
            ->whereIn('loan_id', $loans->pluck('id')->all())
            ->where('status', 'posted')
            ->where('loan_status_before', 'defaulted')
            ->distinct()
            ->pluck('loan_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $own = $applicationLoan === null ? null : CreditApplicationProfile::query()->where('loan_id', $applicationLoan->id)->first();

        $hasValidId = DB::table('documents')
            ->where('documentable_type', Borrower::class)
            ->where('documentable_id', $borrowerId)
            ->where('type', ValidIdService::DOCUMENT_TYPE)
            ->exists();

        return new self(
            $member,
            $loans,
            $periods,
            $inPlaceRestructured,
            $hasValidId,
            $applicationLoan === null ? self::newestDetailedProfile($borrowerId) : ($own?->hasDetails() ? $own : null),
            $applicationLoan,
            $asOf,
            $own?->notice_acknowledged_at !== null,
            $own?->notice_acknowledged_at === null ? null : $own->notice_version,
            recoveredFromDefaultLoanIds: $recoveredFromDefault,
        );
    }

    public function fullName(): string
    {
        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
            $this->member->first_name,
            $this->member->middle_name,
            $this->member->last_name,
            $this->member->suffix,
        ]))));
    }

    /**
     * The loans that still owe: released, ongoing and defaulted. When a
     * restructure is being assessed, its source loan is left out, since the
     * new loan replaces it.
     *
     * @return Collection<int, object>
     */
    public function collectibleLoans(): Collection
    {
        $sourceId = $this->applicationLoan?->source_loan_id;

        return $this->loans
            ->filter(fn (object $loan) => in_array($loan->status, Loan::COLLECTIBLE_STATUSES, true))
            ->reject(fn (object $loan) => $sourceId !== null && (int) $loan->id === (int) $sourceId)
            ->values();
    }

    /**
     * @param  list<int>  $loanIds
     * @return Collection<int, object>
     */
    private static function periodsFor(array $loanIds): Collection
    {
        if ($loanIds === []) {
            return collect();
        }

        $periods = DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->select([
                'id', 'loan_id', 'period_number', 'due_date', 'principal_due', 'interest_due', 'total_due',
                'principal_paid', 'interest_paid', 'status', 'closed_by_adjustment_id', 'closed_by_loan_id',
            ])
            ->orderBy('loan_id')
            ->orderBy('period_number')
            ->get();

        $allocations = DB::table('repayment_allocations')
            ->join('repayments', 'repayments.id', '=', 'repayment_allocations.repayment_id')
            ->whereIn('repayment_allocations.amortization_schedule_id', $periods->pluck('id')->all())
            ->where('repayments.status', 'posted')
            ->orderBy('repayments.payment_date')
            ->orderBy('repayments.id')
            ->select([
                'repayment_allocations.amortization_schedule_id',
                'repayment_allocations.principal',
                'repayment_allocations.interest',
                'repayments.payment_date',
            ])
            ->get()
            ->groupBy('amortization_schedule_id');

        return $periods->map(function (object $period) use ($allocations) {
            $period->allocations = ($allocations->get($period->id) ?? collect())->values();

            return $period;
        });
    }

    /**
     * The member's most recent profile, on any of their loans, that holds at
     * least one detail.
     */
    private static function newestDetailedProfile(int $borrowerId): ?CreditApplicationProfile
    {
        return CreditApplicationProfile::query()
            ->whereIn('loan_id', DB::table('loans')->where('borrower_id', $borrowerId)->select('id'))
            ->where(function ($any) {
                foreach (CreditApplicationProfile::DETAIL_FIELDS as $field) {
                    $any->orWhereNotNull($field);
                }
            })
            ->orderByDesc('loan_id')
            ->first();
    }
}
