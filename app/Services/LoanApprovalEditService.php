<?php

namespace App\Services;

use App\Enums\InterestRateFrequency;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\Approvals\StepMinimum;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Chairman/Chairwoman's edit of a loan application while it waits at their
 * approval step (finish run round 2, decision 14; ClickUp 14zfbg0e89e):
 * PATCH /loans/{loan}/approval-edit, its preview and its state.
 *
 * Who and when. The caller holds `loans:edit_in_approval` (the Chair's role
 * alone, RoleAndPermissionSeeder::CHAIR_ROLE), and the loan is in review,
 * waiting at a step whose role the caller holds, active, and that role is one
 * that carries the permission. A step forwarded to a named user is theirs
 * alone. The chain's admin and super_admin bypass doesn't reach here: only the
 * Chair's own role does. A restructure is refused, since its figures were
 * approved against the loan it replaces.
 *
 * What. The loan form's fields, by its rules (ApprovalEditLoanRequest extends
 * UpdateLoanRequest): the principal, rate, term, frequency, start date,
 * purpose, share capital build-up, deductions, account officer, co-makers and
 * collateral. The member, the product and the policy exception (which decides
 * the chain) stay as they are. Each changed figure is held to the product's
 * limits, as creating the loan would hold it; one the edit leaves alone isn't
 * checked again. A principal that a step left out by its minimum now covers is
 * refused, since the round can't gain a step in place.
 *
 * How. The maturity date, deductions and schedule are worked out by the code
 * POST /loans/preview runs (LoanService::maturityDateFor(),
 * computeDeductions(), buildAmortizationPreview()), from the loan's own terms
 * (interest method, term unit, rate period), so the saved loan is the preview
 * the Chair saw. The write takes the house lock order (LoanWriteTransaction):
 * the collateral rows a list names or the loan holds, then the loan row, then
 * its pending step. Under them the loan must still be at that step and read
 * the version the edit was opened at (version()); otherwise 409, with nothing
 * written. One audit row records every changed field with its old and new
 * value, who and when; collateral attached or detached is recorded by
 * CollateralAttacher as always. The loan stays at the step: the Chair then
 * approves or forwards it with the chain's own endpoints.
 */
class LoanApprovalEditService
{
    public const PERMISSION = 'loans:edit_in_approval';

    public const AUDIT_ACTION = 'loan_edited_in_approval';

    public const STALE = 'This loan changed after you opened it. Reload it to see the changes, then make your edit again.';

    private const NOT_IN_REVIEW = 'This loan isn\'t waiting for approval, so it can\'t be edited here.';

    private const RESTRUCTURE = 'A restructure can\'t be edited during approval: its figures were approved against the loan it replaces. Send it back to the Submit step to change it.';

    /**
     * The product's own fee rates, by the deduction names they go by
     * (LoanService::productFeeDeductions()): rate, minimum and maximum columns,
     * and the fee in words.
     */
    private const FEE_RANGES = [
        'Processing Fee' => ['processing_fee', 'min_processing_fee', 'max_processing_fee', 'processing fee'],
        'Service Fee' => ['service_fee', 'min_service_fee', 'max_service_fee', 'service fee'],
    ];

    /** The schedule row fields POST /loans/preview's `amortization.rows` carry, in its order. */
    private const ROW_FIELDS = ['period_number', 'due_date', 'principal_due', 'interest_due', 'total_due', 'share_capital_build_up', 'total_payment', 'remaining_balance'];

    /** The schedule totals POST /loans/preview's `amortization.totals` carry. */
    private const TOTAL_FIELDS = ['principal_due', 'interest_due', 'share_capital_build_up', 'total_payment'];

    public function __construct(
        private LoanService $loans,
        private LoanApprovalChainService $chain,
        private ApprovalAssignees $assignees,
    ) {}

    /**
     * GET /loans/{loan}/approval-edit: whether `$user` may edit the loan now,
     * why not in words, the pending step, and the version an edit must name.
     *
     * @return array{can_edit: bool, reason: string|null, step: array{id: int, name: string}|null, version: string|null}
     */
    public function state(Loan $loan, User $user): array
    {
        try {
            $step = $this->editableStep($loan, $user);
        } catch (ValidationException $e) {
            return $this->refused((string) Arr::first(Arr::flatten($e->errors())), $loan);
        } catch (AuthorizationException $e) {
            return $this->refused($e->getMessage(), $loan);
        }

        return [
            'can_edit' => true,
            'reason' => null,
            'step' => ['id' => (int) $step->getKey(), 'name' => (string) $step->name],
            'version' => $this->version($loan, $step),
        ];
    }

    /**
     * POST /loans/{loan}/approval-edit/preview: the loan form's figures for the
     * edit as it stands (LoanService::formPreview(), the edit page's preview),
     * with the maturity date and schedule worked out from the loan's own terms,
     * as saving stores them. Writes nothing; the product's limits are the
     * save's to refuse, as on the loan form.
     *
     * @param  array<string, mixed>  $input  ApprovalEditPreviewRequest
     * @return array<string, mixed>
     *
     * @throws ValidationException when the loan isn't in review or is a restructure
     * @throws AuthorizationException when the pending step isn't the caller's
     */
    public function preview(Loan $loan, array $input, User $user): array
    {
        $this->editableStep($loan, $user);

        $values = $this->figures($loan, $input);

        $preview = $this->loans->formPreview([
            'loan_id' => $loan->getKey(),
            'loan_product_id' => $loan->loan_product_id,
            'principal_amount' => $values['principal_amount'],
            'interest_rate' => $values['interest_rate'],
            'term' => $values['term'],
            'frequency' => $values['frequency'],
            'start_date' => $values['start_date'],
            'scb_amount' => $values['scb_amount'],
            'deductions' => $this->statedDeductions($loan, $input),
            ...(isset($input['collaterals']) ? ['collaterals' => $input['collaterals']] : []),
        ], $user);

        // formPreview() reads the term in the product's unit, against the
        // product's range. The loan's own term is kept even once its product
        // counts another unit or has narrowed its range, as saving keeps it, so
        // it still gets its maturity and schedule. A changed term gets them only
        // where formPreview() found the term in the range, and never once the
        // product counts another unit, since saving refuses that change.
        $termKept = $values['term'] === (int) $loan->term;
        $unitMoved = $loan->term_unit !== $loan->loanProduct()->firstOrFail()->term_unit;

        if (! $termKept && $unitMoved) {
            $preview['maturity_date'] = null;
            $preview['amortization'] = null;
        } elseif ($termKept || $preview['maturity_date'] !== null) {
            $projected = $this->projected($loan, $values);
            $preview['maturity_date'] = $projected->maturity_date->toDateString();

            if (BigDecimal::of($values['principal_amount'])->isPositive() && BigDecimal::of($values['interest_rate'])->isPositive()) {
                $preview['amortization'] = $this->schedule($projected);
            }
        }

        return $preview;
    }

    /**
     * PATCH /loans/{loan}/approval-edit.
     *
     * @param  array<string, mixed>  $validated  ApprovalEditLoanRequest
     * @return array{loan: Loan, step: LoanApprovalStep, changed: bool, amortization: array<string, mixed>}
     *
     * @throws ValidationException 422 when the loan isn't in review, is a restructure, or a figure is outside the product's limits
     * @throws AuthorizationException 403 when the pending step isn't the caller's
     */
    public function edit(Loan $loan, array $validated, User $user): array
    {
        $step = $this->editableStep($loan, $user);

        $collaterals = $validated['collaterals'] ?? null;
        $listedIds = $collaterals === null ? [] : array_map(static fn (array $row): int => (int) $row['collateral_id'], $collaterals);
        // What the loan holds, read before the transaction for lockForList()
        // to lock, and read again there under the loan's lock.
        $heldBefore = $collaterals === null ? [] : CollateralAttacher::heldBy($loan);

        $write = function () use ($loan, $validated, $user, $step, $collaterals, $listedIds, $heldBefore): array {
            // The collateral rows first, in one id-ordered statement, then the
            // loan row (lockForList() takes both), then the pending step.
            $lockedCollaterals = $collaterals === null ? null : CollateralAttacher::lockForList($loan, $listedIds, $heldBefore);
            $locked = Loan::whereKey($loan->getKey())->lockForUpdate()->first();
            $pending = $locked === null ? null : $this->pendingStep($locked, locking: true);

            if ($locked === null
                || $locked->status !== 'for_review'
                || $pending === null
                || (int) $pending->getKey() !== (int) $step->getKey()
                || ! hash_equals($this->version($locked, $pending), (string) $validated['version'])
            ) {
                throw LoanWriteTransaction::conflict(self::STALE);
            }

            $this->assertStepIsTheirs($locked, $pending, $user);

            [$old, $new, $coMakerIds] = $this->changes($locked, $pending, $validated);

            if ($new !== []) {
                $this->save($locked, $new, $coMakerIds, $user);
            }

            $collateralsChanged = $lockedCollaterals !== null
                && $this->reconcileCollaterals($locked, $collaterals, $lockedCollaterals, $user);

            if ($new !== []) {
                AuditLogService::log(
                    action: self::AUDIT_ACTION,
                    auditable: $locked,
                    oldValues: $old,
                    newValues: $new,
                    description: $this->describe($locked, $pending, $old, $new),
                    userId: (int) $user->getKey(),
                );
            }

            return [
                'loan' => $locked,
                'step' => $pending,
                'changed' => $new !== [] || $collateralsChanged,
                'amortization' => $this->schedule($locked),
            ];
        };

        // With a list this is a collateral write, so a deadlock on its locks
        // is a 409 like the attach endpoint's; without one it is a loan write.
        return $collaterals === null ? LoanWriteTransaction::run($write) : CollateralWriteTransaction::run($write);
    }

    /**
     * The pending step `$user` may edit the loan at.
     *
     * @throws ValidationException 422 when the loan isn't in review, or is a restructure
     * @throws AuthorizationException 403 when the pending step isn't the caller's
     */
    public function editableStep(Loan $loan, User $user): LoanApprovalStep
    {
        if ($loan->status !== 'for_review') {
            throw ValidationException::withMessages(['status' => [self::NOT_IN_REVIEW]]);
        }

        if ($loan->isRestructure()) {
            throw ValidationException::withMessages(['loan' => [self::RESTRUCTURE]]);
        }

        $step = $this->pendingStep($loan);

        if ($step === null) {
            throw ValidationException::withMessages(['status' => [self::NOT_IN_REVIEW]]);
        }

        $this->assertStepIsTheirs($loan, $step, $user);

        return $step;
    }

    /**
     * The refusal for a step that isn't the caller's approve step, in words.
     */
    private static function notYourStep(LoanApprovalStep $step): AuthorizationException
    {
        return new AuthorizationException("You can edit this loan only while it waits at your approval step. It is at the \"{$step->name}\" step.");
    }

    /**
     * What an edit must name to be saved: a fingerprint of the loan's row as
     * stored (every column, `updated_at` included), its co-makers and
     * collateral, and the step it waits at. Any change to any of them since
     * the edit was opened makes it stale.
     */
    public function version(Loan $loan, LoanApprovalStep $step): string
    {
        $row = $loan->getAttributes();
        ksort($row);

        return hash('sha256', json_encode([
            'loan' => $row,
            'co_makers' => DB::table('co_maker_loan')->where('loan_id', $loan->getKey())->orderBy('co_maker_id')->pluck('co_maker_id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'collaterals' => CollateralAttacher::heldBy($loan),
            'step' => [(int) $step->getKey(), (int) $step->round, $step->assigned_to === null ? null : (int) $step->assigned_to],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{can_edit: false, reason: string, step: array{id: int, name: string}|null, version: null}
     */
    private function refused(string $reason, Loan $loan): array
    {
        $step = $loan->status === 'for_review' ? $this->pendingStep($loan) : null;

        return [
            'can_edit' => false,
            'reason' => $reason,
            'step' => $step === null ? null : ['id' => (int) $step->getKey(), 'name' => (string) $step->name],
            'version' => null,
        ];
    }

    /**
     * The first pending step of the loan's current round, the one it waits at.
     */
    private function pendingStep(Loan $loan, bool $locking = false): ?LoanApprovalStep
    {
        return LoanApprovalStep::query()
            ->where('loan_id', $loan->getKey())
            ->where('round', $this->chain->currentRound($loan))
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderBy('step_order')
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * The step is `$user`'s to edit at: an approve step (a Submit step is the
     * loan processor's to revise, and a Release step comes after approval),
     * forwarded to them, or to nobody (or to someone who could no longer be
     * given it), and its role is one they hold, active, that carries the
     * permission.
     *
     * @throws AuthorizationException
     */
    private function assertStepIsTheirs(Loan $loan, LoanApprovalStep $step, User $user): void
    {
        if ($step->kind !== LoanApprovalStep::KIND_APPROVE) {
            throw self::notYourStep($step);
        }

        $assignee = $this->assignees->effectiveAssignee($step, $loan);

        if ($assignee !== null && (int) $assignee->getKey() !== (int) $user->getKey()) {
            throw new AuthorizationException("This step was forwarded to {$assignee->full_name}, so only they can edit the loan now.");
        }

        $role = $user->activeRoles()->first(fn (Role $role): bool => $role->name === $step->role);

        if ($role === null || ! $role->permissions()->where('name', self::PERMISSION)->exists()) {
            throw self::notYourStep($step);
        }
    }

    /**
     * The loan's terms as the edit leaves them: each sent figure, else the
     * stored one.
     *
     * @param  array<string, mixed>  $input
     * @return array{principal_amount: string, interest_rate: string, term: int, frequency: string, start_date: string, scb_amount: string}
     */
    private function figures(Loan $loan, array $input): array
    {
        return [
            'principal_amount' => self::pesos($input['principal_amount'] ?? $loan->principal_amount),
            'interest_rate' => self::rate($input['interest_rate'] ?? $loan->interest_rate),
            'term' => (int) ($input['term'] ?? $loan->term),
            'frequency' => (string) ($input['frequency'] ?? $loan->frequency),
            'start_date' => (string) ($input['start_date'] ?? $loan->start_date?->toDateString()),
            'scb_amount' => self::pesos($input['scb_amount'] ?? $loan->scb_amount ?? 0),
        ];
    }

    /**
     * The deduction inputs the edit states: the sent list (`[]` is none), or,
     * when none is sent, the loan's own items as they were stated, so they are
     * worked out again on the edited terms, as PUT /loans/{id} does.
     *
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function statedDeductions(Loan $loan, array $input): array
    {
        if (isset($input['deductions'])) {
            return array_values($input['deductions']);
        }

        return array_map(static fn (array $item): array => [
            'name' => $item['name'],
            'amount' => $item['original_value'] ?? $item['amount'],
            'type' => $item['type'],
            ...FeeAmount::formulaFields((string) $item['type'], $item),
        ], array_values(is_array($loan->deductions) ? $loan->deductions : []));
    }

    /**
     * An unsaved copy of the loan carrying the edited terms and the maturity
     * date they give, for the schedule. The loan's own interest method, term
     * unit and rate period stay.
     *
     * @param  array{principal_amount: string, interest_rate: string, term: int, frequency: string, start_date: string, scb_amount: string}  $values
     */
    private function projected(Loan $loan, array $values): Loan
    {
        return (clone $loan)->forceFill([
            ...$values,
            'maturity_date' => $this->loans->maturityDateFor($values['start_date'], $values['term'], $loan->term_unit->value, $values['frequency'])->toDateString(),
        ]);
    }

    /**
     * The loan's schedule as POST /loans/preview's `amortization` states it:
     * the rows and totals of LoanService::buildAmortizationPreview() with the
     * share capital build-up, the code GET /loans/{id}/amortization-preview
     * reads too.
     *
     * @return array{maturity_date: string, interest_method: string, rows: list<array<string, mixed>>, totals: array<string, float|int>}
     */
    private function schedule(Loan $loan): array
    {
        $schedule = $this->loans->withShareCapitalBuildUp($this->loans->buildAmortizationPreview($loan), (float) $loan->scb_amount);

        return [
            'maturity_date' => $loan->maturity_date->toDateString(),
            'interest_method' => (string) $loan->interest_method,
            'rows' => array_map(
                static fn (array $row): array => array_combine(self::ROW_FIELDS, array_map(static fn (string $field): mixed => $row[$field], self::ROW_FIELDS)),
                $schedule['rows'],
            ),
            'totals' => Arr::only($schedule['totals'], self::TOTAL_FIELDS),
        ];
    }

    /**
     * Every field the edit changes, old and new, as the audit row keeps them,
     * after the product's limits are checked. A figure the edit leaves alone
     * isn't checked again, so a loan whose product has since narrowed its
     * range stays editable. The maturity date is worked out again only when
     * the start, term or frequency moves, and the deductions only when the
     * principal, term or deductions do.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<int>|null} old and new values by field, and the co-maker record ids when they change
     *
     * @throws ValidationException on the field outside the product's limits
     */
    private function changes(Loan $loan, LoanApprovalStep $step, array $input): array
    {
        $product = $loan->loanProduct()->firstOrFail();
        $stored = $this->figures($loan, []);
        $edited = $this->figures($loan, $input);
        $old = [];
        $new = [];

        if ($edited['principal_amount'] !== $stored['principal_amount']) {
            $this->assertPrincipalWithin($edited['principal_amount'], $product);
            $this->assertNoLeftOutStepApplies($loan, $step, $edited['principal_amount']);
            [$old['principal_amount'], $new['principal_amount']] = [(float) $stored['principal_amount'], (float) $edited['principal_amount']];
        }

        if ($edited['interest_rate'] !== $stored['interest_rate']) {
            $this->assertSameRatePeriod($loan, $product);
            $this->assertRateWithin($edited['interest_rate'], $product);
            [$old['interest_rate'], $new['interest_rate']] = [(float) $stored['interest_rate'], (float) $edited['interest_rate']];
        }

        if ($edited['term'] !== $stored['term']) {
            $this->assertSameTermUnit($loan, $product);
            $this->assertTermWithin($edited['term'], $product);
            [$old['term'], $new['term']] = [$stored['term'], $edited['term']];
        }

        if ($edited['frequency'] !== $stored['frequency']) {
            $this->assertFrequencyOffered($edited['frequency'], $product);
            [$old['frequency'], $new['frequency']] = [$stored['frequency'], $edited['frequency']];
        }

        if ($edited['start_date'] !== $stored['start_date']) {
            [$old['start_date'], $new['start_date']] = [$stored['start_date'], $edited['start_date']];
        }

        if (isset($new['start_date']) || isset($new['term']) || isset($new['frequency'])) {
            $maturity = $this->loans->maturityDateFor($edited['start_date'], $edited['term'], $loan->term_unit->value, $edited['frequency'])->toDateString();

            if ($maturity !== $loan->maturity_date?->toDateString()) {
                [$old['maturity_date'], $new['maturity_date']] = [$loan->maturity_date?->toDateString(), $maturity];
            }
        }

        if (array_key_exists('purpose', $input) && $input['purpose'] !== $loan->purpose) {
            [$old['purpose'], $new['purpose']] = [$loan->purpose, $input['purpose']];
        }

        if ($edited['scb_amount'] !== $stored['scb_amount']) {
            $this->assertBuildUpWithin($edited['scb_amount'], $product);
            [$old['scb_amount'], $new['scb_amount']] = [(float) $stored['scb_amount'], (float) $edited['scb_amount']];
        }

        $deductionsSent = isset($input['deductions']);

        if ($deductionsSent) {
            $this->assertFeeRatesWithin($input['deductions'], is_array($loan->deductions) ? $loan->deductions : [], $product);
        }

        if ($deductionsSent || isset($new['principal_amount']) || isset($new['term'])) {
            $result = $this->loans->computeDeductions(
                (float) $edited['principal_amount'],
                $this->statedDeductions($loan, $input),
                FeeAmount::termMonths($edited['term'], $loan->term_unit),
            );

            if (self::canonical($result['items']) !== self::canonical(is_array($loan->deductions) ? $loan->deductions : [])) {
                [$old['deductions'], $new['deductions']] = [$loan->deductions ?? [], $result['items']];
            }

            foreach (['total_deductions' => $result['total'], 'net_proceeds' => $result['net_proceeds']] as $column => $value) {
                if (LoanService::toCentavos((float) $value) !== LoanService::toCentavos((float) $loan->{$column})) {
                    [$old[$column], $new[$column]] = [(float) $loan->{$column}, round((float) $value, 2)];
                }
            }
        }

        if (array_key_exists('account_officer_id', $input)) {
            $officer = $input['account_officer_id'] === null ? null : (int) $input['account_officer_id'];
            $storedOfficer = $loan->account_officer_id === null ? null : (int) $loan->account_officer_id;

            if ($officer !== $storedOfficer) {
                [$old['account_officer_id'], $new['account_officer_id']] = [$storedOfficer, $officer];
            }
        }

        $coMakerIds = null;

        if (isset($input['co_maker_ids'])) {
            $current = $loan->coMakers()->orderBy('co_makers.id')->get();
            $ids = $this->coMakerIdsForMembers($input['co_maker_ids']);
            $sorted = $ids;
            sort($sorted);

            if ($sorted !== $current->modelKeys()) {
                $coMakerIds = $ids;
                $old['co_makers'] = $current->map(fn (CoMaker $coMaker): string => (string) $coMaker->full_name)->all();
                $new['co_makers'] = CoMaker::query()->whereKey($ids)->orderBy('id')->get()->map(fn (CoMaker $coMaker): string => (string) $coMaker->full_name)->all();
            }
        }

        return [$old, $new, $coMakerIds];
    }

    /**
     * Write the changed columns and co-makers. The loan's own `updated` audit
     * row is left out: the edit's one row records every field. Only a new
     * co-maker link is stamped with who added it, as LoanService::syncCoMakers()
     * stamps it.
     *
     * @param  array<string, mixed>  $new
     * @param  list<int>|null  $coMakerIds
     */
    private function save(Loan $loan, array $new, ?array $coMakerIds, User $user): void
    {
        $columns = Arr::except($new, ['co_makers']);

        if ($columns !== []) {
            AuditLogService::withoutModelAuditing(fn () => $loan->update($columns));
        }

        if ($coMakerIds !== null) {
            $changes = $loan->coMakers()->sync($coMakerIds);

            if ($changes['attached'] !== []) {
                $loan->coMakers()->updateExistingPivot($changes['attached'], ['added_by' => $user->getKey()]);
            }
        }
    }

    /**
     * Make the loan's collateral exactly the listed set, as PUT /loans/{id}
     * does: held and unlisted is detached, listed and not held is attached at
     * the server's value, held and listed is left as pledged. Each attach and
     * detach writes its own audit row (CollateralAttacher).
     *
     * @param  list<array{collateral_id: int|string}>  $collaterals
     * @param  array{held: list<int>, collaterals: Collection<int, Collateral>}  $locked
     *
     * @throws ValidationException on `collaterals.{index}.collateral_id`
     */
    private function reconcileCollaterals(Loan $loan, array $collaterals, array $locked, User $user): bool
    {
        $listedIds = array_map(static fn (array $row): int => (int) $row['collateral_id'], $collaterals);
        $changed = false;

        foreach (array_diff($locked['held'], $listedIds) as $collateralId) {
            CollateralAttacher::detachLocked($loan, $collateralId, $user);
            $changed = true;
        }

        foreach ($collaterals as $index => $row) {
            $collateralId = (int) $row['collateral_id'];

            if (in_array($collateralId, $locked['held'], true)) {
                continue;
            }

            try {
                CollateralAttacher::attachLocked($loan, $locked['collaterals']->get($collateralId), $user);
                $changed = true;
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    "collaterals.{$index}.collateral_id" => Arr::flatten($e->errors()),
                ]);
            }
        }

        return $changed;
    }

    /**
     * The co-maker record for each member id, made on the member's first pick,
     * as LoanService::coMakerIdsForMembers() reads the loan form's co-maker
     * picker: member ids only, never a co-maker record id.
     *
     * @param  array<int, int|string>  $memberIds
     * @return list<int>
     */
    private function coMakerIdsForMembers(array $memberIds): array
    {
        $ids = [];

        foreach ($memberIds as $memberId) {
            $member = Borrower::find($memberId);

            if ($member !== null) {
                $ids[] = (int) CoMaker::firstOrCreate(
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
                )->getKey();
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @throws ValidationException on `principal_amount`
     */
    private function assertPrincipalWithin(string $principal, LoanProduct $product): void
    {
        $amount = BigDecimal::of($principal);
        $minimum = BigDecimal::of((string) ($product->min_amount ?? '0'));
        $maximum = BigDecimal::of((string) ($product->max_amount ?? '0'));

        if ($minimum->isPositive() && $amount->isLessThan($minimum)) {
            throw ValidationException::withMessages([
                'principal_amount' => ['The principal must be at least '.StepMinimum::pesos((string) $minimum).' for this product.'],
            ]);
        }

        if ($maximum->isPositive() && $amount->isGreaterThan($maximum)) {
            throw ValidationException::withMessages([
                'principal_amount' => ['The principal can be at most '.StepMinimum::pesos((string) $maximum).' for this product.'],
            ]);
        }
    }

    /**
     * A step the chain left out by its minimum can't join the round in place,
     * so a principal that reaches its minimum is refused, in the chain's own
     * words (LoanApprovalChainService refuses the sign-off the same way).
     *
     * @throws ValidationException on `principal_amount`
     */
    private function assertNoLeftOutStepApplies(Loan $loan, LoanApprovalStep $step, string $principal): void
    {
        $chain = is_array($loan->approval_chain) ? $loan->approval_chain : null;

        if ($chain === null) {
            return;
        }

        $inRound = LoanApprovalStep::query()->where('loan_id', $loan->getKey())->where('round', $step->round)->pluck('step_id')->all();

        foreach ($chain as $configured) {
            if (! in_array($configured['id'] ?? null, $inRound, true) && StepMinimum::applies($configured, $principal)) {
                throw ValidationException::withMessages([
                    'principal_amount' => ['A principal of '.StepMinimum::pesos($principal).' brings in the "'.($configured['name'] ?? '').'" step (from '
                        .StepMinimum::pesos((string) $configured[StepMinimum::KEY]).'), which this loan\'s approval chain left out. Send the loan back to the Submit step so its approval chain is set again.'],
                ]);
            }
        }
    }

    /**
     * A rate is checked against its product's range in the product's rate
     * period, which is the loan's own unless the product has moved to another
     * since the loan was made. Then a new rate would be stored per the loan's
     * period but checked per the product's, so it can't change here.
     *
     * @throws ValidationException on `interest_rate`
     */
    private function assertSameRatePeriod(Loan $loan, LoanProduct $product): void
    {
        if ($loan->interest_rate_frequency === $product->interest_rate_frequency) {
            return;
        }

        throw ValidationException::withMessages([
            'interest_rate' => ['This loan\'s rate is quoted '.self::ratePeriod($loan->interest_rate_frequency)
                .', but its product now quotes rates '.self::ratePeriod($product->interest_rate_frequency)
                .', so the rate can\'t be changed during approval.'],
        ]);
    }

    /**
     * A term is checked against its product's range in the product's unit,
     * which is the loan's own unless the product has moved to another since
     * the loan was made. Then the term can't change here.
     *
     * @throws ValidationException on `term`
     */
    private function assertSameTermUnit(Loan $loan, LoanProduct $product): void
    {
        if ($loan->term_unit === $product->term_unit) {
            return;
        }

        throw ValidationException::withMessages([
            'term' => ["This loan's term is counted in {$loan->term_unit->value}, but its product now counts {$product->term_unit->value}, so the term can't be changed during approval."],
        ]);
    }

    /**
     * The product's minimum rate (its rate when it has none) up to its rate.
     *
     * @throws ValidationException on `interest_rate`
     */
    private function assertRateWithin(string $rate, LoanProduct $product): void
    {
        $minimum = BigDecimal::of((string) ($product->min_interest_rate ?? $product->interest_rate));
        $maximum = BigDecimal::of((string) $product->interest_rate);
        $value = BigDecimal::of($rate);

        if ($value->isLessThan($minimum) || $value->isGreaterThan($maximum)) {
            throw ValidationException::withMessages([
                'interest_rate' => ['The interest rate must be between '.self::percent((string) $minimum).' and '.self::percent((string) $maximum).' '
                    .self::ratePeriod($product->interest_rate_frequency).' for this product.'],
            ]);
        }
    }

    /**
     * The product's minimum term (1 when it has none) up to its maximum (its
     * term when it has none), in its own unit.
     *
     * @throws ValidationException on `term`
     */
    private function assertTermWithin(int $term, LoanProduct $product): void
    {
        $minimum = (int) ($product->min_term ?? 1);
        $maximum = (int) ($product->max_term ?? $product->term);

        if ($term < $minimum || $term > $maximum) {
            throw ValidationException::withMessages([
                'term' => ["The term must be between {$minimum} and {$maximum} {$product->term_unit->value} for this product."],
            ]);
        }
    }

    /**
     * One of the frequencies the product offers (its default when it lists none).
     *
     * @throws ValidationException on `frequency`
     */
    private function assertFrequencyOffered(string $frequency, LoanProduct $product): void
    {
        $offered = is_array($product->frequencies) && $product->frequencies !== [] ? $product->frequencies : [$product->frequency];

        if (! in_array($frequency, $offered, true)) {
            throw ValidationException::withMessages([
                'frequency' => ['The payment frequency must be '.implode(' or ', array_map(self::frequencyWords(...), $offered)).' for this product.'],
            ]);
        }
    }

    /**
     * The loan form's build-up rule: required when the product says so, and
     * inside its minimum and maximum when it sets them.
     *
     * @throws ValidationException on `scb_amount`
     */
    private function assertBuildUpWithin(string $scb, LoanProduct $product): void
    {
        $amount = BigDecimal::of($scb);
        $minimum = $product->min_scb !== null && BigDecimal::of((string) $product->min_scb)->isPositive() ? (string) BigDecimal::of((string) $product->min_scb) : null;
        $maximum = $product->max_scb !== null && BigDecimal::of((string) $product->max_scb)->isPositive() ? (string) BigDecimal::of((string) $product->max_scb) : null;

        if ($amount->isZero()) {
            if ($product->scb_required) {
                throw ValidationException::withMessages([
                    'scb_amount' => [$minimum === null
                        ? 'This product needs a share capital build-up.'
                        : 'This product needs a share capital build-up of at least '.StepMinimum::pesos($minimum).'.'],
                ]);
            }

            return;
        }

        $outside = ($minimum !== null && $amount->isLessThan($minimum)) || ($maximum !== null && $amount->isGreaterThan($maximum));

        if (! $outside) {
            return;
        }

        throw ValidationException::withMessages([
            'scb_amount' => [match (true) {
                $minimum !== null && $maximum !== null => 'The share capital build-up must be between '.StepMinimum::pesos($minimum).' and '.StepMinimum::pesos($maximum).' for this product.',
                $minimum !== null => 'The share capital build-up must be at least '.StepMinimum::pesos($minimum).' for this product.',
                default => 'The share capital build-up can be at most '.StepMinimum::pesos((string) $maximum).' for this product.',
            }],
        ]);
    }

    /**
     * The loan form's fee rule: the processing and service fee rates sit inside
     * the product's minimum (0 when it has none) and maximum (its rate when it
     * has none), when it has a maximum above 0. A fee whose minimum is above 0
     * must be stated, as a percentage: left out or as a fixed amount, it is
     * refused. A rate the edit leaves as it was stored isn't checked again.
     *
     * @param  array<int, array<string, mixed>>  $sent
     * @param  array<int, array<string, mixed>>  $stored
     *
     * @throws ValidationException on `deductions`
     */
    private function assertFeeRatesWithin(array $sent, array $stored, LoanProduct $product): void
    {
        foreach (self::FEE_RANGES as $name => [$rateColumn, $minimumColumn, $maximumColumn, $words]) {
            $maximum = BigDecimal::of((string) ($product->{$maximumColumn} ?? $product->{$rateColumn} ?? '0'));

            if (! $maximum->isPositive()) {
                continue;
            }

            $minimum = BigDecimal::of((string) ($product->{$minimumColumn} ?? '0'));
            $storedRate = collect($stored)->first(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'percentage' && ($item['name'] ?? null) === $name);
            $range = "The {$words} must be between ".self::percent((string) $minimum).' and '.self::percent((string) $maximum).' for this product.';
            $statedAsARate = collect($sent)->contains(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'percentage' && ($item['name'] ?? null) === $name);

            if ($minimum->isPositive() && ! $statedAsARate) {
                throw ValidationException::withMessages(['deductions' => [$range]]);
            }

            foreach ($sent as $item) {
                if (($item['type'] ?? null) !== 'percentage' || ($item['name'] ?? null) !== $name || ! is_numeric($item['amount'] ?? null)) {
                    continue;
                }

                $rate = BigDecimal::of((string) $item['amount']);

                if (is_numeric($storedRate['original_value'] ?? $storedRate['amount'] ?? null) && $rate->isEqualTo((string) ($storedRate['original_value'] ?? $storedRate['amount']))) {
                    continue;
                }

                if ($rate->isLessThan($minimum) || $rate->isGreaterThan($maximum)) {
                    throw ValidationException::withMessages(['deductions' => [$range]]);
                }
            }
        }
    }

    /**
     * The audit row's description, in words: the step, then each change.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function describe(Loan $loan, LoanApprovalStep $step, array $old, array $new): string
    {
        $parts = [];
        $per = self::ratePeriod($loan->interest_rate_frequency);
        $unit = $loan->term_unit->value;

        foreach (array_keys($new) as $field) {
            $was = $old[$field] ?? null;
            $now = $new[$field];

            $parts[] = match ($field) {
                'principal_amount' => 'principal '.self::money($was).' → '.self::money($now),
                'interest_rate' => 'interest rate '.self::percent((string) $was).' → '.self::percent((string) $now)." {$per}",
                'term' => "term {$was} {$unit} → {$now} {$unit}",
                'frequency' => 'payment frequency '.self::frequencyWords((string) $was).' → '.self::frequencyWords((string) $now),
                'start_date' => 'start date '.self::date($was).' → '.self::date($now),
                'maturity_date' => 'maturity date '.self::date($was).' → '.self::date($now),
                'purpose' => 'purpose "'.($was ?? '').'" → "'.($now ?? '').'"',
                'scb_amount' => 'share capital build-up '.self::money($was).' → '.self::money($now),
                'deductions' => 'deductions '.self::items($was).' → '.self::items($now),
                'total_deductions' => 'total deductions '.self::money($was).' → '.self::money($now),
                'net_proceeds' => 'net proceeds '.self::money($was).' → '.self::money($now),
                'account_officer_id' => 'account officer '.self::officer($was).' → '.self::officer($now),
                'co_makers' => 'co-makers '.self::names($was).' → '.self::names($now),
                default => Str::headline($field).' changed',
            };
        }

        return "Loan {$loan->application_number} edited during approval at the \"{$step->name}\" step: ".implode('; ', $parts).'.';
    }

    /** A peso amount with two decimals, never rounded on the way in ("80000.00"). */
    private static function pesos(mixed $amount): string
    {
        return (string) BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp);
    }

    /** A rate as its column keeps it, four decimals ("2.5000"). */
    private static function rate(mixed $rate): string
    {
        return (string) BigDecimal::of((string) $rate)->toScale(4, RoundingMode::HalfUp);
    }

    /** "₱80,000.00", or "none". */
    private static function money(mixed $amount): string
    {
        return $amount === null ? 'none' : StepMinimum::pesos((string) $amount);
    }

    /** "2.5%", every decimal the rate has and no trailing zero: rates are never rounded. */
    private static function percent(string $rate): string
    {
        return BigDecimal::of($rate)->strippedOfTrailingZeros().'%';
    }

    /** "per month", "per year": the period a rate is quoted per. */
    private static function ratePeriod(?InterestRateFrequency $frequency): string
    {
        return match ($frequency) {
            InterestRateFrequency::Daily => 'per day',
            InterestRateFrequency::Weekly => 'per week',
            InterestRateFrequency::BiWeekly => 'per 2 weeks',
            InterestRateFrequency::SemiMonthly => 'per half month',
            InterestRateFrequency::Annual => 'per year',
            default => 'per month',
        };
    }

    /** "Monthly", "Semi-Monthly", "Bi-Weekly", "Upon Maturity", as the Audit Trail reads them. */
    private static function frequencyWords(string $frequency): string
    {
        return match ($frequency) {
            'semi_monthly' => 'Semi-Monthly',
            'bi_weekly' => 'Bi-Weekly',
            default => Str::headline($frequency),
        };
    }

    /** "Dec 5, 2026", or "none". */
    private static function date(mixed $date): string
    {
        return $date === null ? 'none' : Carbon::parse((string) $date)->format('M j, Y');
    }

    /** The officer's name, or "none". */
    private static function officer(mixed $userId): string
    {
        $name = $userId === null ? null : User::query()->whereKey($userId)->first()?->full_name;

        return $name === null || $name === '' ? 'none' : (string) $name;
    }

    /**
     * @param  mixed  $names  list of names
     */
    private static function names(mixed $names): string
    {
        return is_array($names) && $names !== [] ? implode(', ', $names) : 'none';
    }

    /**
     * "Processing Fee ₱1,600.00, Service Fee ₱800.00", or "none".
     *
     * @param  mixed  $items  deduction items
     */
    private static function items(mixed $items): string
    {
        $words = array_map(
            static fn (mixed $item): string => is_array($item) ? trim(($item['name'] ?? '').' '.self::money($item['amount'] ?? null)) : '',
            is_array($items) ? array_values($items) : [],
        );

        return $words === [] ? 'none' : implode(', ', $words);
    }

    /**
     * Deduction items in one comparable form: keys sorted, numbers as exact
     * decimals, so 1200, 1200.0 and "1200.00" read the same, and the items in
     * a fixed order, so the same items listed in another order are no change.
     *
     * @param  array<int, mixed>  $items
     */
    private static function canonical(array $items): string
    {
        $canonical = array_map(static function (mixed $item): string {
            if (is_array($item)) {
                $item = array_map(static fn (mixed $value): mixed => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                    ? (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros()
                    : $value, $item);
                ksort($item);
            }

            return json_encode($item, JSON_THROW_ON_ERROR);
        }, array_values($items));

        sort($canonical);

        return implode("\n", $canonical);
    }
}
