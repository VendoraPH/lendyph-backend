<?php

namespace App\Models;

use App\Enums\InterestRateFrequency;
use App\Enums\TermUnit;
use App\Services\ApprovalAssignees;
use App\Services\BranchAccess;
use App\Services\SequenceCode;
use App\Support\StaffRouteBinding;
use App\Traits\Auditable;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

class Loan extends Model
{
    use Auditable, HasFactory;

    /**
     * The database defaults, so a loan built in memory — a preview stub, a
     * replicate() — reads the same as one loaded back.
     */
    protected $attributes = [
        'term_unit' => 'months',
        'interest_rate_frequency' => 'monthly',
    ];

    /**
     * Loans that have been money out the door — the portfolio a balance,
     * release, disbursement, or income report is measured over.
     *
     * A loan that was released does not stop having been released because it
     * later defaulted or was closed by a restructure, so those belong here too
     * — the cash went out the door either way, and a releases or disbursement
     * report that hides it is wrong. Whether such a loan still OWES anything is
     * a different question: see COLLECTIBLE_STATUSES.
     */
    public const EVER_RELEASED_STATUSES = ['released', 'ongoing', 'completed', 'defaulted', 'restructured'];

    /**
     * The `application_number` family. Named so SequenceAllocator predicts
     * numbers from the same constant the hook issues them from.
     */
    public const CODE_PREFIX = 'LA';

    /**
     * Loans that can still owe money on a schedule.
     *
     * `ongoing` matters because RepaymentService flips `released → ongoing` on
     * the very first payment, so filtering on `released` alone silently drops
     * every loan that has ever paid. `defaulted` matters because a defaulted
     * loan is precisely what a delinquency report exists to show.
     *
     * `restructured` is deliberately NOT here, and must not be re-added.
     * LoanService::closeRestructuredSource() is the only thing that sets it,
     * and it means one thing: closed, because the balance moved to a new loan.
     * It clears the open schedules first and zeroes the insurance, so such a
     * loan owes nothing and RepaymentService refuses payments against it.
     *
     * Note the ordering dependency this carries: before that behaviour shipped,
     * `restructured` was stamped on loans that were still live with open
     * schedules. Listing it here is the correct reading of THAT data. So the
     * restructure work — including its backfill of the damaged rows to
     * released/ongoing — has to land before this line does, or those live loans
     * disappear from every delinquency report.
     */
    public const COLLECTIBLE_STATUSES = ['released', 'ongoing', 'defaulted'];

    /**
     * Loans that are out the door and not yet finished with — the portfolio the
     * loans screen's "Active Loans" card counts, and what `?status=active`
     * expands to.
     *
     * Not the same set as COLLECTIBLE_STATUSES, and the difference is
     * deliberate: `defaulted` still owes money, so a delinquency report must
     * include it, but it is not what an operator means by an active loan, and
     * the loans screen gives it no tab. Do not collapse the two.
     *
     * This list also carried `current` and `past_due` until they were removed.
     * Neither is a member of the `loans.status` enum, so no row can hold one and
     * the entries only made this constant assert statuses that cannot exist.
     * Do NOT add them back — not here and not to the enum. The screen's Current
     * tab points at `ongoing`, and past due is not a status at all: it is an
     * `ongoing` loan holding an overdue amortization schedule, which is a
     * schedule-derived filter and is filed as a follow-up. Adding the enum
     * member instead would let a loan be stamped `past_due` and then be
     * invisible to everything that reasons about `ongoing` — which is most of
     * this codebase, RepaymentService::processRepayment() included.
     *
     * That schedule-derived filter has since shipped as
     * self::VIRTUAL_STATUS_PAST_DUE / self::scopePastDue(), which is where a
     * Past Due tab must go. It still is not — and must never become — a member
     * of this constant or of the enum. Note that scope filters on
     * self::COLLECTIBLE_STATUSES rather than on this list: a defaulted loan has
     * no tab of its own and would otherwise be reachable only under All.
     */
    public const ACTIVE_STATUSES = ['released', 'ongoing'];

    /**
     * Loans whose collateral is still pledged: the holders every pledge check
     * refuses a second loan against (CollateralPledgeGuard,
     * Collateral::activeLoans(), CollateralRegister).
     *
     * self::ACTIVE_STATUSES plus `defaulted`. A defaulted loan still owes its
     * balance, and the collateral securing it is what the cooperative may have
     * to call on, so it must not be free to secure another loan. It stays out of
     * ACTIVE_STATUSES itself, which is what the loans screen, auto-pay and the
     * penalty run mean by an active loan.
     */
    public const PLEDGING_STATUSES = ['released', 'ongoing', 'defaulted'];

    /**
     * Virtual `status` value standing for the whole of self::ACTIVE_STATUSES.
     *
     * Not a stored status and never written to a row — it exists only as a
     * query-string shorthand, so that what the Active Loans card counts and
     * what the tab it opens filters on are one definition. Callers should send
     * this rather than spelling the statuses out: the set has already changed
     * once, and a hardcoded list on the client goes stale silently.
     */
    public const VIRTUAL_STATUS_ACTIVE = 'active';

    /**
     * Virtual `status` value standing for self::scopePastDue().
     *
     * Like VIRTUAL_STATUS_ACTIVE this is a query-string shorthand that is never
     * written to a row. Unlike it, `past_due` does not expand to a list of
     * statuses at all: it is derived from `amortization_schedules`, so it
     * resolves to a subquery rather than to a `whereIn` on `loans.status`.
     *
     * Both `?status=past_due` and `meta.stats.past_due` are built from
     * self::scopePastDue(), so the tab and the badge above it cannot drift.
     *
     * It is NOT a subset of VIRTUAL_STATUS_ACTIVE: past due reaches `defaulted`
     * loans, which are collectible but not active.
     */
    public const VIRTUAL_STATUS_PAST_DUE = 'past_due';

    /**
     * Virtual `status` value standing for self::scopeCurrent(): the Current
     * tab, the active loans that are not past due.
     *
     * Never written to a row and never a member of the enum, for the reasons
     * self::ACTIVE_STATUSES gives. Like `past_due` it resolves to a subquery on
     * `amortization_schedules`, so it is branched on rather than mapped into a
     * `whereIn`, and `?status=current` and `meta.stats.current` are built from
     * the one scope.
     */
    public const VIRTUAL_STATUS_CURRENT = 'current';

    /**
     * How a loan's schedule rounds each period's interest
     * (`schedule_rounding`). Every loan released before the follow-ups batch
     * is `legacy`, a float factor (LoanTermSchedule::rateForDays()), and keeps
     * it in every recomputation. Release stamps `exact`
     * (LoanTermSchedule::interestFor()), and so does the CSV importer; a draft
     * or a preview has none and is worked out exactly, so it equals its
     * release. A released loan with none reads as legacy
     * (roundsInterestExactly()).
     */
    public const SCHEDULE_ROUNDING_LEGACY = 'legacy';

    public const SCHEDULE_ROUNDING_EXACT = 'exact';

    protected $fillable = [
        'loan_account_number',
        'external_loan_no',
        'imported_arrears_baseline',
        'borrower_id',
        'loan_product_id',
        'source_loan_id',
        'branch_id',
        'interest_rate',
        'interest_rate_frequency',
        'interest_method',
        'term',
        'term_unit',
        'frequency',
        'principal_amount',
        'purpose',
        'start_date',
        'maturity_date',
        'deductions',
        'total_deductions',
        'net_proceeds',
        'scb_amount',
        'penalty_rate',
        // The product's active penalty configuration, copied at creation
        // (PenaltyTerms::forProduct()); null on a loan charged by
        // `penalty_rate`.
        'penalty_config_id',
        'penalty_config_name',
        'penalty_brackets',
        'grace_period_days',
        'policy_exception',
        'policy_exception_details',
        'status',
        'approval_remarks',
        'approved_by',
        'approved_at',
        'rejection_remarks',
        'rejected_by',
        'rejected_at',
        'released_by',
        'released_at',
        'created_by',
        'account_officer_id',
        'auto_pay',
        'cbs_reference',
        'auto_pay_enabled_at',
        'auto_pay_enabled_by',
        'insurance_premium_pct',
        'insurance_premium_amount',
        'insurance_payment_type',
        'insurance_partial_amount',
        'insurance_remaining_balance',
        'insurance_carried_over',
        'restructured_at',
        'restructured_balance',
        'write_off_amount',
        'restructure_outstanding',
        'restructure_principal',
        'restructure_shortfall',
        'restructure_remarks',
        // How the release paid out (or, on a restructure, collected the
        // deductions), in the Record Payment vocabulary, and its reference.
        // Null on loans released before it was recorded ("Not recorded").
        'release_method',
        'release_reference',
        // A restructure's split of the new principal, stored at release for
        // the books (PostingRules::restructureSplit()). Null on every other loan.
        'restructure_carried_principal',
        'restructure_capitalised_interest',
        'restructure_capitalised_penalty',
        'restructure_written_off_principal',
        // self::SCHEDULE_ROUNDING_*; null until release.
        'schedule_rounding',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:4',
            'principal_amount' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_proceeds' => 'decimal:2',
            'scb_amount' => 'decimal:2',
            'penalty_rate' => 'decimal:4',
            'penalty_brackets' => 'array',
            'policy_exception' => 'boolean',
            'start_date' => 'date',
            'maturity_date' => 'date',
            'imported_arrears_baseline' => 'date',
            'approved_at' => 'datetime',
            'released_at' => 'datetime',
            'rejected_at' => 'datetime',
            'deductions' => 'array',
            'auto_pay' => 'boolean',
            'auto_pay_enabled_at' => 'datetime',
            'insurance_premium_pct' => 'decimal:2',
            'insurance_premium_amount' => 'decimal:2',
            'insurance_partial_amount' => 'decimal:2',
            'insurance_remaining_balance' => 'decimal:2',
            'insurance_carried_over' => 'decimal:2',
            'restructured_at' => 'datetime',
            'restructured_balance' => 'decimal:2',
            'write_off_amount' => 'decimal:2',
            'restructure_outstanding' => 'decimal:2',
            'restructure_principal' => 'decimal:2',
            'restructure_shortfall' => 'decimal:2',
            'restructure_carried_principal' => 'decimal:2',
            'restructure_capitalised_interest' => 'decimal:2',
            'restructure_capitalised_penalty' => 'decimal:2',
            'restructure_written_off_principal' => 'decimal:2',
            'term_unit' => TermUnit::class,
            'interest_rate_frequency' => InterestRateFrequency::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Loan $loan) {
            $last = static::query()->orderByDesc('id')->lockForUpdate()->first();

            // Parsed, not cast — the same guard as Borrower::booted(), for the
            // same reason. `(int) substr($code, 3) + 1` answers 1 for anything
            // it cannot read, and LA-000001 already exists, so one malformed
            // application_number would make every loan create fail on the unique
            // index from then on. SequenceCode stops on the bad row and names it
            // rather than colliding forever.
            $loan->application_number = $last === null
                ? SequenceCode::first(self::CODE_PREFIX)
                : SequenceCode::after(self::CODE_PREFIX, $last->application_number, "loans.id {$last->id}");
        });
    }

    /**
     * `total_deductions` less the itemised `deductions`, in pesos to the
     * centavo, SIGNED: positive when the total is more than the items explain,
     * negative when the items add up to more than the total, 0 when they
     * agree. Added up in whole centavos.
     *
     * The total governs what was withheld (it is what the release journal and
     * `net_proceeds` follow); the printed documents itemise the deductions and
     * show this as a line of its own ("Other deductions" when positive, an
     * "Adjustment" when negative), so the lines add up to the total without
     * the browser subtracting anything. An imported loan carries a total and
     * no items, so all of it is unitemised.
     */
    public function unitemisedDeductions(): float
    {
        $itemised = 0;

        foreach (is_array($this->deductions) ? $this->deductions : [] as $item) {
            if (is_array($item) && is_numeric($item['amount'] ?? null)) {
                $itemised += (int) round((float) $item['amount'] * 100);
            }
        }

        return ((int) round((float) $this->total_deductions * 100) - $itemised) / 100;
    }

    protected function isEditable(): Attribute
    {
        return Attribute::get(fn () => in_array($this->status, ['draft', 'for_review']));
    }

    protected function isReleasable(): Attribute
    {
        return Attribute::get(fn () => $this->status === 'approved');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    /**
     * The penalty configuration this loan was created under, if any. Charges
     * read the loan's own copy (`penalty_brackets`), never this row.
     */
    public function penaltyConfig(): BelongsTo
    {
        return $this->belongsTo(PenaltyConfig::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The loan this one was restructured out of — its outstanding balance is
     * what funded this loan's principal.
     */
    public function sourceLoan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_loan_id');
    }

    /**
     * Restructure applications derived from this loan. Plural because a
     * rejected or voided attempt stays on record and a later one can follow;
     * only one may be open (draft/for_review/approved) at a time, and only the
     * released one closes this loan.
     */
    public function restructuredInto(): HasMany
    {
        return $this->hasMany(self::class, 'source_loan_id');
    }

    /**
     * The pivot's `added_by` is who linked the co-maker to this loan, and its
     * `created_at` when.
     */
    public function coMakers(): BelongsToMany
    {
        return $this->belongsToMany(CoMaker::class, 'co_maker_loan')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    public function collaterals(): BelongsToMany
    {
        return $this->belongsToMany(Collateral::class, 'loan_collaterals')
            ->withPivot(['snapshot_value', 'attached_at'])
            ->withTimestamps();
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function accountOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_officer_id');
    }

    public function autoPayEnabledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auto_pay_enabled_by');
    }

    public function amortizationSchedules(): HasMany
    {
        return $this->hasMany(AmortizationSchedule::class)->orderBy('period_number');
    }

    /**
     * The periods still taking payments, without the ones already settled, for
     * a list that shows a loan's next due and overdue figures and must not load
     * its whole schedule (GET /loans?view=summary).
     */
    public function openSchedules(): HasMany
    {
        return $this->amortizationSchedules()->whereIn('status', AmortizationSchedule::UNPAID_STATUSES);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class)->orderBy('payment_date');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(LoanAdjustment::class)->latest();
    }

    /**
     * Every step of every revision round of this loan's approval chain,
     * in the order the chain runs. Written only by LoanApprovalChainService.
     */
    public function approvalSteps(): HasMany
    {
        return $this->hasMany(LoanApprovalStep::class)
            ->orderBy('round')
            ->orderBy('step_order');
    }

    /**
     * Name of the approval step this loan is waiting on, for the loans list's
     * "For Approval - <approver>" label.
     *
     * The first pending step of the live round: the step the loan detail page
     * picks out of GET /loans/{id}/approval-steps. Null outside `for_review`
     * (an approved loan's pending release step is not an approver), when
     * nothing is pending, and when the name is blank, so a client never has to
     * tell "" apart from null.
     *
     * Reads the `approvalSteps` relation: eager-load it before calling this
     * across a list.
     */
    public function currentApprover(): ?string
    {
        if ($this->status !== 'for_review') {
            return null;
        }

        $name = $this->currentPendingStep()?->name;

        return trim((string) $name) === '' ? null : $name;
    }

    /**
     * The label the loans list shows beside a loan's status, from the
     * `status_label` snapshotted onto a step of its chain.
     *
     * - `for_review` ("For Approval - <label>"): the step currentApprover()
     *   names. The client falls back to the approver name without a label.
     * - `approved` ("Approved - <label>"): its release step, the one step left.
     *   The client reads plain "Approved" without a label.
     *
     * Null for every other status, and when the step has no label or a blank
     * one, so a client never has to tell "" apart from null.
     *
     * Reads the `approvalSteps` relation: eager-load it before calling this
     * across a list.
     */
    public function currentStatusLabel(): ?string
    {
        $step = match ($this->status) {
            'for_review' => $this->currentPendingStep(),
            'approved' => $this->unfinishedReleaseStep(),
            default => null,
        };

        $label = $step?->status_label;

        return trim((string) $label) === '' ? null : $label;
    }

    /**
     * When the loan was first submitted for approval: the submit step of its
     * first round, which LoanApprovalChainService::seed() signs at that moment.
     * A send-back to the submit step opens a later round and doesn't move it.
     *
     * A loan from before the chain existed has no steps. With `$readAuditTrail`,
     * the audit row its move to `for_review` wrote is the record instead, at the
     * cost of a query. Otherwise, and with no such row, null.
     *
     * Reads the `approvalSteps` relation: eager-load it before calling this
     * across a list.
     */
    public function submittedAt(bool $readAuditTrail = false): ?Carbon
    {
        $submitted = $this->approvalSteps
            ->where('kind', LoanApprovalStep::KIND_SUBMIT)
            ->whereNotNull('acted_at')
            ->sortBy('round')
            ->first()
            ?->acted_at;

        if ($submitted !== null || ! $readAuditTrail || $this->approvalSteps->isNotEmpty()) {
            return $submitted;
        }

        return $this->auditLogs()
            ->where('action', 'updated')
            ->where('new_values->status', 'for_review')
            ->oldest('id')
            ->first()
            ?->created_at;
    }

    private function currentPendingStep(): ?LoanApprovalStep
    {
        return LoanApprovalStep::currentRound($this->approvalSteps)
            ->firstWhere('status', LoanApprovalStep::STATUS_PENDING);
    }

    /**
     * The release step of the live round, in any unfinished state, as
     * LoanApprovalChainService::markReleased() finds it. Found by kind rather
     * than as the first pending step, so a chain left mid-flight against an
     * approved loan still answers with this step's label, not an approver's.
     */
    private function unfinishedReleaseStep(): ?LoanApprovalStep
    {
        return LoanApprovalStep::currentRound($this->approvalSteps)
            ->first(fn (LoanApprovalStep $step) => $step->kind === LoanApprovalStep::KIND_RELEASE
                && $step->status !== LoanApprovalStep::STATUS_APPROVED);
    }

    /**
     * The status a screen shows: `past_due` for a released or ongoing loan
     * holding a late period (self::scopePastDue()'s rule), otherwise `status`.
     * Never written: `loans.status` keeps its values, and a defaulted loan,
     * which the Past Due filter also reaches, keeps reading `defaulted`.
     *
     * Uses the periods the caller loaded, so a list pays nothing per row. On a
     * bare model it asks once, so the answer is never silently wrong.
     */
    public function displayStatus(): ?string
    {
        if (! in_array($this->status, self::ACTIVE_STATUSES, true)) {
            return $this->status;
        }

        return $this->holdsLatePeriod() ? self::VIRTUAL_STATUS_PAST_DUE : $this->status;
    }

    private function holdsLatePeriod(): bool
    {
        $asOf = Carbon::today();

        foreach (['amortizationSchedules', 'openSchedules'] as $relation) {
            if ($this->relationLoaded($relation)) {
                return AmortizationSchedule::lateUnpaid($this->getRelation($relation), $this->grace_period_days, $asOf)->isNotEmpty();
            }
        }

        return $this->amortizationSchedules()
            ->reorder()
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->where('due_date', '<', AmortizationSchedule::pastGraceCutoff($this->grace_period_days, $asOf)->toDateString())
            ->exists();
    }

    /**
     * Loans under review whose current approval step belongs to one of these
     * roles: the step currentApprover() names, i.e. the first `pending` step
     * (by step_order) of the loan's latest round. A `pending` row left behind
     * in an earlier round does not count, and neither does any status other
     * than `for_review`.
     *
     * Role names are compared byte for byte, the way
     * LoanApprovalChainService::canAct() compares them in PHP. The column's
     * case-insensitive collation would otherwise let a step role `Manager` or
     * `manager ` match the role `manager`. There is no admin/super_admin
     * bypass: this asks whether it is the role's turn, not who may act.
     *
     * With `$user` (the "awaiting me" filter), a step forwarded to a named
     * user (`assigned_to`) is theirs, not the role's, while they still hold it
     * (ApprovalAssignees); once they don't, it is the role's again. An
     * approved loan's pending release step counts too, for a user holding
     * `loans:release`, by the same rule.
     *
     * @param  list<string>  $roleNames
     */
    public function scopeAwaitingApprovalFromRoles($query, array $roleNames, ?User $user = null)
    {
        if ($user === null) {
            return $query->where('loans.status', 'for_review')
                ->whereExists(fn ($steps) => $this->currentStepQuery($steps)
                    ->whereIn(DB::raw('CAST(current_step.role AS BINARY)'), $roleNames));
        }

        $userId = (int) $user->getKey();
        $fallingBack = $this->stepsFallingBackToTheirRole($roleNames, $user);
        $mine = fn ($steps) => $steps->where(fn ($who) => $who
            ->where(fn ($assigned) => $assigned
                ->where('current_step.assigned_to', $userId)
                ->whereNotIn('current_step.id', $fallingBack))
            ->orWhere(fn ($forRole) => $forRole
                ->where(fn ($unassigned) => $unassigned
                    ->whereNull('current_step.assigned_to')
                    ->orWhereIn('current_step.id', $fallingBack))
                ->whereIn(DB::raw('CAST(current_step.role AS BINARY)'), $roleNames)));

        $canRelease = $user->can('loans:release');

        return $query->where(fn ($loans) => $loans
            ->where(fn ($inReview) => $inReview
                ->where('loans.status', 'for_review')
                ->whereExists(fn ($steps) => $mine($this->currentStepQuery($steps))))
            ->when($canRelease, fn ($loans) => $loans->orWhere(fn ($approved) => $approved
                ->where('loans.status', 'approved')
                ->whereExists(fn ($steps) => $mine($this->currentStepQuery($steps)
                    ->where('current_step.kind', LoanApprovalStep::KIND_RELEASE))))));
    }

    /**
     * The first `pending` step of the loan's latest round, as `current_step`.
     */
    private function currentStepQuery($steps)
    {
        return $steps->selectRaw('1')
            ->from('loan_approval_steps as current_step')
            ->whereColumn('current_step.loan_id', 'loans.id')
            ->where('current_step.status', LoanApprovalStep::STATUS_PENDING)
            ->whereRaw('current_step.round = (select max(latest.round) from loan_approval_steps as latest where latest.loan_id = current_step.loan_id)')
            ->whereNotExists(function ($earlier) {
                $earlier->selectRaw('1')
                    ->from('loan_approval_steps as earlier_step')
                    ->whereColumn('earlier_step.loan_id', 'current_step.loan_id')
                    ->whereColumn('earlier_step.round', 'current_step.round')
                    ->where('earlier_step.status', LoanApprovalStep::STATUS_PENDING)
                    ->whereColumn('earlier_step.step_order', '<', 'current_step.step_order');
            });
    }

    /**
     * Pending, forwarded steps of the live round of loans this user can see,
     * in review or approved, that matter to them (their role's, or forwarded
     * to them), whose assignee no longer holds them: these read as their
     * role's again. Few rows, so the full rule is checked in PHP
     * (ApprovalAssignees), as canAct() checks it.
     *
     * Read in a fixed number of queries whatever the number of steps: the
     * steps with their loans, the loans' steps (for the last-approve rule) and
     * the assignees' roles and permissions are loaded together, and the
     * branch check is read once per assignee (BranchAccess memoises it).
     *
     * @param  list<string>  $roleNames
     * @return list<int>
     */
    private function stepsFallingBackToTheirRole(array $roleNames, User $user): array
    {
        $assignees = app(ApprovalAssignees::class);
        $userId = (int) $user->getKey();

        return LoanApprovalStep::query()
            ->with(['loan.approvalSteps', 'assignee.roles', 'assignee.permissions'])
            ->where('loan_approval_steps.status', LoanApprovalStep::STATUS_PENDING)
            ->whereNotNull('loan_approval_steps.assigned_to')
            ->whereRaw('loan_approval_steps.round = (select max(latest.round) from loan_approval_steps as latest where latest.loan_id = loan_approval_steps.loan_id)')
            ->whereHas('loan', fn ($loans) => $loans->visibleTo($user)->whereIn('loans.status', ['for_review', 'approved']))
            ->where(fn ($steps) => $steps
                ->where('loan_approval_steps.assigned_to', $userId)
                ->orWhereIn('loan_approval_steps.role', $roleNames === [] ? [''] : $roleNames))
            ->get()
            ->filter(fn (LoanApprovalStep $step) => $assignees->effectiveAssignee($step, $step->loan, self::isLastApproveIn($step)) === null)
            ->map(fn (LoanApprovalStep $step) => (int) $step->id)
            ->values()
            ->all();
    }

    /**
     * ApprovalAssignees::isLastApproveStep(), read from the loan's loaded steps.
     */
    private static function isLastApproveIn(LoanApprovalStep $step): bool
    {
        return $step->kind === LoanApprovalStep::KIND_APPROVE
            && ! $step->loan->approvalSteps->contains(fn (LoanApprovalStep $other) => $other->round === $step->round
                && $other->step_order > $step->step_order
                && in_array($other->kind, [LoanApprovalStep::KIND_SUBMIT, LoanApprovalStep::KIND_APPROVE], true));
    }

    /**
     * Debits and credits recorded against this loan, oldest first — a ledger
     * only makes sense read forwards.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LoanLedgerEntry::class)->orderBy('entry_date')->orderBy('id');
    }

    /**
     * Whether its schedules round each period's interest exactly
     * (LoanTermSchedule::interestFor()): a loan marked `exact`, or one with
     * no marker that was never released (a draft or a preview). A loan marked
     * `legacy`, or released with no marker (by code older than the marker, or
     * before its migration ran), keeps the float rounding: one with a
     * `released_at` or a saved schedule. replicate() copies the marker and
     * `released_at`, so a Ledger rebuild or an adjustment's temporary loan
     * rounds as the loan was released.
     */
    public function roundsInterestExactly(): bool
    {
        return match ($this->schedule_rounding) {
            self::SCHEDULE_ROUNDING_EXACT => true,
            self::SCHEDULE_ROUNDING_LEGACY => false,
            default => $this->released_at === null
                && ! ($this->exists && $this->amortizationSchedules()->exists()),
        };
    }

    /**
     * Whether this loan is eligible for the Extend Loan action.
     *
     * Extension is limited to one-month-term loans whatever the product: a
     * term of 1 in months, paid monthly or at maturity. A 30-day term is not
     * one, and neither is a one-month loan paid more often than monthly.
     */
    public function isOneMonthTerm(): bool
    {
        return in_array($this->frequency, ['monthly', 'upon_maturity'], true)
            && $this->term_unit === TermUnit::Months
            && $this->term === 1;
    }

    /**
     * Whether this loan was created by restructuring another one, i.e. its
     * principal came from a source loan's outstanding balance rather than from
     * fresh money. Exposed as `is_restructure` on LoanResource.
     */
    public function isRestructure(): bool
    {
        return $this->source_loan_id !== null;
    }

    /**
     * Whether this loan was migrated in from a cooperative's existing book by
     * the CSV importer rather than originated here. Exposed as `is_imported`
     * on LoanResource, where the UI uses it to label a schedule as
     * reconstructed rather than generated from the loan's own terms.
     *
     * True on EITHER import marker. `external_loan_no` is the coop's own
     * reference for the loan and `imported_arrears_baseline` is the date its
     * pre-import arrears stop; nothing but the importer ever writes either, but
     * a coop's file need not supply a reference number for every row, and a
     * loan imported with no arrears at all need carry no baseline. Requiring
     * both would silently mislabel those loans as natively originated.
     *
     * This is a LABEL, not the penalty rule. Nothing in the penalty or default
     * path may branch on it: those ask
     * AmortizationSchedule::isPenalisable()/penalisableSql() about one schedule
     * against the baseline, which is null-safe by construction and so cannot
     * disagree with this method about which loans it covers.
     */
    public function isImported(): bool
    {
        return $this->external_loan_no !== null
            || $this->imported_arrears_baseline !== null;
    }

    /**
     * How many times this loan has been rolled forward via the Extend Loan
     * action (LoanAdjustmentService::extendLoan()), as distinct from `term`
     * — the term the loan was agreed at, which stays fixed regardless of how
     * many times it has extended.
     *
     * Reads the count eager-loaded via withCount()/loadCount() on
     * 'adjustments as extension_count' when the caller supplied one — that is
     * what keeps a paginated loans list at one query instead of one COUNT per
     * row (see LoanController::index()). Falls back to a live count
     * otherwise, so the attribute is still correct on a bare model.
     */
    protected function extensionCount(): Attribute
    {
        return Attribute::get(function (mixed $value): int {
            if ($value !== null) {
                return (int) $value;
            }

            return $this->adjustments()->where('adjustment_type', 'extension')->count();
        });
    }

    /**
     * The one definition of what this loan still owes: principal remaining on
     * its schedules (floored at zero per period) plus any insurance premium
     * that was not collected at release.
     *
     * Every caller — the API resource, the reports, the exports — must read
     * this rather than recomputing, which is how four different "outstanding
     * balance" figures ended up on the same dashboard. Aggregate/grouped SQL
     * gets the identical rule from AmortizationSchedule::remainingPrincipalSql().
     *
     * Uses the eager-loaded schedules when the caller supplied them, so a
     * paginated list costs no extra query per row; falls back to one aggregate
     * query otherwise, so the value is still correct on a bare model instead of
     * silently reading 0.
     */
    protected function outstandingBalance(): Attribute
    {
        return Attribute::get(function (): float {
            $principalBalance = $this->relationLoaded('amortizationSchedules')
                ? $this->amortizationSchedules->sum(
                    fn ($schedule) => max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid)
                )
                : (float) ($this->amortizationSchedules()
                    ->reorder()
                    ->selectRaw('SUM('.AmortizationSchedule::remainingPrincipalSql().') as balance')
                    ->value('balance') ?? 0);

            return round((float) $principalBalance + (float) $this->insurance_remaining_balance, 2);
        });
    }

    /**
     * What the loan still owes, in whole centavos, from `$schedules` (its
     * periods): each period's unpaid principal, interest and penalty, floored
     * at zero per period, plus the insurance premium still owed.
     *
     * `total` is the API's `total_outstanding` (everything owed) and
     * `principal` its `outstanding_principal`, on GET /loans, GET /loans/{id}
     * and GET /loans/{id}/summary alike, so one loan never shows two
     * "outstanding" figures. A period a restructure, extension or adjustment
     * closed has its dues cut to what was paid, so it adds nothing.
     *
     * Not Loan::$outstanding_balance, which stays principal plus premium for
     * the reports.
     *
     * @param  iterable<AmortizationSchedule>  $schedules
     * @return array{principal: int, interest: int, penalty: int, insurance: int, total: int}
     */
    public function owedCentavos(iterable $schedules): array
    {
        $centavos = fn (mixed $pesos): int => (int) round((float) ($pesos ?? 0) * 100);
        $owed = ['principal' => 0, 'interest' => 0, 'penalty' => 0];

        foreach ($schedules as $schedule) {
            $owed['principal'] += max(0, $centavos($schedule->principal_due) - $centavos($schedule->principal_paid));
            $owed['interest'] += max(0, $centavos($schedule->interest_due) - $centavos($schedule->interest_paid));
            $owed['penalty'] += max(0, $centavos($schedule->penalty_amount) - $centavos($schedule->penalty_paid));
        }

        return $this->owedWithInsurance($owed);
    }

    /**
     * self::owedCentavos() from what self::owedOnPeriodsByLoan() added up, for a
     * list that does not hold every period.
     *
     * @param  array{principal: int, interest: int, penalty: int}  $periods
     * @return array{principal: int, interest: int, penalty: int, insurance: int, total: int}
     */
    public function owedWithInsurance(array $periods): array
    {
        $insurance = max(0, (int) round((float) ($this->insurance_remaining_balance ?? 0) * 100));

        return [
            ...$periods,
            'insurance' => $insurance,
            'total' => $periods['principal'] + $periods['interest'] + $periods['penalty'] + $insurance,
        ];
    }

    /**
     * What each loan's periods still owe, in whole centavos, in one grouped
     * query: the sums self::owedCentavos() makes in PHP, over EVERY period and
     * floored per period by the same AmortizationSchedule SQL helpers. A loan
     * with no periods has no key.
     *
     * @param  array<int, int>  $loanIds
     * @return array<int, array{principal: int, interest: int, penalty: int}>
     */
    public static function owedOnPeriodsByLoan(array $loanIds): array
    {
        if ($loanIds === []) {
            return [];
        }

        return DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->groupBy('loan_id')
            ->selectRaw(
                'loan_id,
                SUM('.AmortizationSchedule::remainingPrincipalSql().') as principal,
                SUM('.AmortizationSchedule::remainingInterestSql().') as interest,
                SUM('.AmortizationSchedule::remainingPenaltySql().') as penalty',
            )
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->loan_id => [
                'principal' => (int) round((float) $row->principal * 100),
                'interest' => (int) round((float) $row->interest * 100),
                'penalty' => (int) round((float) $row->penalty * 100),
            ]])
            ->all();
    }

    /**
     * Whether insurance premium the release left unpaid is still owed on this
     * loan (`insurance_remaining_balance`). Payments collect it first, and a
     * loan that owes it never completes.
     */
    public function owesInsurancePremium(): bool
    {
        return (int) round((float) $this->insurance_remaining_balance * 100) > 0;
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeForBranch($query, int $branchId)
    {
        // Qualified: the loans list joins `borrowers` to sort by borrower name,
        // and that table has a `branch_id` of its own.
        return $query->where('loans.branch_id', $branchId);
    }

    /**
     * Loans in the branches $user may see (BranchAccess). A local scope only:
     * jobs, commands and reports that aggregate the whole book never call it.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $branchIds = app(BranchAccess::class)->for($user);

        return $branchIds === null ? $query : $query->whereIn('loans.branch_id', $branchIds);
    }

    /**
     * Every `{loan}` route parameter resolves only to a loan the signed-in user
     * may see, so another branch's loan answers 404 on every `/loans/{loan}/*`
     * read and write, whichever controller serves it. Without a signed-in user
     * (signed links, console) the binding is unscoped.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return StaffRouteBinding::scope(
            parent::resolveRouteBindingQuery($query, $value, $field),
            fn ($query, User $user) => $query->visibleTo($user),
        );
    }

    /**
     * Loans an operator has to chase: money out the door, still owing, and
     * holding at least one unpaid schedule that is LATE — past its due date and
     * past the grace period the loan's paperwork promised.
     *
     * Past due is not a `loans.status` and must never become one — see
     * self::ACTIVE_STATUSES for why. It is a property of the loan's
     * amortization schedule, so it lives here as a scope and reaches the API
     * as self::VIRTUAL_STATUS_PAST_DUE.
     *
     * Lateness itself is NOT defined here. It is
     * AmortizationSchedule::pastGraceSql(), shared with
     * RepaymentService::applyPenalties() and the loans:apply-penalties command,
     * so that the tab, the `overdue` stamp and the money charged for being late
     * cannot disagree about what late means. Do not inline the comparison.
     *
     * Membership matches ReportService::duePastDueQuery() on statuses —
     * self::COLLECTIBLE_STATUSES, `defaulted` included. The loans screen gives
     * `defaulted` no tab of its own, so before this it was reachable only under
     * All; surfacing it here gives collections one place to look and puts the
     * list and the report on the same set of loans.
     *
     * ONE deliberate difference from that report remains, and it must survive
     * any later attempt to unify the two:
     *
     *   This asks whether a schedule is LATE. The report asks whether it is
     *   DUE — `due_date <= today`, no grace — because its bucket is "Due AND
     *   Past Due" combined and it exists to show what is owed. An installment
     *   falling due today is due, not late, and one inside its grace window is
     *   due but not yet late; counting either here would report arrears early.
     *   The report's own `days_overdue` reads 0 for exactly those rows.
     *
     * This reads the due date rather than the schedule's `overdue` stamp, so it
     * is correct on the day a schedule turns late rather than whenever
     * something last re-stamped it.
     */
    public function scopePastDue($query)
    {
        return $query
            ->whereIn('loans.status', self::COLLECTIBLE_STATUSES)
            ->whereHas('amortizationSchedules', self::lateUnpaidPeriods());
    }

    /**
     * The Current tab: active loans that are not past due, so every released or
     * ongoing loan reads either here or under self::scopePastDue().
     */
    public function scopeCurrent($query)
    {
        return $query
            ->whereIn('loans.status', self::ACTIVE_STATUSES)
            ->whereDoesntHave('amortizationSchedules', self::lateUnpaidPeriods());
    }

    /**
     * Narrows a query on `amortization_schedules` to its unpaid, late periods.
     * self::scopePastDue() and self::scopeCurrent() share it so they cannot
     * disagree about a loan.
     */
    private static function lateUnpaidPeriods(): Closure
    {
        return fn ($periods) => $periods
            ->whereIn('amortization_schedules.status', AmortizationSchedule::UNPAID_STATUSES)
            ->whereRaw(AmortizationSchedule::pastGraceSql(), [today()->toDateString()]);
    }

    /**
     * Filter by a single status, a comma-separated list, or an array of them.
     *
     * The loans list needs the list form: its Active tab is several statuses at
     * once (self::ACTIVE_STATUSES) and has to render from ONE request, not one
     * per status. A single value still behaves exactly as it did.
     *
     * Three virtual values are accepted alongside the real statuses:
     *
     * - `active` (self::VIRTUAL_STATUS_ACTIVE) expands to
     *   self::ACTIVE_STATUSES, so a caller may send it or spell the statuses
     *   out and get the same rows — either way the page total agrees with
     *   `meta.stats.active`.
     * - `past_due` (self::VIRTUAL_STATUS_PAST_DUE) resolves to
     *   self::scopePastDue() and agrees with `meta.stats.past_due` the same
     *   way.
     * - `current` (self::VIRTUAL_STATUS_CURRENT) resolves to
     *   self::scopeCurrent() and agrees with `meta.stats.current`.
     *
     * Prefer the shorthands: the sets are defined in one place here, and a list
     * pinned in the client cannot follow them.
     *
     * Note that only `active` can be flattened into the `whereIn` below.
     * `past_due` and `current` are schedule-derived, so they are subqueries, not
     * statuses — they have to be branched on rather than mapped into it. A list
     * mixing them stays an OR, which is what a comma already means here, so
     * `status=completed,past_due` is "completed OR past due".
     *
     * Values that are neither a status nor a virtual value are deliberately NOT
     * rejected, they simply match nothing: an empty result rather than a 422 that
     * takes the whole page down with it.
     *
     * @param  string|array<int, string>  $status
     */
    public function scopeForStatus($query, string|array $status)
    {
        $requested = collect(is_array($status) ? $status : explode(',', $status))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values();

        // `?status=` or `?status=,` means "no status filter", not "match
        // nothing" — whereIn([]) would hand back an empty page instead.
        if ($requested->isEmpty()) {
            return $query;
        }

        $wantsPastDue = $requested->contains(self::VIRTUAL_STATUS_PAST_DUE);
        $wantsCurrent = $requested->contains(self::VIRTUAL_STATUS_CURRENT);

        $statuses = $requested
            ->reject(fn (string $value) => in_array($value, [self::VIRTUAL_STATUS_PAST_DUE, self::VIRTUAL_STATUS_CURRENT], true))
            ->flatMap(fn (string $value) => $value === self::VIRTUAL_STATUS_ACTIVE
                ? self::ACTIVE_STATUSES
                : [$value])
            ->unique()
            ->values();

        if (! $wantsPastDue && ! $wantsCurrent) {
            return $query->whereIn('loans.status', $statuses->all());
        }

        return $query->where(fn ($q) => $q
            ->when($statuses->isNotEmpty(), fn ($any) => $any->orWhereIn('loans.status', $statuses->all()))
            ->when($wantsPastDue, fn ($any) => $any->orWhere(fn ($inner) => $inner->pastDue()))
            ->when($wantsCurrent, fn ($any) => $any->orWhere(fn ($inner) => $inner->current())));
    }
}
