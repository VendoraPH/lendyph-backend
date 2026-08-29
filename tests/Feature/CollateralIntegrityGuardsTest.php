<?php

/**
 * Four collateral-integrity gaps the lock-state feature left open, pinned shut.
 *
 *   1. attach() accepted ANY resolvable collateral id, so member A's land title
 *      could secure member B's loan. Now scoped to the loan's own borrower.
 *   2. The double-pledge guard only fired on a write into `loan_collaterals`. A
 *      loan moving INTO an active status is not a pivot write, so it never
 *      fired — two paths do exactly that.
 *   3. index() gated its filters on truthiness, so `?borrower_id=0` dropped the
 *      filter and answered with the whole unpaginated collateral book.
 *   4. update() could move a pledged collateral to another borrower — a second
 *      route to (1)'s outcome even after (1) is fixed.
 *
 * CollateralActiveLoansTest.php pins the feature these guards protect; this file
 * pins the guards. Both must stay green together — in particular the test there
 * that a loan LEAVING an active status frees the collateral, which none of these
 * checks may break.
 */

use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();

    $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $this->stranger = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    // SetupLendyPH keeps $branch and $admin protected, so the shared loan
    // attributes are stashed here for the file-level helpers below.
    $this->loanDefaults = [
        'borrower_id' => $this->borrower->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'branch_id' => $this->branch->id,
        'created_by' => $this->admin->id,
    ];
});

/**
 * A loan in an exact status, cheaply — the same helper shape
 * CollateralActiveLoansTest uses, redeclared because Pest loads each file's
 * functions into the same namespace and sharing one would collide.
 */
function guardLoanInStatus(array $defaults, string $status, ?int $borrowerId = null): Loan
{
    static $serial = 500;
    $serial++;

    return Loan::factory()->create(array_merge($defaults, [
        'status' => $status,
        'loan_account_number' => 'LN-'.str_pad((string) $serial, 6, '0', STR_PAD_LEFT),
    ], $borrowerId === null ? [] : ['borrower_id' => $borrowerId]));
}

/**
 * Write the pivot row directly, deliberately bypassing the attach guard, to
 * build states the guard itself refuses to produce.
 */
function guardPledgeDirectly(Loan $loan, Collateral $collateral, float $snapshotValue = 100.0): void
{
    $loan->collaterals()->attach($collateral->id, [
        'snapshot_value' => $snapshotValue,
        'attached_at' => now(),
    ]);
}

/**
 * A real loan taken through the lifecycle to `approved` and left there, so the
 * transition under test is the release itself.
 *
 * Built through LoanService rather than the factory because release() persists
 * an amortization schedule and needs a product with real terms behind it.
 */
function guardApprovedLoan(Borrower $borrower, User $admin, float $principal = 60000): Loan
{
    $product = LoanProduct::factory()->create([
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 6,
        'frequency' => 'monthly',
        'penalty_rate' => 2.0,
        'grace_period_days' => 3,
    ]);

    $service = app(LoanService::class);

    $loan = $service->createLoan([
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_amount' => $principal,
        'start_date' => now()->toDateString(),
    ], $admin);

    $service->submitForReview($loan);
    $service->approve($loan, $admin, 'Approved for testing');

    return $loan->fresh();
}

/**
 * ₱60,000 principal + ₱10,800 straight interest over six monthly periods — what
 * guardApprovedLoan() leaves owed, and therefore what settles it in one payment.
 */
const GUARD_FULL_SETTLEMENT = 70800.00;

/**
 * Run `$act` and report where the collateral row lock sits relative to the
 * transaction's first PLAIN read.
 *
 * That ordering is the whole ballgame under REPEATABLE READ: the consistent
 * snapshot is fixed by the first plain SELECT and neither a locking read nor DML
 * moves it, so a guard that locks AFTER some earlier `->get()` locks the right
 * rows and then answers from a pre-lock view of the world.
 *
 * @return array{lock: int|null, firstPlainRead: int|null, began: bool}
 */
function guardLockOrdering(callable $act): array
{
    $beganAt = null;
    Event::listen(TransactionBeginning::class, function () use (&$beganAt) {
        if ($beganAt === null) {
            $beganAt = count(DB::getQueryLog());
        }
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    $act();
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $q) => strtolower($q))->all();
    DB::disableQueryLog();

    if ($beganAt === null) {
        return ['lock' => null, 'firstPlainRead' => null, 'began' => false];
    }

    $lock = null;
    $firstPlainRead = null;

    foreach ($queries as $i => $sql) {
        if ($i < $beganAt) {
            continue;
        }

        $isLockingRead = str_contains($sql, 'for update') || str_contains($sql, 'lock in share mode');

        if ($lock === null && $isLockingRead && str_contains($sql, 'from `collaterals`')) {
            $lock = $i;
        }

        if ($firstPlainRead === null && str_starts_with($sql, 'select') && ! $isLockingRead) {
            $firstPlainRead = $i;
        }
    }

    return ['lock' => $lock, 'firstPlainRead' => $firstPlainRead, 'began' => true];
}

// ── 1. attach: the collateral must belong to the loan's borrower ─────────

it('refuses to attach a collateral belonging to a different borrower', function () {
    // The whole bug in three lines: a stranger's asset, a resolvable id, and a
    // loan that is not theirs.
    $strangersCollateral = Collateral::factory()->create(['borrower_id' => $this->stranger->id]);
    $loan = guardLoanInStatus($this->loanDefaults, 'draft');

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $strangersCollateral->id,
        'snapshot_value' => 250000,
    ])->assertStatus(422)
        ->assertJsonValidationErrors('collateral_id')
        ->assertJsonFragment([
            'collateral_id' => ['This collateral is not registered to this loan\'s borrower.'],
        ]);

    $this->assertDatabaseCount('loan_collaterals', 0);
});

it('still attaches a collateral belonging to the loan\'s own borrower', function () {
    $own = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $loan = guardLoanInStatus($this->loanDefaults, 'draft');

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $own->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $this->assertDatabaseHas('loan_collaterals', [
        'loan_id' => $loan->id,
        'collateral_id' => $own->id,
        'snapshot_value' => 250000,
    ]);
});

it('refuses a stranger\'s collateral whatever status the loan is in', function (string $status) {
    $strangersCollateral = Collateral::factory()->create(['borrower_id' => $this->stranger->id]);
    $loan = guardLoanInStatus($this->loanDefaults, $status);

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $strangersCollateral->id,
        'snapshot_value' => 250000,
    ])->assertStatus(422)->assertJsonValidationErrors('collateral_id');

    $this->assertDatabaseCount('loan_collaterals', 0);
})->with(['draft', 'for_review', 'approved', 'released', 'ongoing']);

it('answers a collateral id that resolves to nothing the same way it answers a stranger\'s', function () {
    // Same message on purpose: telling "not yours" apart from "does not exist"
    // would turn this endpoint into an enumeration oracle over other members'
    // registers.
    $loan = guardLoanInStatus($this->loanDefaults, 'draft');

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => 999999,
        'snapshot_value' => 250000,
    ])->assertStatus(422)
        ->assertJsonFragment([
            'collateral_id' => ['This collateral is not registered to this loan\'s borrower.'],
        ]);
});

it('refuses the stranger\'s collateral before it opens a transaction or takes a lock', function () {
    // Rejecting in validation, not as a third guard inside the locked
    // transaction, is the judgement call being pinned here: a request that names
    // a collateral it was never entitled to name must not get as far as locking
    // that collateral's row.
    $strangersCollateral = Collateral::factory()->create(['borrower_id' => $this->stranger->id]);
    $loan = guardLoanInStatus($this->loanDefaults, 'draft');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $strangersCollateral->id,
        'snapshot_value' => 250000,
    ])->assertStatus(422);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $q) => strtolower($q));
    DB::disableQueryLog();

    expect($queries->filter(fn (string $q) => str_contains($q, 'for update')))
        ->toBeEmpty('a request that was never entitled to name this collateral still locked its row');
});

// ── 2a. release(): approved → released ───────────────────────────────────

it('refuses to release a loan whose collateral another active loan already holds', function () {
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $loan = guardApprovedLoan($this->borrower, $this->admin);
    guardPledgeDirectly($loan, $collateral, 250000);

    $rival = guardLoanInStatus($this->loanDefaults, 'ongoing');
    guardPledgeDirectly($rival, $collateral, 250000);

    $this->patchJson("/api/loans/{$loan->id}/release")
        ->assertStatus(422)
        ->assertJsonValidationErrors('collateral')
        ->assertJsonFragment([
            'collateral' => ["This loan holds collateral already pledged to active loan {$rival->loan_account_number}. Detach it from that loan first."],
        ]);

    // The whole release rolls back, not just the guard's own read: no status
    // change, no loan account number issued, no amortization schedule.
    $loan->refresh();
    expect($loan->status)->toBe('approved')
        ->and($loan->loan_account_number)->toBeNull()
        ->and($loan->amortizationSchedules()->count())->toBe(0);

    expect($this->getJson("/api/collaterals/{$collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $rival->id, 'loan_account_number' => $rival->loan_account_number]]);
});

it('still releases a loan holding collateral no other active loan wants', function () {
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $loan = guardApprovedLoan($this->borrower, $this->admin);

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

    $loan->refresh();
    expect($loan->status)->toBe('released')
        ->and($loan->loan_account_number)->not->toBeNull();

    expect($this->getJson("/api/collaterals/{$collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $loan->id, 'loan_account_number' => $loan->loan_account_number]]);
});

it('still releases when the collateral\'s only other holder is not active', function (string $holderStatus) {
    // The sanctioned attach the guard deliberately permits, followed by the
    // release it must not block.
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    guardPledgeDirectly(guardLoanInStatus($this->loanDefaults, $holderStatus), $collateral);

    $loan = guardApprovedLoan($this->borrower, $this->admin);
    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

    expect($loan->fresh()->status)->toBe('released');
})->with(['draft', 'for_review', 'approved', 'rejected', 'completed', 'defaulted', 'restructured', 'void']);

it('releases a loan holding no collateral at all without asking the question', function () {
    $loan = guardApprovedLoan($this->borrower, $this->admin);

    $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

    expect($loan->fresh()->status)->toBe('released');
});

it('takes a row lock on the loan\'s collateral inside the release transaction', function () {
    // "Add the check at the status transition, taking the same `collaterals` row
    // lock attach() takes so it cannot race" — this is the "so it cannot race"
    // half. A read-then-write check without the lock is not a guard: an attach
    // racing a release both pass their own read and both commit.
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $loan = guardApprovedLoan($this->borrower, $this->admin);

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $transactionsOpened = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactionsOpened) {
        $transactionsOpened++;
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // `from \`loans\` … for update` is release()'s own loan-account-number lock
    // and would pass a laxer filter, so this insists on the COLLATERAL row.
    $lockingReads = collect($queries)
        ->pluck('query')
        ->map(fn (string $sql) => strtolower($sql))
        ->filter(fn (string $sql) => str_contains($sql, 'from `collaterals`') && str_contains($sql, 'for update'));

    expect($lockingReads)->not->toBeEmpty('release() decided without locking the collateral row')
        ->and($transactionsOpened)->toBeGreaterThan(0, 'release() checked and wrote outside a transaction');
});

// ── 2b. voidRepayment(): completed → ongoing/released ────────────────────

it('refuses to void a payment that would re-activate a loan onto collateral another active loan holds', function () {
    // The reachable one, walked end to end through sanctioned steps only.
    //
    //  1. Loan A is released holding the collateral and then settled in full,
    //     which takes it to `completed`.
    //  2. Loan B attaches the same collateral. PERMITTED, deliberately: A is no
    //     longer active, so the collateral is genuinely free.
    //  3. Voiding A's payment un-completes A. Before this guard, that left one
    //     collateral securing two live balances, with nothing anywhere in the
    //     system having been refused.
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $loanA = guardApprovedLoan($this->borrower, $this->admin);
    $this->postJson("/api/loans/{$loanA->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();
    $this->patchJson("/api/loans/{$loanA->id}/release")->assertOk();

    $repayment = app(RepaymentService::class)->processRepayment(
        $loanA->fresh(), GUARD_FULL_SETTLEMENT, now()->toDateString(), $this->admin,
    );
    expect($loanA->fresh()->status)->toBe('completed');

    $loanB = guardLoanInStatus($this->loanDefaults, 'released');
    $this->postJson("/api/loans/{$loanB->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Duplicate entry'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('collateral')
        ->assertJsonFragment([
            'collateral' => ["This loan holds collateral already pledged to active loan {$loanB->loan_account_number}. Detach it from that loan first."],
        ]);

    // The void rolls back whole: the payment is still posted, the loan is still
    // completed, and the collateral still has exactly one active holder.
    expect($repayment->fresh()->status)->toBe('posted')
        ->and($loanA->fresh()->status)->toBe('completed');

    expect($this->getJson("/api/collaterals/{$collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $loanB->id, 'loan_account_number' => $loanB->loan_account_number]]);
});

it('still voids a payment when nothing else has taken the collateral', function () {
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $loan = guardApprovedLoan($this->borrower, $this->admin);
    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();
    $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

    $repayment = app(RepaymentService::class)->processRepayment(
        $loan->fresh(), GUARD_FULL_SETTLEMENT, now()->toDateString(), $this->admin,
    );
    expect($loan->fresh()->status)->toBe('completed');

    $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Duplicate entry'])
        ->assertOk();

    // Back to an active status, and the collateral goes back to reporting it.
    $loan->refresh();
    expect($loan->status)->toBe('released')
        ->and($repayment->fresh()->status)->toBe('voided');

    expect($this->getJson("/api/collaterals/{$collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $loan->id, 'loan_account_number' => $loan->loan_account_number]]);
});

it('still voids a payment on a loan that holds no collateral', function () {
    $loan = guardApprovedLoan($this->borrower, $this->admin);
    $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

    $repayment = app(RepaymentService::class)->processRepayment(
        $loan->fresh(), GUARD_FULL_SETTLEMENT, now()->toDateString(), $this->admin,
    );

    $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed twice'])
        ->assertOk();

    expect($loan->fresh()->status)->toBe('released')
        ->and(Repayment::find($repayment->id)->status)->toBe('voided');
});

it('still voids a partial payment, which leaves an already-active loan active', function () {
    // `ongoing` → `ongoing` is not a transition into the active set, so the
    // guard has nothing to say about it and must not invent something.
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $loan = guardApprovedLoan($this->borrower, $this->admin);
    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();
    $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

    $repayment = app(RepaymentService::class)->processRepayment(
        $loan->fresh(), 5000, now()->toDateString(), $this->admin,
    );
    expect($loan->fresh()->status)->toBe('ongoing');

    $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Wrong member'])
        ->assertOk();

    expect($loan->fresh()->status)->toBe('ongoing');
});

// ── 2c. the third path the audit named, which is not one ─────────────────

it('still releases a restructure that inherited its source loan\'s collateral', function () {
    // LoanService::closeRestructuredSource() was reported as a third gap. It is
    // not: the only status write there takes the SOURCE from `released`/`ongoing`
    // into `restructured`, which is outside Loan::ACTIVE_STATUSES, so it frees a
    // collateral rather than taking one. `$previousStatus` beside it is an
    // audit-log field, not a rollback.
    //
    // What it IS is the reason the release guard runs at the END of the release
    // transaction. inheritCollaterals() puts the source's collateral on the
    // restructure on purpose, so both loans hold it between application and
    // release. A guard placed at the status write would reject every restructure
    // release that inherited anything — this test is what fails if anybody moves
    // it there.
    $source = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $source->borrower_id]);

    $this->postJson("/api/loans/{$source->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $restructure = Loan::findOrFail(
        $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => GUARD_FULL_SETTLEMENT,
            'start_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id'),
    );

    // Both loans hold the collateral right now, and that is correct.
    expect($restructure->collaterals()->pluck('collaterals.id')->all())->toBe([$collateral->id])
        ->and($source->collaterals()->pluck('collaterals.id')->all())->toBe([$collateral->id]);

    // Restructure approval is dual control, so the sign-off is a second user.
    $approver = tap(
        User::factory()->create(),
        fn (User $user) => $user->assignRole(Role::where('name', 'admin')->first()),
    );
    $this->patchJson("/api/loans/{$restructure->id}/submit")->assertOk();
    $this->actingAs($approver);
    $this->patchJson("/api/loans/{$restructure->id}/approve", ['approval_remarks' => 'ok'])->assertOk();
    $this->actingAs($this->admin);

    $this->patchJson("/api/loans/{$restructure->id}/release")->assertOk();

    expect($source->fresh()->status)->toBe('restructured')
        ->and($restructure->fresh()->status)->toBe('released');

    expect($this->getJson("/api/collaterals/{$collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $restructure->id, 'loan_account_number' => $restructure->fresh()->loan_account_number]]);
});

// ── 3. index(): filters gated on presence, not truthiness ────────────────

it('refuses borrower_id=0 instead of answering with the whole collateral book', function () {
    Collateral::factory()->count(3)->create(['borrower_id' => $this->stranger->id]);

    $this->getJson('/api/collaterals?borrower_id=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors('borrower_id');
});

it('refuses a zero collateral type filter under either query key', function (string $key) {
    Collateral::factory()->count(3)->create(['borrower_id' => $this->stranger->id]);

    $this->getJson("/api/collaterals?{$key}=0")
        ->assertStatus(422)
        ->assertJsonValidationErrors($key);
})->with(['type', 'collateral_type_id']);

it('filters correctly on a valid borrower id', function () {
    Collateral::factory()->count(2)->create(['borrower_id' => $this->borrower->id]);
    Collateral::factory()->count(3)->create(['borrower_id' => $this->stranger->id]);

    $rows = $this->getJson("/api/collaterals?borrower_id={$this->borrower->id}")->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and(array_unique(array_column($rows, 'borrower_id')))->toBe([$this->borrower->id]);
});

it('filters correctly on a valid collateral type under either query key', function (string $key) {
    // CollateralFactory mints a fresh CollateralType per row, so these three are
    // each of a different type from $wanted by construction.
    $wanted = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    Collateral::factory()->count(3)->create(['borrower_id' => $this->borrower->id]);

    $rows = $this->getJson("/api/collaterals?{$key}={$wanted->collateral_type_id}")->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($wanted->id);
})->with(['type', 'collateral_type_id']);

it('still returns the whole book when no filter is sent', function () {
    Collateral::factory()->count(2)->create(['borrower_id' => $this->borrower->id]);
    Collateral::factory()->count(3)->create(['borrower_id' => $this->stranger->id]);

    expect($this->getJson('/api/collaterals')->assertOk()->json('data'))->toHaveCount(5);
});

it('refuses a non-integer filter rather than silently ignoring it', function () {
    $this->getJson('/api/collaterals?borrower_id=all')
        ->assertStatus(422)
        ->assertJsonValidationErrors('borrower_id');
});

// ── 4. update(): no reassigning a pledged collateral ─────────────────────

it('refuses to move a pledged collateral to another borrower', function () {
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    guardPledgeDirectly(guardLoanInStatus($this->loanDefaults, 'ongoing'), $collateral);

    $this->putJson("/api/collaterals/{$collateral->id}", ['borrower_id' => $this->stranger->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('borrower_id')
        ->assertJsonFragment([
            'borrower_id' => ['This collateral is attached to 1 loan(s) and cannot be moved to another member. Detach it from all loans first.'],
        ]);

    expect($collateral->fresh()->borrower_id)->toBe($this->borrower->id);
});

it('refuses the reassignment however inactive the holding loan is', function (string $status) {
    // The judgement call: the bar is ATTACHED AT ALL, matching destroy(), not the
    // attach guard's narrower "attached to an ACTIVE loan".
    //
    // The pivot row is the historical record of what secured that loan and
    // survives its closure on purpose; `borrower_id` lives on `collaterals`, so
    // moving it would rewrite that record. An active-only bar also leaks
    // straight back into gap 1: reassign a `completed` loan's collateral, attach
    // it elsewhere, then void a payment on the first loan.
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    guardPledgeDirectly(guardLoanInStatus($this->loanDefaults, $status), $collateral);

    $this->putJson("/api/collaterals/{$collateral->id}", ['borrower_id' => $this->stranger->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('borrower_id');

    expect($collateral->fresh()->borrower_id)->toBe($this->borrower->id);
})->with(['draft', 'for_review', 'approved', 'rejected', 'completed', 'defaulted', 'restructured', 'void']);

it('still moves an unattached collateral to another borrower', function () {
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $this->putJson("/api/collaterals/{$collateral->id}", ['borrower_id' => $this->stranger->id])
        ->assertOk();

    expect($collateral->fresh()->borrower_id)->toBe($this->stranger->id);
});

it('still moves a collateral whose only loan link has been detached', function () {
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $loan = guardLoanInStatus($this->loanDefaults, 'ongoing');
    guardPledgeDirectly($loan, $collateral);

    $this->putJson("/api/collaterals/{$collateral->id}", ['borrower_id' => $this->stranger->id])
        ->assertStatus(422);

    $this->deleteJson("/api/loans/{$loan->id}/collaterals/{$collateral->id}")->assertOk();

    $this->putJson("/api/collaterals/{$collateral->id}", ['borrower_id' => $this->stranger->id])
        ->assertOk();

    expect($collateral->fresh()->borrower_id)->toBe($this->stranger->id);
});

it('still edits every other field of a pledged collateral', function () {
    // Non-negotiable: the collateral edit form PUTs the whole payload on every
    // save, `borrower_id` included and unchanged. Treating presence rather than
    // CHANGE as the trigger would make an attached collateral uneditable.
    $collateral = Collateral::factory()->create([
        'borrower_id' => $this->borrower->id,
        'detail_value' => 'TCT-11111',
        'amount' => 250000,
    ]);
    guardPledgeDirectly(guardLoanInStatus($this->loanDefaults, 'ongoing'), $collateral);

    $this->putJson("/api/collaterals/{$collateral->id}", [
        'borrower_id' => $this->borrower->id,
        'collateral_type_id' => $collateral->collateral_type_id,
        'detail_value' => 'TCT-22222',
        'amount' => 400000,
    ])->assertOk();

    $collateral->refresh();
    expect($collateral->detail_value)->toBe('TCT-22222')
        ->and((float) $collateral->amount)->toBe(400000.0)
        ->and($collateral->borrower_id)->toBe($this->borrower->id);
});

// ── the snapshot rule: lock BEFORE the transaction's first plain read ─────

it('locks the collateral before the void transaction reads anything', function () {
    // This is the finding the security review blocked on, expressed as the
    // property that fixes it.
    //
    // reverseAllocation() opens with a plain `->get()` over the schedules. With
    // the collateral lock taken after it — where it originally sat — the guard
    // locked the right rows and then answered from the snapshot that `->get()`
    // had already fixed, so a pledge committed in between was invisible. The
    // exploit: void a payment on a completed loan, and while it is inside
    // reverseAllocation() attach its collateral to another active loan
    // (permitted, because this loan is not active yet). The guard sees no
    // conflict and un-completes the loan onto a collateral now securing two live
    // balances, silently.
    //
    // Asserted structurally rather than by racing two connections: reproducing
    // the interleaving needs the attach to COMMIT mid-transaction, which after
    // the fix would block on the lock this test is checking for — a test that
    // deadlocks against the code it is meant to pass on. The ordering is the
    // property; the race is just its consequence.
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $loan = guardApprovedLoan($this->borrower, $this->admin);
    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();
    $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();

    $repayment = app(RepaymentService::class)->processRepayment(
        $loan->fresh(), GUARD_FULL_SETTLEMENT, now()->toDateString(), $this->admin,
    );

    $order = guardLockOrdering(fn () => $this->patchJson(
        "/api/repayments/{$repayment->id}/void", ['void_reason' => 'Duplicate entry'],
    )->assertOk());

    expect($order['began'])->toBeTrue('voidRepayment() ran outside a transaction')
        ->and($order['lock'])->not->toBeNull('voidRepayment() never locked the collateral row')
        ->and($order['lock'])->toBeLessThan(
            $order['firstPlainRead'] ?? PHP_INT_MAX,
            'the collateral lock came after a plain read, so the guard answers from a pre-lock snapshot',
        );
});

it('locks the collateral before the release transaction reads anything', function () {
    // Same property on the other transition — but be clear about what this one
    // is worth. Run against the pre-hoist code it PASSES, because a plain
    // release happens to issue no plain SELECT before the guard: the source
    // lookup and the loan-account-number read are both locking reads, and the
    // rest is UPDATEs and INSERTs. It was safe by accident, and one `->get()`
    // added anywhere above would have broken it silently.
    //
    // So this is a regression pin, not a reproduction. The two tests that do
    // fail against the old code are the void ordering test above (lock at query
    // 12, first plain read at query 2) and the update lock test below (no
    // transaction at all).
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $loan = guardApprovedLoan($this->borrower, $this->admin);

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $order = guardLockOrdering(fn () => $this->patchJson("/api/loans/{$loan->id}/release")->assertOk());

    expect($order['began'])->toBeTrue('release() ran outside a transaction')
        ->and($order['lock'])->not->toBeNull('release() never locked the collateral row')
        ->and($order['lock'])->toBeLessThan(
            $order['firstPlainRead'] ?? PHP_INT_MAX,
            'the collateral lock came after a plain read, so the guard answers from a pre-lock snapshot',
        );
});

it('locks the collateral before update counts its attachments', function () {
    // update() was check-then-act with no transaction at all: count first, write
    // after, nothing in between. Now it opens with the lock, which also makes the
    // count the transaction's first plain read — so the count is taken of a
    // post-lock world rather than a snapshot that predates it.
    //
    // The payload has to be a borrower_id CHANGE on an UNATTACHED collateral,
    // and nothing else will do. assertBorrowerIsNotBeingReassignedWhilePledged()
    // returns early both when `borrower_id` is absent AND when it is present but
    // equal, so neither an `amount`-only PUT nor a PUT resending the same
    // borrower ever reaches `$collateral->loans()->count()` — the very read this
    // test exists to place. Changed-and-unattached is the one payload that runs
    // the count and still succeeds.
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $order = guardLockOrdering(fn () => $this->putJson(
        "/api/collaterals/{$collateral->id}", ['borrower_id' => $this->stranger->id],
    )->assertOk());

    // Guard rail on the guard rail: if a future refactor makes the count
    // unreachable again, this test would quietly degrade to `lock < PHP_INT_MAX`
    // and assert nothing.
    expect($order['firstPlainRead'])->not->toBeNull(
        'the attachment count never ran, so this test is no longer placing the read it claims to',
    );

    expect($order['began'])->toBeTrue('update() ran outside a transaction')
        ->and($order['lock'])->not->toBeNull('update() never locked the collateral row')
        ->and($order['lock'])->toBeLessThan(
            $order['firstPlainRead'] ?? PHP_INT_MAX,
            'update() counted attachments before locking the row it was about to reassign',
        );
});

it('refuses an attach whose collateral is reassigned between validation and the lock', function () {
    // The other half of the update()/attach() race, from attach()'s side.
    //
    // AttachCollateralRequest settles ownership BEFORE the transaction opens, so
    // a PUT that moves the collateral to another member in that window would
    // otherwise pledge a stranger's asset to this loan — finding 1's outcome,
    // routed around finding 1's guard. The reassignment is landed here exactly
    // in that window, off the validation query itself, so the interleaving is
    // deterministic rather than raced.
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $loan = guardLoanInStatus($this->loanDefaults, 'draft');

    $reassigned = false;
    DB::listen(function ($query) use (&$reassigned, $collateral) {
        if ($reassigned) {
            return;
        }

        $sql = strtolower($query->sql);

        // The form request's narrowed `exists` lookup — the last thing to run
        // before the controller opens its transaction.
        if (str_contains($sql, 'from `collaterals`') && str_contains($sql, 'count(*)')) {
            $reassigned = true;
            DB::table('collaterals')
                ->where('id', $collateral->id)
                ->update(['borrower_id' => $this->stranger->id]);
        }
    });

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertStatus(422)
        ->assertJsonValidationErrors('collateral_id')
        ->assertJsonFragment([
            'collateral_id' => ['This collateral is not registered to this loan\'s borrower.'],
        ]);

    expect($reassigned)->toBeTrue('the reassignment never landed, so this test proved nothing');
    $this->assertDatabaseCount('loan_collaterals', 0);
});

// ── no unguarded back door into an active status ─────────────────────────

it('has no path writing an active loan status outside the ones that are accounted for', function () {
    // The sibling of CollateralActiveLoansTest's pivot-write census, for the
    // other half of the rule. That one enumerates writers into
    // `loan_collaterals`; this one enumerates writers of an ACTIVE loan status,
    // which is the half the original guard could not see and therefore the half
    // a future change is most likely to reintroduce.
    //
    // Per OCCURRENCE, not per file. A file-level census passed a third write
    // inside LoanService.php or RepaymentService.php silently, and those two are
    // exactly the files a fourth path would be added to.
    //
    // Line numbers make this deliberately brittle: an edit ABOVE any of these
    // lines fails the test too. That is a known cost, accepted so that a new
    // writer cannot hide behind an unchanged file list. The matched statement
    // rides along with the line number precisely so the failure diff answers
    // "renumbered or new?" at a glance — if every statement is unchanged and
    // only the numbers moved, just renumber.
    //
    // Three patterns, because this codebase spells a status write three ways:
    //
    //  - Array/mass-assignment, `update([...])` or `create([...])`, either quote
    //    style, with the value possibly computed —
    //    `'status' => $remaining > 0 ? 'ongoing' : 'released'` is how
    //    voidRepayment() spells it. `status` IS in Loan::$fillable, so mass
    //    assignment is a live route.
    //  - Property assignment, `$loan->status = 'ongoing';` — an idiom already
    //    used on schedules in RepaymentService::reverseAllocation(), so it is one
    //    keystroke away from being used on a loan.
    //  - Raw SQL through DB::update/statement/unprepared touching `loans` and an
    //    active status.
    //
    // `whereIn('status', ['released', 'ongoing'])` and Loan::ACTIVE_STATUSES
    // cannot false-positive: they have no `=>` after the column name and no
    // `->status =`.
    //
    // Known blind spots, none of which exist in the codebase today. Listed so
    // that "the census passed" is never mistaken for "there is no other writer":
    //
    //  - A status held in a variable assigned elsewhere:
    //    `$next = 'ongoing'; $loan->update(['status' => $next]);`
    //  - A class constant instead of a literal: `Loan::STATUS_RELEASED`.
    //  - A multi-line array with the key and the literal on separate lines —
    //    the scan is line-by-line, so nothing matches either line alone.
    //  - A ternary whose branches contain a comma, which `[^,\n]*` stops at.
    //
    // No literal-name line scanner can close these. The guard itself is the
    // enforcement; this census is the tripwire that makes a new writer visible
    // in review.
    //
    // One self-reference to be careful of: the expected strings below contain
    // `'status' => 'released'`, so this test file would match its own patterns.
    // It is safe only because the scan is scoped to app_path(). Widen it to
    // base_path() and the census starts reporting itself.
    //
    // Scoped to app/ on purpose. database/seeders legitimately mints demo loans
    // in every status and has no invariant to keep; the rule is about code that
    // MOVES a live loan.
    $arrayWrite = '/["\']status["\']\s*=>\s*[^,\n]*["\'](?:released|ongoing)["\']/';
    $propertyWrite = '/->status\s*=\s*["\'](?:released|ongoing)["\']/';
    $rawWrite = '/DB::(?:update|statement|unprepared)\(.*(?:released|ongoing)/';

    $occurrences = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());

        foreach (file($file->getPathname()) as $index => $line) {
            $isWrite = preg_match($arrayWrite, $line)
                || preg_match($propertyWrite, $line)
                || preg_match($rawWrite, $line);

            if ($isWrite) {
                // The statement text rides along with the line number so a
                // failure says at a glance whether this is a genuinely new
                // writer or just the same two shifted down by an edit above.
                $occurrences[] = $relative.':'.($index + 1).' — '.trim($line);
            }
        }
    }

    sort($occurrences);

    expect($occurrences)->toBe([
        // CsvImportProcessor::importLoanRow(): a migrated loan is INSERTED
        // straight as `ongoing`. Deliberately UNGUARDED, and it is the one case
        // where that is provable rather than argued: this is a Loan::create(),
        // so the loan holds no collateral at the moment it becomes active —
        // `loan_collaterals` is a pivot and the importer never writes it, the
        // migration CSV having no collateral column at all. A loan with zero
        // collaterals cannot become the second active holder of anything.
        //
        // The moment this importer learns to attach collateral, that stops
        // being true and this line has to move to the guarded list.
        'app/Services/CsvImport/CsvImportProcessor.php:968 — \'status\' => \'ongoing\',',
        // release(): approved → released. Its lock is the first statement of the
        // transaction; the assertion runs at the end, after
        // closeRestructuredSource() has taken any restructure source out of the
        // active set.
        'app/Services/LoanService.php:626 — \'status\' => \'released\',',
        // processRepayment(): released → ongoing. Deliberately UNGUARDED — both
        // are already active, so it cannot add a holder.
        'app/Services/RepaymentService.php:185 — $loan->update([\'status\' => \'ongoing\']);',
        // voidRepayment(): completed → ongoing/released. Guarded before the
        // status write, off a lock taken at the top of the transaction.
        'app/Services/RepaymentService.php:348 — $loan->update([\'status\' => $remainingPayments > 0 ? \'ongoing\' : \'released\']);',
    ], 'a new writer of an active loan status appeared; open its transaction with CollateralPledgeGuard::lockCollateralsOf() and call CollateralPledgeGuard::assertNoDoublePledge() before the write, or account for it here and say why it cannot add a second active holder');
});
