<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanService
{
    /**
     * @param  bool  $enforceMinimumAmount  Set false for a restructure: the new
     *                                      loan's principal is a part-paid balance, which is routinely below the
     *                                      product's `min_amount` and must not be rejected for it. Every other
     *                                      product rule (max_amount, interest-rate range, term range) still applies.
     */
    public function createLoan(array $validated, User $user, bool $enforceMinimumAmount = true): Loan
    {
        $product = LoanProduct::findOrFail($validated['loan_product_id']);
        $borrower = Borrower::findOrFail($validated['borrower_id']);

        $principal = (float) $validated['principal_amount'];
        $interestRate = (float) ($validated['interest_rate'] ?? $product->interest_rate);
        $term = (int) ($validated['term'] ?? $product->term);
        $frequency = $validated['frequency'] ?? $product->frequency;

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

        // Validate interest rate against product range
        $minRate = (float) ($product->min_interest_rate ?? $product->interest_rate);
        $maxRate = (float) $product->interest_rate;
        if ($interestRate < $minRate || $interestRate > $maxRate) {
            throw ValidationException::withMessages([
                'interest_rate' => ["Interest rate must be between {$minRate}% and {$maxRate}% for this product."],
            ]);
        }

        // Validate term against product range
        $minTerm = (int) ($product->min_term ?? 1);
        $maxTerm = (int) ($product->max_term ?? $product->term);
        if ($term < $minTerm || $term > $maxTerm) {
            throw ValidationException::withMessages([
                'term' => ["Term must be between {$minTerm} and {$maxTerm} months for this product."],
            ]);
        }

        // Auto-compute deductions from product fees when not sent by frontend
        $deductions = $validated['deductions'] ?? [];
        if (empty($deductions)) {
            if ((float) $product->processing_fee > 0) {
                $deductions[] = ['name' => 'Processing Fee', 'amount' => (float) $product->processing_fee, 'type' => 'percentage'];
            }
            if ((float) $product->service_fee > 0) {
                $deductions[] = ['name' => 'Service Fee', 'amount' => (float) $product->service_fee, 'type' => 'percentage'];
            }
            if ((float) ($product->notarial_fee ?? 0) > 0) {
                $deductions[] = ['name' => 'Notarial Fee', 'amount' => (float) $product->notarial_fee, 'type' => 'percentage'];
            }
        }

        $deductionResult = $this->computeDeductions($principal, $deductions);

        $loan = Loan::create([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'branch_id' => $borrower->branch_id,
            'interest_rate' => $interestRate,
            'interest_method' => $product->interest_method,
            'term' => $term,
            'frequency' => $frequency,
            'principal_amount' => $principal,
            'purpose' => $validated['purpose'] ?? null,
            'start_date' => $validated['start_date'],
            'maturity_date' => $this->computeMaturityDate(
                $validated['start_date'],
                $term,
                $frequency,
            ),
            'deductions' => $deductionResult['items'],
            'total_deductions' => $deductionResult['total'],
            'net_proceeds' => $deductionResult['net_proceeds'],
            'scb_amount' => $validated['scb_amount'] ?? 0,
            'penalty_rate' => $product->penalty_rate,
            'grace_period_days' => $product->grace_period_days,
            'policy_exception' => $validated['policy_exception'] ?? false,
            'policy_exception_details' => $validated['policy_exception_details'] ?? null,
            'status' => 'draft',
            'created_by' => $user->id,
            'account_officer_id' => $validated['account_officer_id'] ?? null,
        ]);

        // Frontend sends borrower IDs as co-makers — resolve to CoMaker records
        if (! empty($validated['co_maker_ids'])) {
            $coMakerIds = [];
            foreach ($validated['co_maker_ids'] as $id) {
                // Try as co_maker ID first, then as borrower ID
                $coMaker = CoMaker::find($id);
                if ($coMaker) {
                    $coMakerIds[] = $coMaker->id;
                } else {
                    // Look up borrower and find/create a co-maker for them
                    $cmBorrower = Borrower::find($id);
                    if ($cmBorrower) {
                        $coMaker = CoMaker::firstOrCreate(
                            ['borrower_id' => $cmBorrower->id, 'first_name' => $cmBorrower->first_name, 'last_name' => $cmBorrower->last_name],
                            [
                                'address' => $cmBorrower->address,
                                'contact_number' => $cmBorrower->contact_number,
                                'relationship_to_borrower' => 'other',
                                'status' => 'active',
                            ],
                        );
                        $coMakerIds[] = $coMaker->id;
                    }
                }
            }
            if (! empty($coMakerIds)) {
                $loan->coMakers()->sync($coMakerIds);
            }
        }

        return $loan;
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

        // The guards run INSIDE the transaction, opening with a row lock on the
        // source. The one-open-application-at-a-time rule is an exists() check
        // followed by an insert, so without serializing on the source two
        // simultaneous requests would both see "none open" and both create one.
        return DB::transaction(function () use ($sourceLoan, $validated, $user, $principal, $remarks) {
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

            $newLoan = $this->createLoan($validated, $user, enforceMinimumAmount: false);

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
                    $newLoan->coMakers()->sync($inherited);
                }
            }

            $inheritedCollaterals = $this->inheritCollaterals($lockedSource, $newLoan);

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
     * outside Loan::ACTIVE_STATUSES — so a source whose collateral did not come
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
     * @return array<int, int> ids of the collaterals carried over
     */
    private function inheritCollaterals(Loan $source, Loan $newLoan): array
    {
        // Read under a row lock. These are the same `collaterals` rows
        // CollateralController::attach() locks before it decides, so a
        // concurrent attach of one of them serializes behind this copy instead
        // of racing it; the lock also covers the `loan_collaterals` rows, so a
        // detach cannot delete a row out from under the read and leave the new
        // loan holding collateral the source no longer has.
        //
        // No lock-order cycle with attach(): attach waits on a `loans` row only
        // for the foreign-key check when it INSERTS a pivot row, and it only
        // ever inserts for a collateral the target loan does not already hold —
        // disjoint, by construction, from the set copied here.
        $rows = $source->collaterals()
            // Same stated lock order as CollateralPledgeGuard::lockCollateralsOf().
            // A convention rather than a guarantee — see the note there — but the
            // two paths lock the same rows and should ask in the same order.
            ->orderBy('collaterals.id')
            ->lockForUpdate()
            ->get()
            ->mapWithKeys(fn (Collateral $collateral) => [
                $collateral->id => [
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
                    'snapshot_value' => $collateral->pivot->snapshot_value,
                    'attached_at' => now(),
                ],
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $newLoan->collaterals()->attach($rows->all());

        return $rows->keys()->map(fn ($id) => (int) $id)->all();
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

    private function toCentavos(float $amount): int
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
            $validated['maturity_date'] = $this->computeMaturityDate(
                $validated['start_date'],
                $loan->term,
                $loan->frequency,
            );
        }

        $loan->update($validated);

        if (isset($validated['co_maker_ids'])) {
            $loan->coMakers()->sync($validated['co_maker_ids']);
        }

        return $loan;
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

    public function submitForReview(Loan $loan): Loan
    {
        $this->guardStatus($loan, 'draft', 'submit for review');
        $loan->update(['status' => 'for_review']);

        return $loan;
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

        $loan->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'approval_remarks' => $remarks,
        ]);

        return $loan;
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

    public function release(Loan $loan, User $releaser, array $insurance = []): Loan
    {
        $this->guardStatus($loan, 'approved', 'release');

        return DB::transaction(function () use ($loan, $releaser, $insurance) {
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
            // It is also the better lock order: collaterals before `loans`, the
            // same order CollateralController::attach() takes.
            $lockedCollateralIds = CollateralPledgeGuard::lockCollateralsOf($loan);

            // Lock and validate the source up front, before anything is written.
            // Two approved restructures of the same source releasing at once
            // would otherwise both succeed and the borrower would owe both, for
            // the same balance. Whichever transaction gets the lock second finds
            // the source already closed and is rolled back by the throw.
            $lockedSource = $this->lockAndGuardRestructureSource($loan);

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

            $this->applyInsuranceOnRelease($loan, $insurance);

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

            // `approved` → `released` is a transition INTO Loan::ACTIVE_STATUSES,
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
            // and out of ACTIVE_STATUSES, so what is asserted is the state this
            // transaction is actually about to commit. A throw still rolls the
            // whole release back, status write and loan account number included.
            CollateralPledgeGuard::assertNoDoublePledge($lockedCollateralIds, $loan);

            return $loan;
        });
    }

    /**
     * Lock the source loan and refuse to release unless it is still open and
     * the application still matches what was approved.
     *
     * Catches a source already closed by a different restructure, one paid off
     * while this application sat in review, and a principal edited after
     * sign-off. Fails CLOSED: anything unexpected throws and rolls the release
     * back rather than quietly releasing a second loan for the same debt.
     *
     * @return Loan|null the locked source, or null when this is an ordinary loan
     */
    private function lockAndGuardRestructureSource(Loan $loan): ?Loan
    {
        if ($loan->source_loan_id === null) {
            return null;
        }

        $source = Loan::whereKey($loan->source_loan_id)->lockForUpdate()->first();

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
     * `$source` is already locked by lockAndGuardRestructureSource().
     */
    private function closeRestructuredSource(Loan $newLoan, Loan $source, User $releaser): void
    {
        // Read for the audit entry only — this is NOT a rollback and nothing
        // below restores it. An audit has flagged it as a third double-pledge
        // path; it is not one. The only status write here moves the source from
        // `released`/`ongoing` INTO `restructured`, which is outside
        // Loan::ACTIVE_STATUSES, so it FREES a collateral rather than taking
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

    private function applyInsuranceOnRelease(Loan $loan, array $insurance): void
    {
        $pct = $insurance['insurance_premium_percentage'] ?? null;
        if ($pct === null || (float) $pct === 0.0) {
            return;
        }

        $paymentType = $insurance['insurance_payment_type'] ?? 'full';
        $premiumAmount = round((float) ($insurance['insurance_premium_amount'] ?? 0), 2);
        $partialAmount = isset($insurance['insurance_partial_amount'])
            ? round((float) $insurance['insurance_partial_amount'], 2)
            : null;

        if ($paymentType === 'full') {
            $partialAmount = null;
            $remainingBalance = 0.0;
            $collected = $premiumAmount;
        } else {
            $remainingBalance = round($premiumAmount - (float) $partialAmount, 2);
            $collected = (float) $partialAmount;
        }

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
        if (in_array($loan->status, ['released', 'ongoing', 'completed'])) {
            throw ValidationException::withMessages([
                'status' => ['Released, ongoing, or completed loans cannot be voided.'],
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
        $items = [];

        foreach ($deductions as $deduction) {
            $amount = $deduction['type'] === 'percentage'
                ? round($principalAmount * $deduction['amount'] / 100, 2)
                : round((float) $deduction['amount'], 2);

            $items[] = [
                'name' => $deduction['name'],
                'amount' => $amount,
                'type' => $deduction['type'],
                'original_value' => $deduction['amount'],
            ];

            $total += $amount;
        }

        $netProceeds = round($principalAmount - $total, 2);

        if ($netProceeds < 0) {
            throw ValidationException::withMessages([
                'deductions' => ['Total deductions exceed the principal amount.'],
            ]);
        }

        return [
            'items' => $items,
            'total' => $total,
            'net_proceeds' => $netProceeds,
        ];
    }

    public function computeMaturityDate(string $startDate, int $term, string $frequency): Carbon
    {
        $date = Carbon::parse($startDate);

        return match ($frequency) {
            'daily' => $date->addDays($term),
            'weekly' => $date->addWeeks($term),
            'bi_weekly' => $date->addDays($term * 14),
            'semi_monthly' => $date->addDays($term * 15),
            // Upon-maturity bullet loans treat `term` as months-until-maturity
            // (single lump-sum payment on the maturity date).
            'monthly', 'upon_maturity' => $date->addMonths($term),
        };
    }

    public function buildAmortizationPreview(Loan $loan): array
    {
        // `frequency = upon_maturity` is a bullet loan: a single lump-sum
        // payment at maturity, regardless of which interest method is set.
        if ($loan->frequency === 'upon_maturity') {
            return $this->buildSinglePaymentAtMaturity($loan);
        }

        return match ($loan->interest_method) {
            'straight' => $this->buildStraight($loan),
            'diminishing' => $this->buildDiminishing($loan),
            'upon_maturity' => $this->buildUponMaturity($loan),
        };
    }

    private function buildSinglePaymentAtMaturity(Loan $loan): array
    {
        $principal = (float) $loan->principal_amount;
        $rate = (float) $loan->interest_rate / 100; // PH convention: monthly rate
        $term = $loan->term;
        $totalInterest = round($principal * $rate * $term, 2);

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

    private function buildStraight(Loan $loan): array
    {
        $principal = (float) $loan->principal_amount;
        $rate = (float) $loan->interest_rate / 100; // Monthly rate (PH convention)
        $term = $loan->term;

        // PH lending: interest = principal × monthly rate (flat on original principal each period)
        $interestPerPeriod = round($principal * $rate, 2);
        $totalInterest = round($interestPerPeriod * $term, 2);
        $principalPerPeriod = round($principal / $term, 2);

        $schedule = [];
        $balance = $principal;
        $date = Carbon::parse($loan->start_date);

        for ($i = 1; $i <= $term; $i++) {
            $date = $this->addPeriod($date, $loan->frequency);

            $pDue = ($i === $term) ? $balance : $principalPerPeriod;
            $iDue = ($i === $term) ? $totalInterest - ($interestPerPeriod * ($term - 1)) : $interestPerPeriod;
            $balance = round($balance - $pDue, 2);

            $schedule[] = [
                'period_number' => $i,
                'due_date' => $date->toDateString(),
                'principal_due' => round($pDue, 2),
                'interest_due' => round($iDue, 2),
                'total_due' => round($pDue + $iDue, 2),
                'remaining_balance' => max($balance, 0),
                'status' => 'pending',
            ];
        }

        return $schedule;
    }

    private function buildDiminishing(Loan $loan): array
    {
        $principal = (float) $loan->principal_amount;
        $term = $loan->term;
        // PH lending: interest_rate is monthly rate (e.g., 3 = 3% per month)
        $ratePerPeriod = (float) $loan->interest_rate / 100;

        // PMT formula
        if ($ratePerPeriod > 0) {
            $payment = round($principal * ($ratePerPeriod * pow(1 + $ratePerPeriod, $term))
                / (pow(1 + $ratePerPeriod, $term) - 1), 2);
        } else {
            $payment = round($principal / $term, 2);
        }

        $schedule = [];
        $balance = $principal;
        $date = Carbon::parse($loan->start_date);

        for ($i = 1; $i <= $term; $i++) {
            $date = $this->addPeriod($date, $loan->frequency);

            $interestDue = round($balance * $ratePerPeriod, 2);
            $principalDue = ($i === $term) ? $balance : round($payment - $interestDue, 2);
            $totalDue = round($principalDue + $interestDue, 2);
            $balance = round($balance - $principalDue, 2);

            $schedule[] = [
                'period_number' => $i,
                'due_date' => $date->toDateString(),
                'principal_due' => $principalDue,
                'interest_due' => $interestDue,
                'total_due' => $totalDue,
                'remaining_balance' => max($balance, 0),
                'status' => 'pending',
            ];
        }

        return $schedule;
    }

    private function buildUponMaturity(Loan $loan): array
    {
        $principal = (float) $loan->principal_amount;
        $rate = (float) $loan->interest_rate / 100; // Monthly rate (PH convention)
        $term = $loan->term;

        // PH lending: interest = principal × monthly rate per period
        $interestPerPeriod = round($principal * $rate, 2);
        $totalInterest = round($interestPerPeriod * $term, 2);

        // If term > 1, generate interest-only periodic payments + principal at maturity
        if ($term > 1) {
            $schedule = [];
            $date = Carbon::parse($loan->start_date);

            for ($i = 1; $i <= $term; $i++) {
                $date = $this->addPeriod($date, $loan->frequency);
                $isLast = ($i === $term);

                $pDue = $isLast ? $principal : 0;
                $iDue = ($isLast) ? $totalInterest - ($interestPerPeriod * ($term - 1)) : $interestPerPeriod;
                $balance = $isLast ? 0 : $principal;

                $schedule[] = [
                    'period_number' => $i,
                    'due_date' => $date->toDateString(),
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
        $maturityDate = $this->computeMaturityDate(
            $loan->start_date->toDateString(),
            $term,
            $loan->frequency,
        );

        return [
            [
                'period_number' => 1,
                'due_date' => $maturityDate->toDateString(),
                'principal_due' => $principal,
                'interest_due' => $totalInterest,
                'total_due' => round($principal + $totalInterest, 2),
                'remaining_balance' => 0,
                'status' => 'pending',
            ],
        ];
    }

    private function addPeriod(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            'bi_weekly' => $date->copy()->addDays(14),
            'semi_monthly' => $date->copy()->addDays(15),
            // Upon-maturity loans schedule a single bullet payment, so the
            // schedule generator never iterates past period 1 — but treat the
            // "next period" as the maturity date itself (term months out) for
            // any caller that does invoke addPeriod defensively.
            'monthly', 'upon_maturity' => $date->copy()->addMonth(),
        };
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
