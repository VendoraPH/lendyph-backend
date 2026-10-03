<?php

namespace App\Services;

use App\Enums\TermUnit;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\Money;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanService
{
    /**
     * What a loan refuses deductions with when they are more than its
     * principal: computeDeductions()'s guard, and the form preview's `error`.
     */
    private const DEDUCTIONS_EXCEED_PRINCIPAL = 'Total deductions exceed the principal amount.';

    /**
     * Create a draft loan application (POST /loans).
     *
     * In its own transaction, so the lock Loan::booted() takes on the newest
     * loan row for the next application number is held until the loan and its
     * co-makers are committed, and a failure anywhere leaves no half-made
     * application behind. Without it that lock was released the moment it was
     * taken (autocommit), and two creates at once could both take the same
     * LA- number: the second one's insert then failed on the unique index as a
     * 500. A create pledges no collateral (StoreLoanRequest carries none), so
     * its first lock is that loan row; see LoanWriteTransaction for the order.
     *
     * Even holding the lock, a create that queued behind another's can read the
     * number that one is inserting (InnoDB resumes the waiting read where it
     * stood), so the duplicate-key error on `loans_application_number_unique`
     * is answered with the same 409 as a deadlock, scoped to that index alone.
     * Both say so in a create's words: there is no loan yet to "reload", so
     * the client is told to submit again. Nothing retries on its own.
     *
     * This is the outermost transaction: LoanController::store() and the test
     * helpers are the only callers. A restructure creates its loan inside its
     * own transaction, through createLoanRecord().
     */
    public function createLoan(array $validated, User $user): Loan
    {
        return LoanWriteTransaction::run(
            fn (): Loan => $this->createLoanRecord($validated, $user),
            racedUniqueIndex: 'loans_application_number_unique',
            conflictMessage: 'Another loan application was created at the same moment. Submit again.',
        );
    }

    /**
     * The loan createLoan() creates, written inside the caller's transaction.
     *
     * @param  bool  $enforceMinimumAmount  Set false for a restructure: the new
     *                                      loan's principal is a part-paid balance, which is routinely below the
     *                                      product's `min_amount` and must not be rejected for it. Every other
     *                                      product rule (max_amount, interest-rate range, term range) still applies.
     */
    private function createLoanRecord(array $validated, User $user, bool $enforceMinimumAmount = true): Loan
    {
        $product = LoanProduct::findOrFail($validated['loan_product_id']);
        $borrower = Borrower::findOrFail($validated['borrower_id']);

        $principal = (float) $validated['principal_amount'];
        $interestRate = (float) ($validated['interest_rate'] ?? $product->interest_rate);
        $term = (int) ($validated['term'] ?? $product->term);
        $frequency = $validated['frequency'] ?? $product->frequency;
        // Copied onto the loan, so editing the product later never reprices it.
        $snapshot = $this->productSnapshot($product);

        // Validate principal against product min/max
        if ($enforceMinimumAmount && $product->min_amount > 0 && $principal < (float) $product->min_amount) {
            throw ValidationException::withMessages([
                'principal_amount' => ["Minimum loan amount for this product is {$product->min_amount}."],
            ]);
        }
        if ($product->max_amount > 0 && $principal > (float) $product->max_amount) {
            throw ValidationException::withMessages([
                'principal_amount' => ["Maximum loan amount for this product is {$product->max_amount}."],
            ]);
        }

        $this->assertInterestRateWithinProductRange($interestRate, $product);
        $this->assertTermWithinProductRange($term, $product);

        // The product's own fees apply only when no deductions were sent at all
        // (key absent or null). A sent `[]` is a deliberate "none": the
        // restructure form sends it when the operator waives every fee.
        $deductionResult = $this->computeDeductions($principal, $validated['deductions'] ?? $this->productFeeDeductions($product));

        $loan = Loan::create([
            ...$snapshot,
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'branch_id' => $borrower->branch_id,
            'interest_rate' => $interestRate,
            'term' => $term,
            'frequency' => $frequency,
            'principal_amount' => $principal,
            'purpose' => $validated['purpose'] ?? null,
            'start_date' => $validated['start_date'],
            'maturity_date' => $this->maturityDateFor($validated['start_date'], $term, $snapshot['term_unit'], $frequency),
            'deductions' => $deductionResult['items'],
            'total_deductions' => $deductionResult['total'],
            'net_proceeds' => $deductionResult['net_proceeds'],
            'scb_amount' => $validated['scb_amount'] ?? 0,
            'policy_exception' => $validated['policy_exception'] ?? false,
            'policy_exception_details' => $validated['policy_exception_details'] ?? null,
            'status' => 'draft',
            'created_by' => $user->id,
            'account_officer_id' => $validated['account_officer_id'] ?? null,
        ]);

        // `co_maker_ids` are MEMBER ids here — see coMakerIdsForMembers().
        if (! empty($validated['co_maker_ids'])) {
            $coMakerIds = $this->coMakerIdsForMembers($validated['co_maker_ids']);

            if (! empty($coMakerIds)) {
                $this->syncCoMakers($loan, $coMakerIds, $user);
            }
        }

        return $loan;
    }

    /**
     * What a loan copies from its product and keeps, whatever happens to the
     * product afterwards: how interest is computed (`interest_method`), what
     * the term counts (`term_unit`), what period the rate is quoted per
     * (`interest_rate_frequency`), and the late-payment terms (`penalty_rate`,
     * `grace_period_days`).
     *
     * The one list. createLoanRecord() copies it onto a new loan, and the
     * form preview builds its unsaved loan from it, so the preview's schedule
     * follows the same product terms the saved loan will. The loan keeps them
     * even if the product changes afterwards.
     *
     * @return array{interest_method: string, term_unit: string, interest_rate_frequency: string, penalty_rate: mixed, grace_period_days: mixed}
     */
    private function productSnapshot(LoanProduct $product): array
    {
        return [
            'interest_method' => $product->interest_method,
            'term_unit' => $product->term_unit->value,
            'interest_rate_frequency' => $product->interest_rate_frequency->value,
            'penalty_rate' => $product->penalty_rate,
            'grace_period_days' => $product->grace_period_days,
        ];
    }

    /**
     * The deductions a loan carries when its application states none: the
     * product's own processing, service and notarial fee rates, each a
     * percentage of the principal, in that order, leaving out a rate of 0.
     *
     * Inputs for computeDeductions(), shared by the paths that fall back on
     * the product's fees (a create, and the form preview of one), so the
     * preview cannot list a different set than the create charges.
     *
     * @return list<array{name: string, amount: float, type: string}>
     */
    private function productFeeDeductions(LoanProduct $product): array
    {
        $deductions = [];

        if ((float) $product->processing_fee > 0) {
            $deductions[] = ['name' => 'Processing Fee', 'amount' => (float) $product->processing_fee, 'type' => 'percentage'];
        }
        if ((float) $product->service_fee > 0) {
            $deductions[] = ['name' => 'Service Fee', 'amount' => (float) $product->service_fee, 'type' => 'percentage'];
        }
        if ((float) ($product->notarial_fee ?? 0) > 0) {
            $deductions[] = ['name' => 'Notarial Fee', 'amount' => (float) $product->notarial_fee, 'type' => 'percentage'];
        }

        return $deductions;
    }

    /**
     * A loan's term must sit inside its product's range, counted in the
     * product's term unit: `min_term` (1 when unset) up to `max_term` (the
     * product's own term when unset).
     *
     * createLoanRecord()'s check, and the same range formMaturity() reads, so
     * the preview gives no maturity date for a term a create would refuse.
     */
    private function assertTermWithinProductRange(int $term, LoanProduct $product): void
    {
        $minTerm = (int) ($product->min_term ?? 1);
        $maxTerm = (int) ($product->max_term ?? $product->term);

        if ($term < $minTerm || $term > $maxTerm) {
            throw ValidationException::withMessages([
                'term' => ["Term must be between {$minTerm} and {$maxTerm} {$product->term_unit->value} for this product."],
            ]);
        }
    }

    /**
     * A loan's rate must sit inside its product's range: `min_interest_rate`
     * (or the product rate when no minimum is set) up to `interest_rate`.
     *
     * Shared by createLoan() and updateLoan(), so an edit cannot move a rate
     * somewhere creating the loan would have refused.
     */
    private function assertInterestRateWithinProductRange(float $interestRate, LoanProduct $product): void
    {
        $minRate = (float) ($product->min_interest_rate ?? $product->interest_rate);
        $maxRate = (float) $product->interest_rate;
        if ($interestRate < $minRate || $interestRate > $maxRate) {
            throw ValidationException::withMessages([
                'interest_rate' => ["Interest rate must be between {$minRate}% and {$maxRate}% for this product."],
            ]);
        }
    }

    /**
     * The co-maker record for each member (borrower) id, created the first
     * time that member is picked.
     *
     * Member ids and nothing else, because that is all the loan form's co-maker
     * picker sends. These ids used to be read as a co-maker RECORD id first and
     * a member id only failing that. The two are separate sequences, and this
     * very method creates a co-maker record on a member's first pick, so they
     * collide almost at once: member #7 picked on one loan gets record #1, and
     * member #1 picked on the next was then bound to member #7 — the wrong
     * person made jointly liable, with nothing to say so. It also let a rejected
     * member through validation whenever some co-maker record carried their
     * number. StoreLoanRequest now accepts only non-rejected member ids.
     *
     * Restructure and update share this same reading; see restructure() and
     * updateLoan().
     *
     * @param  array<int, int|string>  $memberIds
     * @return list<int>
     */
    private function coMakerIdsForMembers(array $memberIds): array
    {
        $coMakerIds = [];

        foreach ($memberIds as $memberId) {
            $member = Borrower::find($memberId);

            if ($member) {
                $coMakerIds[] = $this->coMakerRecordFor($member)->id;
            }
        }

        return $coMakerIds;
    }

    /**
     * The co-maker record that stands for $member on a loan, created on first
     * use.
     *
     * Keyed on `member_borrower_id`, NOT `borrower_id` + name. The old key
     * carried no suffix, so a Co-makers-tab entry created under one member's
     * profile describing someone else by name (e.g. a Jr. entering his
     * father's Sr. record with no suffix) collided with that member later
     * being picked as a co-maker himself — the wrong identity got bound to the
     * loan. `member_borrower_id` is a member's OWN id and nothing else, so it
     * cannot collide with a same-named relative's entry, which never carries
     * one at all.
     */
    private function coMakerRecordFor(Borrower $member): CoMaker
    {
        return CoMaker::firstOrCreate(
            ['member_borrower_id' => $member->id],
            [
                'borrower_id' => $member->id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'address' => $member->address,
                'contact_number' => $member->contact_number,
                'relationship_to_borrower' => 'other',
                'status' => 'active',
            ],
        );
    }

    /**
     * Make $coMakerIds exactly the loan's co-makers, recording $user as the
     * one who added each link this call creates.
     *
     * createLoan(), restructure() and updateLoan() all link co-makers through
     * here, so none of them can forget `added_by`; addCoMakerAwaitingRelease()
     * attaches a single one and stamps it itself.
     *
     * Only NEW links are stamped: a co-maker who was already on the loan keeps
     * the `added_by` (and `created_at`) of whoever linked them first, so an
     * edit that re-sends the same set does not rewrite the record to say the
     * editor added everyone.
     *
     * @param  list<int>  $coMakerIds
     */
    private function syncCoMakers(Loan $loan, array $coMakerIds, ?User $user): void
    {
        $changes = $loan->coMakers()->sync($coMakerIds);

        if ($user && $changes['attached'] !== []) {
            $loan->coMakers()->updateExistingPivot($changes['attached'], ['added_by' => $user->id]);
        }
    }

    /**
     * Restructure a live loan by creating a NEW loan carrying its outstanding
     * balance.
     *
     * The new loan starts as `draft` and goes through the normal submit →
     * approve → release flow. The source loan is untouched here and stays fully
     * collectible: it is only closed as `restructured` when the new loan is
     * RELEASED (see closeRestructuredSource()). A rejected or voided restructure
     * therefore leaves the source exactly as it was.
     *
     * @param  array<string, mixed>  $validated  RestructureLoanRequest payload
     */
    public function restructure(Loan $sourceLoan, array $validated, User $user): Loan
    {
        if ((int) $validated['borrower_id'] !== (int) $sourceLoan->borrower_id) {
            throw ValidationException::withMessages([
                'borrower_id' => ['A restructured loan must stay with the borrower of the loan it replaces.'],
            ]);
        }

        $principal = round((float) $validated['principal_amount'], 2);
        $remarks = $validated['remarks'] ?? null;

        // What the source holds, read before the transaction so that the read
        // takes no lock and fixes no snapshot; inheritCollaterals() reads it
        // again, exactly, under the source's lock.
        $heldBefore = CollateralAttacher::heldBy($sourceLoan);

        // The guards run INSIDE the transaction, opening with a row lock on the
        // source. The one-open-application-at-a-time rule is an exists() check
        // followed by an insert, so without serializing on the source two
        // simultaneous requests would both see "none open" and both create one.
        //
        // A collateral write, since it copies pledges onto the new loan, so a
        // deadlock in it is a 409 like the other pledge writes', not a 500.
        // This is the outermost transaction: LoanController::restructure() is
        // the only caller.
        return CollateralWriteTransaction::run(function () use ($sourceLoan, $validated, $user, $principal, $remarks, $heldBefore) {
            // The source's collateral first, then the source: the order every
            // collateral write takes (CollateralAttacher), so this cannot hold
            // the loan while waiting on a collateral that a detach or a
            // revaluation holds while waiting on the loan. The collateral rows
            // only, never `loan_collaterals`: a locking read of the source's
            // pledges would gap-lock the index range after them, where a pledge
            // written on the newest loan lands, while createLoan() below waits
            // on that newest loan's row for the next application number.
            $lockedCollaterals = CollateralAttacher::lock($heldBefore);
            $lockedSource = $this->lockSourceLoan($sourceLoan->id);

            $hasOpenRestructure = $lockedSource->restructuredInto()
                ->whereIn('status', ['draft', 'for_review', 'approved'])
                ->exists();

            if ($hasOpenRestructure) {
                throw ValidationException::withMessages([
                    'loan' => ['This loan already has a restructure application in progress. Release, reject, or void it first.'],
                ]);
            }

            ['outstanding' => $outstanding, 'shortfall' => $shortfall] =
                $this->assertRestructureInvariants($lockedSource, $principal, $remarks, $user);

            // Without `co_maker_ids`: createLoanRecord() reads those as member
            // ids, and this form's are not only member ids. Linked below instead.
            // Not createLoan(), whose own transaction would be a savepoint here.
            $newLoan = $this->createLoanRecord(Arr::except($validated, 'co_maker_ids'), $user, enforceMinimumAmount: false);

            // Terms as approved, frozen here. The write-off at release is
            // computed from these and not from the live columns — see the
            // migration for why.
            $newLoan->update([
                'source_loan_id' => $lockedSource->id,
                'restructure_outstanding' => $outstanding,
                'restructure_principal' => $principal,
                'restructure_shortfall' => $shortfall,
                'restructure_remarks' => $remarks,
            ]);

            // Co-makers carry over when the caller says nothing about them. An
            // explicitly empty array is respected — that is how you drop them.
            if (! array_key_exists('co_maker_ids', $validated)) {
                $inherited = $lockedSource->coMakers()->pluck('co_makers.id')->all();

                if ($inherited !== []) {
                    $this->syncCoMakers($newLoan, $inherited, $user);
                }
            } elseif (! empty($validated['co_maker_ids'])) {
                $coMakerIds = $this->coMakerIdsForMembers($validated['co_maker_ids']);

                if ($coMakerIds !== []) {
                    $this->syncCoMakers($newLoan, $coMakerIds, $user);
                }
            }

            $inheritedCollaterals = $this->inheritCollaterals($lockedSource, $newLoan, $user, $lockedCollaterals->keys()->all());

            AuditLogService::log(
                action: 'restructure_created',
                auditable: $newLoan,
                oldValues: [
                    'source_loan_id' => $lockedSource->id,
                    'source_application_number' => $lockedSource->application_number,
                    'source_loan_account_number' => $lockedSource->loan_account_number,
                    'source_status' => $lockedSource->status,
                ],
                newValues: [
                    'outstanding_at_application' => $outstanding,
                    'new_principal' => $principal,
                    'shortfall_amount' => $shortfall,
                    'remarks' => $remarks,
                    'inherited_collateral_ids' => $inheritedCollaterals,
                ],
                description: "Restructure application {$newLoan->application_number} created from loan "
                    .($lockedSource->loan_account_number ?? $lockedSource->application_number)
                    ." (outstanding ₱{$outstanding} → principal ₱{$principal}, shortfall ₱{$shortfall})",
            );

            return $newLoan->refresh();
        });
    }

    /**
     * Move the source loan's collateral onto the loan replacing it.
     *
     * Unconditional, unlike the co-maker inheritance above: there is no
     * `collateral_ids` input on RestructureLoanRequest to opt out with, and
     * leaving the collateral behind is not a neutral choice. On release,
     * closeRestructuredSource() flips the source to `restructured`, which is
     * outside Loan::PLEDGING_STATUSES — so a source whose collateral did not come
     * with it makes CollateralResource's `active_loans` report a land title as
     * FREE while it is still securing a live balance, and makes
     * CollateralController::attach() let a second loan take it. That is the
     * exact failure the lock state exists to prevent, reached through the
     * sanctioned workflow.
     *
     * Deliberately NOT routed through the attach guard. The source is
     * `released` or `ongoing` — assertRestructureInvariants() accepts nothing
     * else — so the guard would reject every one of these rows. Collateral
     * moving from a live loan to the loan replacing it is the sanctioned
     * transfer the guard protects, not the double pledge it refuses. Both loans
     * hold the collateral between application and release, which is correct and
     * costs nothing: the new loan is a draft, so `active_loans` still names only
     * the source, and release closes the source in the same transaction, so
     * there is never a moment when both are active.
     *
     * @param  array<int, int>  $lockedCollateralIds  the collateral rows restructure() locked
     * @return array<int, int> ids of the collaterals carried over
     *
     * @throws HttpResponseException 409 when the source holds a collateral that was not locked
     */
    private function inheritCollaterals(Loan $source, Loan $newLoan, User $user, array $lockedCollateralIds): array
    {
        // A plain read, and exact. restructure() holds the source's row lock,
        // and every write that adds or removes a pledge holds that loan's row
        // lock (see CollateralAttacher), so the source's pledges cannot change under
        // this read or before the copy commits; on a released or ongoing loan
        // none is allowed at all. The collateral rows were locked before the
        // source, as restructure() describes, which is what a concurrent attach
        // of one of them serializes on.
        //
        // One the source gained after restructure() read what it held would be
        // copied without its row lock, so that is refused as the conflict it
        // is.
        $snapshotValues = $source->collaterals()
            ->orderBy('collaterals.id')
            ->get()
            ->mapWithKeys(fn (Collateral $collateral) => [
                // Carried forward, not re-appraised.
                //
                // POST /loans/{loan}/collaterals takes `snapshot_value` from
                // the operator; this path has no such input, so deriving a
                // fresh figure from the live `collaterals.amount` would have
                // the server assert an appraisal nobody signed off on — the
                // same class of error closeRestructuredSource() avoids by
                // computing the write-off from the approved snapshot rather
                // than the live balance. The source's row is left untouched,
                // so what was pledged against the original loan, and when it
                // was struck, both stay on the record. An operator who wants
                // the collateral re-valued for the new loan detaches and
                // re-attaches it with a stated value.
                $collateral->id => $collateral->pivot->snapshot_value,
            ])
            ->all();

        if (array_diff(array_keys($snapshotValues), $lockedCollateralIds) !== []) {
            throw CollateralWriteTransaction::conflict();
        }

        if ($snapshotValues === []) {
            return [];
        }

        // Recorded per collateral, as `collateral_attached` on the new loan,
        // like every other pledge.
        CollateralAttacher::inheritLocked($newLoan, $snapshotValues, $user);

        return array_map('intval', array_keys($snapshotValues));
    }

    /**
     * Take a row lock on the loan being restructured.
     *
     * Every path that decides something about a source loan — creating an
     * application against it, releasing one — serializes here, so the checks
     * below cannot be raced by a concurrent request.
     */
    private function lockSourceLoan(int $sourceLoanId): Loan
    {
        $source = Loan::whereKey($sourceLoanId)->lockForUpdate()->first();

        if (! $source) {
            throw ValidationException::withMessages([
                'loan' => ['The loan being restructured no longer exists.'],
            ]);
        }

        return $source;
    }

    /**
     * The complete rule set for restructuring a given source at a given
     * principal. Shared by creation and by any later edit of the principal, so
     * the two can never drift apart.
     *
     * @return array{outstanding: float, shortfall: float}
     *
     * @throws AuthorizationException when a shortfall is attempted without `loans:write_off`
     */
    private function assertRestructureInvariants(Loan $sourceLoan, float $principal, ?string $remarks, User $user): array
    {
        if (! in_array($sourceLoan->status, ['released', 'ongoing'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only released or ongoing loans can be restructured.'],
            ]);
        }

        $outstanding = $this->totalOutstanding($sourceLoan);

        if ($outstanding <= 0) {
            throw ValidationException::withMessages([
                'loan' => ['This loan has no outstanding balance to restructure.'],
            ]);
        }

        // Compared in centavos so a 2dp float never rounds a genuine overshoot
        // into an accepted amount, or an exact match into a rejection.
        if ($this->toCentavos($principal) > $this->toCentavos($outstanding)) {
            throw ValidationException::withMessages([
                'principal_amount' => ["The restructured principal cannot exceed the loan's outstanding balance of {$outstanding}."],
            ]);
        }

        $shortfall = round($outstanding - $principal, 2);

        if ($this->toCentavos($shortfall) > 0) {
            // A shortfall writes debt off, so it must carry a reason…
            if (blank($remarks)) {
                throw ValidationException::withMessages([
                    'remarks' => ['Remarks are required when the restructured principal is less than the outstanding balance.'],
                ]);
            }

            // …and destroying debt is a separate privilege from rescheduling it.
            // Enforced here rather than in the FormRequest because whether this
            // is a shortfall at all depends on the computed outstanding balance,
            // which the request cannot see.
            if (! $user->can('loans:write_off')) {
                throw new AuthorizationException(
                    'Writing off part of the balance requires the loans:write_off permission. '
                    ."Restructure the full outstanding balance of ₱{$outstanding} instead.",
                );
            }
        }

        return ['outstanding' => $outstanding, 'shortfall' => $shortfall];
    }

    /**
     * Everything the borrower still owes on a loan: unpaid principal, unpaid
     * interest, unpaid penalty, plus any insurance premium still on the books.
     *
     * Deliberately WIDER than the `Loan::outstanding_balance` accessor
     * (principal + insurance only) because a restructure normally capitalizes
     * arrears — the borrower's whole obligation moves to the new loan, not just
     * the principal slice of it.
     */
    private function totalOutstanding(Loan $loan): float
    {
        $openSchedules = $loan->amortizationSchedules()
            ->reorder()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get();

        $unpaid = $openSchedules->sum(
            fn (AmortizationSchedule $s): float => max(0, (float) $s->principal_due - (float) $s->principal_paid)
                + max(0, (float) $s->interest_due - (float) $s->interest_paid)
                + max(0, (float) ($s->penalty_amount ?? 0) - (float) ($s->penalty_paid ?? 0)),
        );

        return round((float) $unpaid + (float) $loan->insurance_remaining_balance, 2);
    }

    /**
     * A peso figure as whole centavos, rounded half away from zero. Public so
     * CollateralSecurity converts a saved pledge the way formPreview()
     * converts a stated one.
     */
    public static function toCentavos(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function updateLoan(Loan $loan, array $validated, ?User $user = null): Loan
    {
        if (! $loan->is_editable) {
            throw ValidationException::withMessages([
                'status' => ['Loan can only be edited in draft or for_review status.'],
            ]);
        }

        // Only when the edit moves the rate or the product: a draft whose rate
        // is left alone stays editable even if its product's range has since
        // narrowed. Checked before guardRestructurePrincipalEdit(), which
        // writes an audit row, so a refused rate leaves nothing behind.
        $rateChanges = isset($validated['interest_rate'])
            && (float) $validated['interest_rate'] !== (float) $loan->interest_rate;
        $productChanges = isset($validated['loan_product_id'])
            && (int) $validated['loan_product_id'] !== (int) $loan->loan_product_id;

        if ($rateChanges || $productChanges) {
            $this->assertInterestRateWithinProductRange(
                (float) ($validated['interest_rate'] ?? $loan->interest_rate),
                $productChanges ? LoanProduct::findOrFail($validated['loan_product_id']) : $loan->loanProduct,
            );
        }

        // `collaterals` is never a loan column. Absent, collateral is not
        // touched at all; present, it is the loan's complete list.
        $collaterals = $validated['collaterals'] ?? null;
        unset($validated['collaterals']);

        // What the loan holds, read before the transaction for lockForList()
        // to lock, and checked again there under the loan's lock.
        $heldBefore = $collaterals === null ? [] : CollateralAttacher::heldBy($loan);

        // One transaction, so a refused collateral leaves the loan's own fields
        // unsaved too. It must be the OUTERMOST one, and its collateral locks
        // its first statements: CollateralPledgeGuard's snapshot rule. Then the
        // loan row, the order every collateral write takes (CollateralAttacher),
        // and the editable check made again on that locked row: the one above
        // ran before the transaction, so a loan approved since would otherwise
        // have its collateral changed after sign-off.
        $edit = function () use ($loan, $validated, $user, $collaterals, $heldBefore): Loan {
            $lockedCollaterals = $collaterals === null ? null : CollateralAttacher::lockForList(
                $loan,
                array_map(static fn (array $row): int => (int) $row['collateral_id'], $collaterals),
                $heldBefore,
            );

            $validated = $this->guardRestructurePrincipalEdit($loan, $validated, $user);

            $needsRecompute = isset($validated['principal_amount']) || isset($validated['deductions']);

            if ($needsRecompute) {
                $principal = (float) ($validated['principal_amount'] ?? $loan->principal_amount);
                $deductions = $validated['deductions'] ?? $this->deductionInputsFrom($loan->deductions ?? []);
                $result = $this->computeDeductions($principal, $deductions);
                $validated['deductions'] = $result['items'];
                $validated['total_deductions'] = $result['total'];
                $validated['net_proceeds'] = $result['net_proceeds'];
            }

            if (isset($validated['start_date'])) {
                $validated['maturity_date'] = $this->maturityDateFor(
                    $validated['start_date'],
                    $loan->term,
                    $loan->term_unit->value,
                    $loan->frequency,
                );
            }

            $loan->update($validated);

            // MEMBER ids only, exactly as createLoan() reads them — see
            // coMakerIdsForMembers(). UpdateLoanRequest previously left this
            // field unvalidated as bare co-maker record ids.
            if (isset($validated['co_maker_ids'])) {
                $this->syncCoMakers($loan, $this->coMakerIdsForMembers($validated['co_maker_ids']), $user);
            }

            if ($lockedCollaterals !== null) {
                $this->reconcileCollaterals($loan, $collaterals, $lockedCollaterals, $user);
            }

            return $loan;
        };

        // With a list this is a collateral write, so a deadlock on its locks is
        // a 409 like the attach endpoint's rather than a 500.
        return $collaterals === null ? DB::transaction($edit) : CollateralWriteTransaction::run($edit);
    }

    /**
     * Make the loan's collateral exactly the listed set.
     *
     * Collateral the loan holds and the list leaves out is detached. Listed
     * collateral the loan does not hold yet is attached through the same
     * guards as POST /loans/{loan}/collaterals. Collateral that is both held
     * and listed is left exactly as it is: its `snapshot_value` and
     * `attached_at` record the appraisal it was pledged at, and an edit that
     * re-sends the list must not re-appraise it. An operator who wants a new
     * value detaches and re-attaches it.
     *
     * Each attach and detach is recorded by CollateralAttacher, one audit row
     * per collateral, exactly as the endpoints record theirs, beside the
     * `updated` row the Loan model writes for the loan's own fields. Nothing
     * is written for collateral the list leaves as it was.
     *
     * @param  list<array{collateral_id: int|string, snapshot_value: int|float|string}>  $collaterals
     * @param  array{held: list<int>, collaterals: EloquentCollection<int, Collateral>}  $locked  as CollateralAttacher::lockForList() returns it
     *
     * @throws ValidationException on `collaterals.{index}.collateral_id`
     */
    private function reconcileCollaterals(Loan $loan, array $collaterals, array $locked, ?User $user): void
    {
        $listedIds = array_map(static fn (array $row): int => (int) $row['collateral_id'], $collaterals);

        foreach (array_diff($locked['held'], $listedIds) as $collateralId) {
            CollateralAttacher::detachLocked($loan, $collateralId, $user);
        }

        foreach ($collaterals as $index => $row) {
            $collateralId = (int) $row['collateral_id'];

            if (in_array($collateralId, $locked['held'], true)) {
                continue;
            }

            try {
                CollateralAttacher::attachLocked($loan, $locked['collaterals']->get($collateralId), $row['snapshot_value'], $user);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    "collaterals.{$index}.collateral_id" => Arr::flatten($e->errors()),
                ]);
            }
        }
    }

    /**
     * Keep a restructure's principal from being edited out from under the rules
     * that approved it.
     *
     * `PATCH /loans/{id}` is gated on `loans:update` and a draft/for_review
     * status, neither of which knows anything about restructures. Without this,
     * an application approved at the full outstanding balance could be dropped
     * to ₱1 afterwards and released, writing the entire debt off with no
     * shortfall check, no remarks and no write-off permission ever consulted.
     *
     * A legitimate change is still allowed — it just re-runs the complete rule
     * set and re-freezes the snapshot the write-off is computed from.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function guardRestructurePrincipalEdit(Loan $loan, array $validated, ?User $user): array
    {
        if ($loan->source_loan_id === null || ! array_key_exists('principal_amount', $validated)) {
            return $validated;
        }

        $principal = round((float) $validated['principal_amount'], 2);

        if ($this->toCentavos($principal) === $this->toCentavos((float) $loan->principal_amount)) {
            return $validated;
        }

        if (! $user || ! $user->can('loans:restructure')) {
            throw new AuthorizationException(
                'Changing the principal of a restructure requires the loans:restructure permission.',
            );
        }

        $remarks = $validated['remarks'] ?? $loan->restructure_remarks;
        // There is no `remarks` column on loans; it is only carried here.
        unset($validated['remarks']);

        $source = $this->lockSourceLoan($loan->source_loan_id);

        ['outstanding' => $outstanding, 'shortfall' => $shortfall] =
            $this->assertRestructureInvariants($source, $principal, $remarks, $user);

        $validated['restructure_outstanding'] = $outstanding;
        $validated['restructure_principal'] = $principal;
        $validated['restructure_shortfall'] = $shortfall;
        $validated['restructure_remarks'] = $remarks;

        AuditLogService::log(
            action: 'restructure_principal_changed',
            auditable: $loan,
            oldValues: [
                'principal_amount' => (float) $loan->principal_amount,
                'restructure_outstanding' => (float) $loan->restructure_outstanding,
                'restructure_shortfall' => (float) $loan->restructure_shortfall,
            ],
            newValues: [
                'principal_amount' => $principal,
                'restructure_outstanding' => $outstanding,
                'restructure_shortfall' => $shortfall,
                'remarks' => $remarks,
            ],
            description: "Restructure {$loan->application_number} principal changed to ₱{$principal} "
                ."(outstanding ₱{$outstanding}, shortfall ₱{$shortfall})",
        );

        return $validated;
    }

    /**
     * `draft -> for_review`, and the point at which the loan's approval chain
     * comes into existence.
     *
     * Wrapped in a transaction — it was a bare update before — because the
     * status flip and the chain snapshot have to commit together. A loan that
     * reached `for_review` with no rows in `loan_approval_steps` would be
     * un-actionable by anyone: there would be no pending step for an approver
     * to sign, and no way back to `draft` to re-seed one.
     *
     * `$submitter` is optional and defaults to the authenticated user so the
     * existing callers — the controller, DemoSeeder and a dozen tests — keep
     * working unchanged; the chain's submit step is recorded against whoever
     * it resolves to.
     */
    public function submitForReview(Loan $loan, ?User $submitter = null): Loan
    {
        $this->guardStatus($loan, 'draft', 'submit for review');

        return DB::transaction(function () use ($loan, $submitter) {
            $loan->update(['status' => 'for_review']);

            // Resolved here rather than constructor-injected: LoanApprovalChainService
            // depends on THIS class to run the for_review -> approved transition when
            // the chain reaches its release step, and two constructor-injected services
            // pointing at each other is a container resolution loop.
            app(LoanApprovalChainService::class)->seed($loan, $submitter);

            return $loan;
        });
    }

    public function approve(Loan $loan, User $approver, ?string $remarks): Loan
    {
        $this->guardStatus($loan, 'for_review', 'approve');

        // Dual control on restructures only. A restructure can destroy debt, so
        // the person who raised it must not also be the one who signs it off.
        //
        // super_admin is exempt by product decision: the platform owner operates
        // the system directly and there is no second super_admin to hand the
        // approval to, so the rule would simply block them. Every other role —
        // including the client-side `admin`, which holds every permission —
        // still needs a second person.
        //
        // The exemption has to be spelled out here because this is a plain role
        // check, not an authorization check: `Gate::before` in AppServiceProvider
        // short-circuits Gate/permission calls for super_admin, and never sees
        // this guard.
        //
        // Deliberately NOT applied to ordinary loans: this client is a small
        // cooperative where one person legitimately handles a whole application,
        // and the write-off risk is specific to restructures.
        if ($loan->source_loan_id !== null
            && (int) $loan->created_by === (int) $approver->id
            && ! $approver->hasRole('super_admin')
        ) {
            throw new AuthorizationException(
                'A restructure must be approved by someone other than the person who created it.',
            );
        }

        $this->guardApprovalChainIsClear($loan, $approver);

        $loan->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'approval_remarks' => $remarks,
        ]);

        // Keep the chain agreeing with the loan. Only reachable via the
        // admin/super_admin exemption above or a chainless loan; a chain left
        // mid-flight against an `approved` loan is actionable by nobody and
        // strands the release step out of reach of the UI. Resolved from the
        // container for the same reason `seed()` is: the chain service depends
        // on this one.
        app(LoanApprovalChainService::class)->markApprovedOutOfBand($loan, $approver);

        return $loan;
    }

    /**
     * Refuse a single-shot approval while the loan's approval chain is still
     * mid-flight.
     *
     * `loans:approve` is held by `loan_officer`, so without this a
     * policy-exception loan sitting at step 2 of 10 could be taken straight to
     * `approved` by one person — the chain rows untouched, no signatures, and
     * nothing in the audit trail to show the other eight approvers were never
     * asked. That would make the whole chain decorative.
     *
     * The check is on chain STATE rather than on who is calling, which is what
     * lets LoanApprovalChainService::approve() hand over here without a flag:
     * by the time it does, it has marked the last `approve` step approved, so
     * nothing is outstanding and this passes. A direct call mid-chain still has
     * later steps sitting `waiting` and is refused.
     *
     * `admin` and `super_admin` are exempt, using the same BYPASS_ROLES the
     * chain itself honours in canAct(): they may already act on every step in
     * sequence, so doing it in one call is a shortcut rather than an
     * escalation. Refusing them would also break every fixture in the suite —
     * SetupLendyPH::createReleasedLoan() approves as admin on a loan whose
     * chain is still at step 1.
     *
     * Loans with no chain at all — anything submitted before this shipped, and
     * every imported loan — are unaffected.
     */
    private function guardApprovalChainIsClear(Loan $loan, User $approver): void
    {
        if ($approver->hasAnyRole(LoanApprovalStep::BYPASS_ROLES)) {
            return;
        }

        $round = $loan->approvalSteps()->max('round');

        if ($round === null) {
            return;
        }

        $outstanding = $loan->approvalSteps()
            ->where('round', $round)
            ->where('kind', LoanApprovalStep::KIND_APPROVE)
            ->whereIn('status', [
                LoanApprovalStep::STATUS_WAITING,
                LoanApprovalStep::STATUS_PENDING,
            ])
            ->exists();

        if ($outstanding) {
            throw ValidationException::withMessages([
                'status' => 'This loan is still moving through its approval chain. Sign off on the current step instead.',
            ]);
        }
    }

    public function reject(Loan $loan, User $approver, ?string $remarks): Loan
    {
        $this->guardStatus($loan, 'for_review', 'reject');
        $loan->update([
            'status' => 'rejected',
            'rejection_remarks' => $remarks,
            'rejected_by' => $approver->id,
            'rejected_at' => now(),
        ]);

        return $loan;
    }

    /**
     * Link a co-maker to a loan that is approved and waiting to be released —
     * the release dialog's "Add Co-Maker".
     *
     * Owner decision: whoever may release a loan may complete its co-makers at
     * the counter, but only until the release itself. Once the money is out,
     * who is jointly liable for it is settled, and the app offers no way to
     * add to that list.
     *
     * `$validated` either names one of the borrower's existing, active co-maker
     * records (`co_maker_id`) or describes a new one, which is created on the
     * loan's borrower exactly as the Co-makers tab (CoMakerController::store)
     * creates it, so it also shows up there.
     *
     * The status is re-read under a row lock on the loan, not taken from the
     * route-bound model. release() flips the status with an UPDATE of this same
     * row, so the two serialize: a release that commits first makes this a
     * 422, and one that comes second releases the loan with the co-maker
     * already on it. Without the lock, a co-maker could be linked to a loan
     * that was released a moment earlier.
     *
     * @param  array<string, mixed>  $validated  AddLoanCoMakerRequest payload
     */
    public function addCoMakerAwaitingRelease(Loan $loan, array $validated, User $user): CoMaker
    {
        return DB::transaction(function () use ($loan, $validated, $user) {
            $lockedLoan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (! $lockedLoan->is_releasable) {
                throw ValidationException::withMessages([
                    'status' => ['Co-makers can only be added while the loan is awaiting release.'],
                ]);
            }

            if (isset($validated['co_maker_id'])) {
                // Locked so a concurrent delete or deactivation of the
                // co-maker cannot land between these checks and the attach.
                $coMaker = CoMaker::whereKey($validated['co_maker_id'])
                    ->where('borrower_id', $lockedLoan->borrower_id)
                    ->lockForUpdate()
                    ->first();

                if (! $coMaker) {
                    throw ValidationException::withMessages([
                        'co_maker_id' => ["The selected co-maker is not one of this borrower's co-makers."],
                    ]);
                }

                if ($lockedLoan->coMakers()->whereKey($coMaker->id)->exists()) {
                    throw ValidationException::withMessages([
                        'co_maker_id' => ['This co-maker is already on the loan.'],
                    ]);
                }

                // Linking makes this person jointly liable for the loan, so a
                // co-maker someone has deactivated is refused rather than
                // quietly brought back. Checked under the lock above, so a
                // concurrent deactivation cannot slip in before the attach.
                if ($coMaker->status !== 'active') {
                    throw ValidationException::withMessages([
                        'co_maker_id' => ["This co-maker is inactive. Reactivate them on the borrower's Co-makers tab before adding them to a loan."],
                    ]);
                }
            } else {
                $coMaker = CoMaker::create([
                    ...Arr::except($validated, 'co_maker_id'),
                    'borrower_id' => $lockedLoan->borrower_id,
                ]);
            }

            $lockedLoan->coMakers()->attach($coMaker->id, ['added_by' => $user->id]);

            AuditLogService::log(
                action: 'co_maker_added',
                auditable: $lockedLoan,
                newValues: [
                    'co_maker_id' => $coMaker->id,
                    'co_maker_code' => $coMaker->co_maker_code,
                    'full_name' => $coMaker->full_name,
                    // Whether the record was created by this call, or an
                    // existing co-maker of the borrower's was linked.
                    'co_maker_created' => $coMaker->wasRecentlyCreated,
                ],
                description: "Co-maker {$coMaker->co_maker_code} ({$coMaker->full_name}) added to loan "
                    ."{$lockedLoan->application_number} while awaiting release",
                userId: $user->id,
            );

            // Read back through the relation so the pivot (`added_by`,
            // `created_at`) rides along for CoMakerResource.
            return $lockedLoan->coMakers()->whereKey($coMaker->id)->firstOrFail();
        });
    }

    public function release(Loan $loan, User $releaser, array $insurance = [], ?string $feeFingerprint = null): Loan
    {
        $this->guardStatus($loan, 'approved', 'release');

        // What the loan holds, read before the transaction so that the read
        // takes no lock and fixes no snapshot; it is read again, exactly, under
        // the loan's lock below.
        $heldBefore = CollateralAttacher::heldBy($loan);

        // A deadlock or lock wait timeout is a 409, not a 500. This is the
        // outermost transaction: LoanController::release() and the test
        // helpers are the only callers.
        return LoanWriteTransaction::run(function () use ($loan, $releaser, $insurance, $feeFingerprint, $heldBefore) {
            // THE FIRST STATEMENT IN THIS TRANSACTION, and it has to stay that
            // way. Under REPEATABLE READ the consistent snapshot is fixed by the
            // first plain SELECT, and neither a locking read nor DML moves it —
            // so the conflict read in assertNoDoublePledge() below is answered
            // from whatever the world looked like at that first plain read. Take
            // the collateral lock after it (say, after totalOutstanding() inside
            // closeRestructuredSource()) and the guard silently starts reading a
            // stale world: it locks the right rows and then fails to see a pledge
            // another transaction committed in between. Locking here means no
            // conflicting pledge CAN be committed from here on, so every later
            // snapshot already contains everything the guard needs.
            //
            // The order every collateral write takes (CollateralAttacher): the
            // collateral rows in ONE id-ordered statement, never the pledges
            // themselves, then the loan rows.
            $lockedCollateralIds = CollateralAttacher::lock($heldBefore)->keys()->all();

            // Lock the loan and the loan it restructures, then validate both,
            // before anything is written. Two releases of one loan would
            // otherwise both write a schedule and a journal; two approved
            // restructures of the same source releasing at once would both
            // succeed and the borrower would owe both, for the same balance.
            // Whichever transaction gets the lock second finds the loan already
            // released, or the source already closed, and is rolled back by the
            // throw.
            $lockedSource = $this->lockAndGuardReleaseRows($loan, $lockedCollateralIds);

            // Generate loan account number with row-level lock to prevent race conditions.
            // Order by loan_account_number (not id) so the next number is taken from the
            // highest LN issued — loans can be released out of insertion order.
            $lastLoan = Loan::whereNotNull('loan_account_number')
                ->orderByDesc('loan_account_number')
                ->lockForUpdate()
                ->first();
            $nextNum = $lastLoan ? (int) substr($lastLoan->loan_account_number, 3) + 1 : 1;
            $loanAccountNumber = 'LN-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);

            $loan->update([
                'status' => 'released',
                'loan_account_number' => $loanAccountNumber,
                'released_by' => $releaser->id,
                'released_at' => now(),
            ]);

            // The fee rules configured in Settings, charged here and nowhere
            // else. Until this call existed `Fee` was an orphaned model: the
            // Fees screen wrote rows that nothing in the application ever read,
            // so a 2% product fee could be configured, saved, and silently
            // ignored by every release.
            //
            // POSITION. Two constraints, one of them load-bearing:
            //
            // - BEFORE AutomaticPoster::loanRelease() below, which is not
            //   negotiable. That rule credits cash with `net_proceeds` and
            //   asserts `gross === net + deductions`. Charge fees after it and
            //   the journal says money left the drawer that is still in it —
            //   and the entry BALANCES, so no report would ever show the
            //   difference.
            // - BEFORE applyInsuranceOnRelease(), which is a judgement rather
            //   than an arithmetic requirement. The two amounts are
            //   independent, so the order moves neither total. What it decides
            //   is which guard speaks first when the withholdings overrun the
            //   principal. Fees come from configuration nobody is looking at;
            //   the premium is typed into the dialog by the cashier standing
            //   there. Reporting a fee-schedule problem as "insurance collected
            //   exceeds the loan net proceeds" would send them to correct the
            //   one number that is innocent.
            //
            // A sibling in this namespace, so no import is needed here — the
            // write of `released` to the status above is pinned by line number
            // in CollateralIntegrityGuardsTest's active-status census, and a
            // `use` statement at the top of this file would move it.
            app(LoanReleaseFeeService::class)->applyOnRelease($loan, $feeFingerprint);

            $this->applyInsuranceOnRelease($loan, $insurance);

            // The share capital the deductions withhold, credited to the
            // member's share capital ledger: ONE row, the sum of every Share
            // Capital item, carrying this loan. After applyOnRelease() and
            // applyInsuranceOnRelease(), so it reads the FINAL deductions; and
            // before AutomaticPoster::loanRelease() below, which credits the
            // same amount to the Share Capital equity account, so the books and
            // the member's ledger commit together or not at all. A borrower who
            // is not a member is refused here, with nothing written.
            app(ShareCapitalReleaseCredit::class)->record($loan, $releaser);

            // Persist amortization schedule
            $schedule = $this->buildAmortizationPreview($loan);
            foreach ($schedule as $row) {
                AmortizationSchedule::create([
                    'loan_id' => $loan->id,
                    ...$row,
                ]);
            }

            // Releasing a restructure is the moment the old debt actually moves.
            // Inside this transaction so a failure anywhere above leaves the
            // source loan open and collectible.
            if ($lockedSource) {
                $this->closeRestructuredSource($loan, $lockedSource, $releaser);
            }

            // Close out the approval chain's `release` step, which this action
            // IS. Without it a released loan keeps a pending release step
            // forever and the loan detail page contradicts `loans.status`.
            // Placed before the assertion below so that guard stays the last
            // statement in the transaction, as its comment requires.
            app(LoanApprovalChainService::class)->markReleased($loan, $releaser);

            // `approved` → `released` is a transition INTO Loan::PLEDGING_STATUSES,
            // and it writes no `loan_collaterals` row, so the guard on
            // CollateralController::attach() never sees it. Without this, a loan
            // attached while its collateral's only other holder was inactive
            // becomes a second active holder the moment it is released.
            //
            // The ASSERTION is deliberately the last thing in the transaction,
            // even though its lock is the first. inheritCollaterals() copies the
            // source loan's collateral onto its restructure ON PURPOSE and
            // bypasses the attach guard to do it, so between application and
            // release BOTH loans hold it — asserting before
            // closeRestructuredSource() would reject every restructure release
            // that inherited anything. Here the source is already `restructured`
            // and out of PLEDGING_STATUSES, so what is asserted is the state this
            // transaction is actually about to commit. A throw still rolls the
            // whole release back, status write and loan account number included.
            /*
             * THE BOOKS. Explicit, and inside this transaction on purpose.
             *
             * Position matters twice over:
             *
             * - AFTER applyInsuranceOnRelease(), which withholds the premium by
             *   REWRITING `net_proceeds` and `total_deductions` on the loan.
             *   Post before it and the entry credits cash with money that never
             *   left the drawer and omits the premium from income — and it
             *   balances, so no report would ever show it.
             * - AFTER the loan account number is issued, so the journal's
             *   reference is the LN the borrower's papers carry.
             *
             * It stays BEFORE the assertion below, which that comment requires
             * to be the last statement in this transaction. A throw from here
             * rolls the whole release back — status, loan account number,
             * schedule and all — which is the intended failure: refuse to
             * release money the books cannot record, rather than release it and
             * leave the two disagreeing with nothing to point at the
             * difference.
             *
             * NOT an observer. CsvImportProcessor bulk-creates historical
             * `released` loans without coming through here, and must not post a
             * journal for a disbursement that happened years ago under someone
             * else's books.
             */
            app(AutomaticPoster::class)->loanRelease($loan, $releaser->id);

            CollateralPledgeGuard::assertNoDoublePledge($lockedCollateralIds, $loan);

            return $loan;
        });
    }

    /**
     * Lock the loan being released and the loan it restructures, then refuse
     * to release unless the loan is still approved, holds only collateral that
     * was locked, and (for a restructure) its source is still open and the
     * application still matches what was approved.
     *
     * Both loan rows are locked in ONE statement in id order, after the
     * collateral rows: the order every collateral write takes (see
     * CollateralAttacher). `$loan` is then refreshed from its locked row, so
     * every write below starts from what is committed rather than from what
     * the request read before the lock.
     *
     * A loan no longer approved under the lock was released, voided or sent
     * back by another request after this one read it, and a collateral it
     * holds that was not locked was pledged in between: both are the 409 of a
     * write another one got to first.
     *
     * For a restructure, catches a source already closed by a different
     * restructure, one paid off while this application sat in review, and a
     * principal edited after sign-off. Fails CLOSED: anything unexpected throws
     * and rolls the release back rather than quietly releasing a second loan
     * for the same debt.
     *
     * @param  array<int, int>  $lockedCollateralIds  the collateral rows release() locked
     * @return Loan|null the locked source, or null when this is an ordinary loan
     *
     * @throws HttpResponseException 409 when the loan changed since it was read
     */
    private function lockAndGuardReleaseRows(Loan $loan, array $lockedCollateralIds): ?Loan
    {
        $ids = array_map('intval', array_filter([$loan->getKey(), $loan->source_loan_id]));
        sort($ids);

        $locked = Loan::whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $current = $locked->get($loan->getKey());

        if ($current === null || $current->status !== 'approved') {
            throw LoanWriteTransaction::conflict();
        }

        $loan->setRawAttributes($current->getAttributes(), true);

        if (array_diff(CollateralAttacher::heldBy($loan), $lockedCollateralIds) !== []) {
            throw LoanWriteTransaction::conflict();
        }

        if ($loan->source_loan_id === null) {
            return null;
        }

        $source = $locked->get($loan->source_loan_id);

        if (! $source || ! in_array($source->status, ['released', 'ongoing'], true)) {
            throw ValidationException::withMessages([
                'source_loan_id' => ['The loan being restructured is no longer open, so this restructure cannot be released.'],
            ]);
        }

        // The write-off is derived from the snapshot taken when the terms were
        // approved, so a principal that no longer matches it means the amount of
        // debt being destroyed has moved since sign-off.
        if ($loan->restructure_principal === null) {
            throw ValidationException::withMessages([
                'source_loan_id' => ['This restructure has no approved terms recorded and cannot be released.'],
            ]);
        }

        if ($this->toCentavos((float) $loan->principal_amount) !== $this->toCentavos((float) $loan->restructure_principal)) {
            throw ValidationException::withMessages([
                'principal_amount' => [
                    'The principal has changed since this restructure was approved (approved ₱'
                    .$loan->restructure_principal.', now ₱'.$loan->principal_amount
                    .'). It must be re-approved before release.',
                ],
            ]);
        }

        return $source;
    }

    /**
     * Close the source loan once its restructure is released.
     *
     * This is the ONLY place a loan reaches `restructured`, which is what makes
     * that status mean exactly one thing: closed because its balance moved to a
     * new loan.
     *
     * `$source` is already locked by lockAndGuardReleaseRows().
     */
    private function closeRestructuredSource(Loan $newLoan, Loan $source, User $releaser): void
    {
        // Read for the audit entry only — this is NOT a rollback and nothing
        // below restores it. An audit has flagged it as a third double-pledge
        // path; it is not one. The only status write here moves the source from
        // `released`/`ongoing` INTO `restructured`, which is outside
        // Loan::PLEDGING_STATUSES, so it FREES a collateral rather than taking
        // one. CollateralPledgeGuard is deliberately not called from here.
        $previousStatus = $source->status;
        $closingBalance = $this->totalOutstanding($source);

        $this->clearOpenSchedules($source);

        // From the approved snapshot, NOT from the live balance: penalties keep
        // accruing between approval and release, and charging that drift to the
        // write-off would destroy more debt than anyone signed off on.
        $writeOff = round(max(0, (float) $newLoan->restructure_shortfall), 2);
        $drift = round($closingBalance - (float) $newLoan->restructure_outstanding, 2);

        $source->update([
            'status' => 'restructured',
            'restructured_at' => now(),
            'restructured_balance' => $closingBalance,
            'write_off_amount' => $writeOff,
            'insurance_remaining_balance' => 0,
        ]);

        AuditLogService::log(
            action: 'restructure_closed',
            auditable: $source,
            oldValues: [
                'status' => $previousStatus,
                'outstanding_balance' => $closingBalance,
            ],
            newValues: [
                'status' => 'restructured',
                'restructured_into_loan_id' => $newLoan->id,
                'restructured_into_application_number' => $newLoan->application_number,
                'restructured_into_loan_account_number' => $newLoan->loan_account_number,
                'restructured_balance' => $closingBalance,
                'new_principal' => (float) $newLoan->principal_amount,
                'write_off_amount' => $writeOff,
                // The approved figures the write-off came from, plus how far the
                // real balance drifted from them between approval and release —
                // so the number can be justified later without re-deriving it.
                'approved_outstanding' => (float) $newLoan->restructure_outstanding,
                'approved_shortfall' => (float) $newLoan->restructure_shortfall,
                'balance_drift_since_approval' => $drift,
                // The event destroys debt, so it carries the stated reason.
                'remarks' => $newLoan->restructure_remarks,
                'closed_by' => $releaser->id,
            ],
            description: "Loan closed as restructured — ₱{$closingBalance} moved to loan "
                .($newLoan->loan_account_number ?? $newLoan->application_number)
                .($writeOff > 0 ? ", ₱{$writeOff} written off ({$newLoan->restructure_remarks})" : ''),
        );
    }

    /**
     * Wipe what is still owed on a loan whose balance has moved elsewhere.
     *
     * Rows with nothing collected are DELETED; partially-paid rows are shrunk to
     * exactly what was collected and marked `paid`.
     *
     * Deleting rather than introducing a `void` schedule status is deliberate:
     * `ReportService::loanBalanceSummary()` sums `principal_due` with no status
     * filter and `DashboardService::stats()` counts overdue schedules with no
     * loan-status filter, so a voided row would keep inflating both. Deleting is
     * also what `extendLoan()` and `applyRestructure()` already do. Rewriting
     * the partial rows instead of deleting them is what keeps
     * `SUM(principal_paid)` — every peso the borrower actually paid —
     * reconciling across the closure.
     */
    private function clearOpenSchedules(Loan $loan): void
    {
        $openSchedules = $loan->amortizationSchedules()
            ->reorder()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get();

        foreach ($openSchedules as $schedule) {
            $collected = (float) $schedule->principal_paid
                + (float) $schedule->interest_paid
                + (float) ($schedule->penalty_paid ?? 0);

            if ($collected <= 0) {
                $schedule->delete();

                continue;
            }

            $schedule->update([
                'principal_due' => $schedule->principal_paid,
                'interest_due' => $schedule->interest_paid,
                'penalty_amount' => $schedule->penalty_paid ?? 0,
                // `total_due` excludes penalty everywhere else in this codebase.
                'total_due' => round((float) $schedule->principal_paid + (float) $schedule->interest_paid, 2),
                'remaining_balance' => 0,
                'status' => 'paid',
            ]);
        }
    }

    /**
     * The insurance a release takes, from the dialog's fields: the premium for
     * the percentage, what is collected now, and what is left owing, in
     * centavos. Null when there is no percentage or it is 0.
     *
     * THE premium, for the release preview and for the release alike, so the
     * two cannot quote different figures and the browser computes none:
     * `round(principal × percentage / 100)` to the centavo, half up, in whole
     * centavos (the percentage carries at most two places, so this is exact).
     * A full payment collects the premium; a partial one collects the partial
     * amount, which cannot be more than the premium, and leaves the rest
     * owing.
     *
     * `insurance_premium_amount` is no longer an input. A client that still
     * sends one must send the server's figure: any other is refused, so a
     * premium worked out in the browser can never be the one withheld.
     *
     * @param  array<string, mixed>  $insurance  the release dialog's insurance fields
     * @return array{percentage: float, payment_type: string, premium: int, collected: int, partial: int|null, remaining: int}|null
     *
     * @throws ValidationException on `insurance_premium_amount` or `insurance_partial_amount`
     */
    public function insuranceTerms(Loan $loan, array $insurance): ?array
    {
        $pct = $insurance['insurance_premium_percentage'] ?? null;

        if ($pct === null || $pct === '' || (float) $pct === 0.0) {
            return null;
        }

        $hundredths = (int) round((float) $pct * 100);
        $premium = intdiv($this->toCentavos((float) $loan->principal_amount) * $hundredths + 5000, 10000);
        $label = rtrim(rtrim(number_format($hundredths / 100, 2, '.', ''), '0'), '.');

        $sent = $insurance['insurance_premium_amount'] ?? null;

        if ($sent !== null && $sent !== '' && $this->toCentavos((float) $sent) !== $premium) {
            throw ValidationException::withMessages([
                'insurance_premium_amount' => ["The premium for {$label}% is ".Money::format($premium).'. Reload the release preview.'],
            ]);
        }

        $paymentType = $insurance['insurance_payment_type'] ?? 'full';

        if ($paymentType !== 'partial') {
            return ['percentage' => $hundredths / 100, 'payment_type' => 'full', 'premium' => $premium, 'collected' => $premium, 'partial' => null, 'remaining' => 0];
        }

        $partial = $this->toCentavos((float) ($insurance['insurance_partial_amount'] ?? 0));

        if ($partial > $premium) {
            throw ValidationException::withMessages([
                'insurance_partial_amount' => ['The partial amount cannot be more than the '.Money::format($premium)." premium for {$label}%."],
            ]);
        }

        return ['percentage' => $hundredths / 100, 'payment_type' => 'partial', 'premium' => $premium, 'collected' => $partial, 'partial' => $partial, 'remaining' => $premium - $partial];
    }

    /**
     * The release preview's insurance figures (GET /loans/{id}/release-preview):
     * insuranceTerms() for the dialog's fields, and what the premium collected
     * leaves of the previewed totals. 2dp strings, like that endpoint's own
     * totals. With no insurance, `insurance` is null and the totals are the
     * previewed ones.
     *
     * @param  array<string, mixed>  $insurance
     * @param  string  $totalDeductions  the preview's `total_deductions`
     * @param  string  $netProceeds  the preview's `net_proceeds`
     * @return array{insurance: array{premium_amount: string, collected: string, partial_amount: string|null, remaining_balance: string}|null, total_deductions_after_insurance: string, net_proceeds_after_insurance: string, exceeds_net_proceeds: bool}
     *
     * @throws ValidationException as insuranceTerms() does
     */
    public function insurancePreview(Loan $loan, array $insurance, string $totalDeductions, string $netProceeds): array
    {
        $terms = $this->insuranceTerms($loan, $insurance);
        $collected = $terms['collected'] ?? 0;
        $net = $this->toCentavos((float) $netProceeds);
        $pesos = fn (int $centavos): string => number_format($centavos / 100, 2, '.', '');

        return [
            'insurance' => $terms === null ? null : [
                'premium_amount' => $pesos($terms['premium']),
                'collected' => $pesos($terms['collected']),
                'partial_amount' => $terms['partial'] === null ? null : $pesos($terms['partial']),
                'remaining_balance' => $pesos($terms['remaining']),
            ],
            'total_deductions_after_insurance' => $pesos($this->toCentavos((float) $totalDeductions) + $collected),
            'net_proceeds_after_insurance' => $pesos($net - $collected),
            'exceeds_net_proceeds' => $collected > $net,
        ];
    }

    /**
     * Withhold the insurance premium at release, as insuranceTerms() computes
     * it.
     *
     * @param  array<string, mixed>  $insurance
     */
    private function applyInsuranceOnRelease(Loan $loan, array $insurance): void
    {
        $terms = $this->insuranceTerms($loan, $insurance);

        if ($terms === null) {
            return;
        }

        $pct = $terms['percentage'];
        $paymentType = $terms['payment_type'];
        $premiumAmount = $terms['premium'] / 100;
        $partialAmount = $terms['partial'] === null ? null : $terms['partial'] / 100;
        $remainingBalance = $terms['remaining'] / 100;
        $collected = $terms['collected'] / 100;

        $newNetProceeds = round((float) $loan->net_proceeds - $collected, 2);

        if ($newNetProceeds < 0) {
            throw ValidationException::withMessages([
                'insurance_premium_amount' => ['Insurance collected exceeds the loan net proceeds.'],
            ]);
        }

        $loanUpdates = [
            'insurance_premium_pct' => round((float) $pct, 2),
            'insurance_premium_amount' => $premiumAmount,
            'insurance_payment_type' => $paymentType,
            'insurance_partial_amount' => $partialAmount,
            'insurance_remaining_balance' => $remainingBalance,
            'net_proceeds' => $newNetProceeds,
        ];

        if ($collected > 0) {
            $deductions = $loan->deductions ?? [];
            $deductions[] = [
                'name' => 'Insurance Premium',
                'amount' => round($collected, 2),
                'type' => 'fixed',
                'original_value' => round($collected, 2),
            ];
            $loanUpdates['deductions'] = $deductions;
            $loanUpdates['total_deductions'] = round((float) $loan->total_deductions + $collected, 2);
        }

        $loan->update($loanUpdates);

        AuditLogService::log(
            action: 'release_insurance',
            auditable: $loan,
            newValues: [
                'insurance_premium_percentage' => (float) $pct,
                'insurance_premium_amount' => $premiumAmount,
                'insurance_payment_type' => $paymentType,
                'insurance_partial_amount' => $partialAmount,
                'insurance_remaining_balance' => $remainingBalance,
                'collected_at_release' => $collected,
            ],
            description: "Insurance premium recorded on release ({$paymentType}, ₱{$collected} collected)",
        );
    }

    public function voidLoan(Loan $loan): Loan
    {
        if (in_array($loan->status, ['released', 'ongoing', 'completed', 'defaulted'])) {
            throw ValidationException::withMessages([
                'status' => ['Released, ongoing, completed, or defaulted loans cannot be voided.'],
            ]);
        }

        $loan->update(['status' => 'void']);

        return $loan;
    }

    /**
     * Rebuild deduction inputs from stored deduction items so a recompute
     * applies percentage fees off their original rate (`original_value`),
     * not the previously computed peso amount.
     *
     * @param  array<int, array{name: string, amount: float|int|string, type: string, original_value?: float|int|string}>  $items
     * @return array<int, array{name: string, amount: float|int|string, type: string}>
     */
    private function deductionInputsFrom(array $items): array
    {
        return array_map(static fn (array $item): array => [
            'name' => $item['name'],
            'amount' => $item['original_value'] ?? $item['amount'],
            'type' => $item['type'],
        ], $items);
    }

    public function computeDeductions(float $principalAmount, array $deductions): array
    {
        $total = 0;
        $items = $this->deductionItems($principalAmount, $deductions);

        foreach ($items as $item) {
            $total += $item['amount'];
        }

        $netProceeds = round($principalAmount - $total, 2);

        if ($netProceeds < 0) {
            throw ValidationException::withMessages([
                'deductions' => [self::DEDUCTIONS_EXCEED_PRINCIPAL],
            ]);
        }

        return [
            'items' => $items,
            'total' => $total,
            'net_proceeds' => $netProceeds,
        ];
    }

    /**
     * Each stated deduction as the loan stores it: a percentage of the
     * principal, or a fixed peso figure, each rounded to the centavo, keeping
     * the stated value as `original_value`.
     *
     * computeDeductions() and the form preview both build their items here,
     * so the preview lists exactly what saving the loan would store.
     *
     * @param  list<array{name: string, amount: float|int|string, type: string}>  $deductions
     * @return list<array{name: string, amount: float, type: string, original_value: mixed}>
     */
    private function deductionItems(float $principalAmount, array $deductions): array
    {
        $items = [];

        foreach ($deductions as $deduction) {
            $items[] = [
                'name' => $deduction['name'],
                'amount' => $deduction['type'] === 'percentage'
                    ? round($principalAmount * $deduction['amount'] / 100, 2)
                    : round((float) $deduction['amount'], 2),
                'type' => $deduction['type'],
                'original_value' => $deduction['amount'],
            ];
        }

        return $items;
    }

    /**
     * Where `$periods` periods of `$frequency` from the start end.
     *
     * A PERIOD COUNT, not a loan's term: the CSV importer walks a loan's
     * periods with it. A loan's own maturity date is maturityDateFor(), which
     * reads the term as a length in its `term_unit`. For the term
     * LoanTermSchedule::fromPeriodCount() stores, the two agree.
     */
    public function computeMaturityDate(string $startDate, int $periods, string $frequency): Carbon
    {
        $date = Carbon::parse($startDate);

        return match ($frequency) {
            'daily' => $date->addDays($periods),
            'weekly' => $date->addWeeks($periods),
            'bi_weekly' => $date->addDays($periods * 14),
            'semi_monthly' => $date->addDays($periods * 15),
            // Upon-maturity bullet loans treat `term` as months-until-maturity
            // (single lump-sum payment on the maturity date). Calendar months
            // on the start's day, capped at a shorter month's end.
            'monthly', 'upon_maturity' => LoanTermSchedule::calendarMonthDueDate($date, $periods, $date->day),
        };
    }

    /**
     * A loan's maturity date: its term, a length in `$termUnit`, from the start.
     * See LoanTermSchedule for how the term turns into instalments.
     */
    public function maturityDateFor(string $startDate, int $term, string $termUnit, string $frequency): Carbon
    {
        return LoanTermSchedule::maturityDate(Carbon::parse($startDate), $term, $termUnit, $frequency);
    }

    /**
     * Every figure the loan form and the restructure page show while they are
     * filled in, computed here so the browser only displays them
     * (POST /loans/preview).
     *
     * - `collateral`: CollateralSecurity::summary() of the stated snapshot
     *   values against the principal, the rule the loan page's collaterals
     *   card uses too.
     * - `maturity_date`: where the loan would mature, by the code a create
     *   stores it with. Null until the product, a term inside its range, the
     *   frequency and the start date are known; the principal and the rate
     *   play no part.
     * - `deductions`: what the loan would withhold (formDeductions()). Null
     *   until the product and a principal above 0 are known.
     * - `amortization`: the schedule {@see self::buildAmortizationPreview()}
     *   writes at release, built from an unsaved loan carrying what
     *   createLoan() would store — the product's interest method, term unit and
     *   rate frequency, the form's rate, term, frequency and start date — plus
     *   the share capital build-up added to each period, and the column totals.
     *   Null until the maturity date is known and a principal and a rate above
     *   0 are too.
     *
     * Money is added in whole centavos, never as peso floats. Writes nothing.
     *
     * @param  array{loan_product_id?: int|null, principal_amount?: float|int|string|null, interest_rate?: float|int|string|null, term?: int|null, frequency?: string|null, start_date?: string|null, scb_amount?: float|int|string|null, collaterals?: list<array{collateral_id?: int|null, snapshot_value: float|int|string}>|null, deductions?: list<array{name: string, amount: float|int|string, type: string}>|null}  $input
     * @return array{collateral: array{total_value: float|int, security_status: string, short_by: float|int}, maturity_date: string|null, deductions: array<string, mixed>|null, amortization: array<string, mixed>|null}
     */
    public function formPreview(array $input): array
    {
        $principal = $this->toCentavos((float) ($input['principal_amount'] ?? 0));
        $pledged = 0;

        foreach ($input['collaterals'] ?? [] as $collateral) {
            $pledged += $this->toCentavos((float) $collateral['snapshot_value']);
        }

        $product = isset($input['loan_product_id']) ? LoanProduct::find($input['loan_product_id']) : null;
        $maturity = $this->formMaturity($product, $input);

        return [
            'collateral' => CollateralSecurity::summary($principal, $pledged),
            'maturity_date' => $maturity?->toDateString(),
            'deductions' => $product === null || $principal <= 0 ? null : $this->formDeductions($product, $input, $principal, $maturity),
            'amortization' => $product === null || $maturity === null ? null : $this->formSchedule($product, $input, $principal, $maturity),
        ];
    }

    /**
     * Where the form's loan would mature, or null while the product, a term
     * inside the product's range, the frequency or the start date is missing.
     *
     * A term outside the range is one createLoan() refuses, so it has no
     * maturity and no schedule. This also bounds the work a single preview can
     * ask for.
     *
     * @param  array<string, mixed>  $input
     */
    private function formMaturity(?LoanProduct $product, array $input): ?Carbon
    {
        if ($product === null || empty($input['term']) || empty($input['frequency']) || empty($input['start_date'])) {
            return null;
        }

        $term = (int) $input['term'];

        try {
            $this->assertTermWithinProductRange($term, $product);
        } catch (ValidationException) {
            return null;
        }

        return $this->maturityDateFor($input['start_date'], $term, $product->term_unit->value, $input['frequency']);
    }

    /**
     * The `deductions` half of formPreview(): what the form's loan would
     * withhold, by the code a create and a release run.
     *
     * - `items`: deductionItems() of the stated deductions, or, when none are
     *   stated (absent or null), of the product's own fees
     *   (productFeeDeductions()), exactly as createLoan() falls back. A sent
     *   `[]` is none, as there.
     * - `configured_fees`: what LoanReleaseFeeService adds at release for this
     *   loan, from its product, principal and term
     *   (LoanReleaseFeeService::itemsFor() on an unsaved loan). A fee with a
     *   term condition applies only once the maturity date is known.
     * - the totals, in whole centavos; `net_proceeds` is null and `error`
     *   carries the refusal when the deductions are more than the principal:
     *   the create's message when the stated ones alone are, the release's
     *   when the configured fees take them over. In the body rather than as a
     *   422, so the rest of the preview still shows.
     *
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, stated_total: float|int, configured_fees: list<array<string, mixed>>, configured_total: float|int, total_deductions: float|int, net_proceeds: float|int|null, error: string|null}
     */
    private function formDeductions(LoanProduct $product, array $input, int $principal, ?Carbon $maturity): array
    {
        $items = $this->deductionItems($principal / 100, $input['deductions'] ?? $this->productFeeDeductions($product));
        $stated = 0;

        foreach ($items as $item) {
            $stated += $this->toCentavos((float) $item['amount']);
        }

        $configuredFees = app(LoanReleaseFeeService::class)->itemsFor((new Loan)->forceFill([
            'loan_product_id' => $product->id,
            'principal_amount' => $principal / 100,
            'start_date' => $input['start_date'] ?? null,
            'maturity_date' => $maturity?->toDateString(),
            'deductions' => $items,
        ]));
        $configured = 0;

        foreach ($configuredFees as $fee) {
            $configured += $this->toCentavos((float) $fee['amount']);
        }

        $total = $stated + $configured;

        $error = match (true) {
            $stated > $principal => self::DEDUCTIONS_EXCEED_PRINCIPAL,
            $total > $principal => LoanReleaseFeeService::overrunMessage($configured / 100, $total / 100, $principal / 100),
            default => null,
        };

        return [
            'items' => $items,
            'stated_total' => $stated / 100,
            'configured_fees' => $configuredFees,
            'configured_total' => $configured / 100,
            'total_deductions' => $total / 100,
            'net_proceeds' => $error === null ? ($principal - $total) / 100 : null,
            'error' => $error,
        ];
    }

    /**
     * The amortization half of formPreview(), or null while the principal or
     * the rate is missing.
     *
     * @param  array<string, mixed>  $input
     * @return array{maturity_date: string, interest_method: string, rows: list<array<string, mixed>>, totals: array<string, float|int>}|null
     */
    private function formSchedule(LoanProduct $product, array $input, int $principal, Carbon $maturity): ?array
    {
        $rate = (float) ($input['interest_rate'] ?? 0);

        if ($principal <= 0 || $rate <= 0) {
            return null;
        }

        $loan = (new Loan)->forceFill([
            ...$this->productSnapshot($product),
            'principal_amount' => $principal / 100,
            'interest_rate' => $rate,
            'term' => (int) $input['term'],
            'frequency' => $input['frequency'],
            'start_date' => $input['start_date'],
            'maturity_date' => $maturity,
        ]);

        $scb = $this->toCentavos((float) ($input['scb_amount'] ?? 0));
        $totals = ['principal_due' => 0, 'interest_due' => 0, 'share_capital_build_up' => 0, 'total_payment' => 0];
        $rows = [];

        foreach ($this->buildAmortizationPreview($loan) as $row) {
            $payment = $this->toCentavos((float) $row['total_due']) + $scb;

            $rows[] = [
                'period_number' => $row['period_number'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'total_due' => $row['total_due'],
                'share_capital_build_up' => $scb / 100,
                'total_payment' => $payment / 100,
                'remaining_balance' => $row['remaining_balance'],
            ];

            $totals['principal_due'] += $this->toCentavos((float) $row['principal_due']);
            $totals['interest_due'] += $this->toCentavos((float) $row['interest_due']);
            $totals['share_capital_build_up'] += $scb;
            $totals['total_payment'] += $payment;
        }

        return [
            'maturity_date' => $loan->maturity_date->toDateString(),
            'interest_method' => $product->interest_method,
            'rows' => $rows,
            'totals' => array_map(fn (int $centavos): float|int => $centavos / 100, $totals),
        ];
    }

    /**
     * The loan's schedule, computed and not saved.
     *
     * `$anchorDay` is the day of the month calendar-month instalments fall on,
     * the loan's start day by default. A schedule rebuilt from a later date
     * passes the loan's original day, see LoanTermSchedule::instalments().
     */
    public function buildAmortizationPreview(Loan $loan, ?int $anchorDay = null): array
    {
        // `frequency = upon_maturity` is a bullet loan: a single lump-sum
        // payment at maturity, regardless of which interest method is set.
        if ($loan->frequency === 'upon_maturity') {
            return $this->buildSinglePaymentAtMaturity($loan);
        }

        return match ($loan->interest_method) {
            'straight' => $this->buildStraight($loan, $anchorDay),
            'diminishing' => $this->buildDiminishing($loan, $anchorDay),
            'upon_maturity' => $this->buildUponMaturity($loan, $anchorDay),
        };
    }

    private function buildSinglePaymentAtMaturity(Loan $loan): array
    {
        $principal = (float) $loan->principal_amount;
        $term = $loan->term;

        // A months term accrues a month's interest for each month; a days term
        // accrues for exactly its days.
        $totalInterest = $loan->term_unit === TermUnit::Months
            ? round($principal * $this->rateFor($loan, LoanTermSchedule::DAYS_PER_MONTH) * $term, 2)
            : round($principal * $this->rateFor($loan, $term), 2);

        return [
            [
                'period_number' => 1,
                'due_date' => $loan->maturity_date->toDateString(),
                'principal_due' => round($principal, 2),
                'interest_due' => $totalInterest,
                'total_due' => round($principal + $totalInterest, 2),
                'remaining_balance' => 0,
                'status' => 'pending',
            ],
        ];
    }

    private function buildStraight(Loan $loan, ?int $anchorDay): array
    {
        $principal = (float) $loan->principal_amount;
        $instalments = $this->instalmentsFor($loan, $anchorDay);
        $count = count($instalments);

        $principalPerPeriod = round($principal / $count, 2);

        $schedule = [];
        $balance = $principal;

        foreach ($instalments as $index => $instalment) {
            $i = $index + 1;

            // Flat: interest on the ORIGINAL principal, for the days this
            // instalment covers — a short final instalment is charged less.
            $pDue = ($i === $count) ? $balance : $principalPerPeriod;
            $iDue = round($principal * $this->rateFor($loan, $instalment['days']), 2);
            $balance = round($balance - $pDue, 2);

            $schedule[] = [
                'period_number' => $i,
                'due_date' => $instalment['due_date']->toDateString(),
                'principal_due' => round($pDue, 2),
                'interest_due' => round($iDue, 2),
                'total_due' => round($pDue + $iDue, 2),
                'remaining_balance' => max($balance, 0),
                'status' => 'pending',
            ];
        }

        return $schedule;
    }

    private function buildDiminishing(Loan $loan, ?int $anchorDay): array
    {
        $principal = (float) $loan->principal_amount;
        $instalments = $this->instalmentsFor($loan, $anchorDay);
        $count = count($instalments);

        // PMT at the rate of one full instalment. Every instalment but a short
        // final one is full length.
        $ratePerPeriod = $this->rateFor($loan, $instalments[0]['days']);

        if ($ratePerPeriod > 0) {
            $payment = round($principal * ($ratePerPeriod * pow(1 + $ratePerPeriod, $count))
                / (pow(1 + $ratePerPeriod, $count) - 1), 2);
        } else {
            $payment = round($principal / $count, 2);
        }

        $schedule = [];
        $balance = $principal;

        foreach ($instalments as $index => $instalment) {
            $i = $index + 1;

            $interestDue = round($balance * $this->rateFor($loan, $instalment['days']), 2);
            $principalDue = ($i === $count) ? $balance : round($payment - $interestDue, 2);
            $totalDue = round($principalDue + $interestDue, 2);
            $balance = round($balance - $principalDue, 2);

            $schedule[] = [
                'period_number' => $i,
                'due_date' => $instalment['due_date']->toDateString(),
                'principal_due' => $principalDue,
                'interest_due' => $interestDue,
                'total_due' => $totalDue,
                'remaining_balance' => max($balance, 0),
                'status' => 'pending',
            ];
        }

        return $schedule;
    }

    private function buildUponMaturity(Loan $loan, ?int $anchorDay): array
    {
        $principal = (float) $loan->principal_amount;
        $instalments = $this->instalmentsFor($loan, $anchorDay);
        $count = count($instalments);

        // More than one instalment: interest-only instalments, with the
        // principal due in the last.
        if ($count > 1) {
            $schedule = [];

            foreach ($instalments as $index => $instalment) {
                $i = $index + 1;
                $isLast = ($i === $count);

                $pDue = $isLast ? $principal : 0;
                $iDue = round($principal * $this->rateFor($loan, $instalment['days']), 2);
                $balance = $isLast ? 0 : $principal;

                $schedule[] = [
                    'period_number' => $i,
                    'due_date' => $instalment['due_date']->toDateString(),
                    'principal_due' => round($pDue, 2),
                    'interest_due' => round($iDue, 2),
                    'total_due' => round($pDue + $iDue, 2),
                    'remaining_balance' => round($balance, 2),
                    'status' => 'pending',
                ];
            }

            return $schedule;
        }

        // Single-period: lump sum at maturity
        $totalInterest = round($principal * $this->rateFor($loan, $instalments[0]['days']), 2);

        return [
            [
                'period_number' => 1,
                'due_date' => $instalments[0]['due_date']->toDateString(),
                'principal_due' => $principal,
                'interest_due' => $totalInterest,
                'total_due' => round($principal + $totalInterest, 2),
                'remaining_balance' => 0,
                'status' => 'pending',
            ],
        ];
    }

    /**
     * @return list<array{due_date: Carbon, days: int}>
     */
    private function instalmentsFor(Loan $loan, ?int $anchorDay): array
    {
        return LoanTermSchedule::instalments(
            Carbon::parse($loan->start_date),
            (int) $loan->term,
            $loan->term_unit->value,
            $loan->frequency,
            $anchorDay,
        );
    }

    /**
     * The interest, as a fraction of principal, for an instalment covering
     * `$days` days at the loan's quoted rate.
     */
    private function rateFor(Loan $loan, int $days): float
    {
        return LoanTermSchedule::rateForDays(
            (float) $loan->interest_rate,
            $loan->interest_rate_frequency->value,
            $days,
        );
    }

    private function guardStatus(Loan $loan, string $expected, string $action): void
    {
        if ($loan->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => ["Loan must be in '{$expected}' status to {$action}."],
            ]);
        }
    }
}
