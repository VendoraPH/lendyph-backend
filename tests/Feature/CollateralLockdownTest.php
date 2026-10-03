<?php

/**
 * Collateral changes, locked down to the loans that can still change.
 *
 *   1. Attach, detach and the `collaterals` list on PUT /loans/{loan} only while
 *      the loan is editable (draft, for_review), read again from the loan's
 *      locked row inside the transaction.
 *   2. A collateral's amount cannot change while an approved or still-owing
 *      loan holds it.
 *   3. Every attach, detach and value change leaves one audit row per loan,
 *      naming who made it, on every path that makes it.
 *   4. One lock order on all of those paths: the collateral rows in a single
 *      id-ordered statement, then the loan rows.
 *
 * The 409 for a deadlock is pinned in CollateralWriteConflictTest, which has to
 * run outside the per-test transaction to see the rollback.
 */

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

dataset('lockdown statuses that are not editable', ['approved', 'rejected', 'released', 'ongoing', 'completed', 'defaulted', 'restructured', 'void']);
dataset('lockdown editable statuses', ['draft', 'for_review']);

beforeEach(function () {
    $this->seedAndLogin();

    $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $this->loanDefaults = [
        'borrower_id' => $this->borrower->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'branch_id' => $this->branch->id,
        'created_by' => $this->admin->id,
    ];
    $this->collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id, 'amount' => 250000]);

    // Everything a refused request must leave exactly as it found it.
    $this->state = fn (): array => [
        'pivot' => DB::table('loan_collaterals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'collaterals' => DB::table('collaterals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'loans' => DB::table('loans')->orderBy('id')->get(['id', 'status', 'purpose'])->map(fn (object $row): array => (array) $row)->all(),
        'audit' => AuditLog::count(),
    ];

    $this->auditRows = fn (string $action, int $after = 0) => AuditLog::where('action', $action)
        ->where('id', '>', $after)
        ->orderBy('id')
        ->get();
});

/**
 * A loan in an exact status, cheaply. Named apart from the helpers in the other
 * collateral test files because Pest declares every file's functions globally.
 */
function lockdownLoan(array $defaults, string $status): Loan
{
    static $serial = 700;
    $serial++;

    return Loan::factory()->create(array_merge($defaults, [
        'status' => $status,
        'loan_account_number' => 'LN-'.str_pad((string) $serial, 6, '0', STR_PAD_LEFT),
    ]));
}

/**
 * Write the pivot row directly, to build a state the endpoints now refuse to.
 */
function lockdownPledge(Loan $loan, Collateral $collateral, float $snapshotValue = 100.0): void
{
    $loan->collaterals()->attach($collateral->id, [
        'snapshot_value' => $snapshotValue,
        'attached_at' => now(),
    ]);
}

/**
 * Change the loan's status the moment the request takes its collateral lock:
 * after any check made before the transaction, before the loan row is read
 * under its own lock. A status change committed by another request in that
 * window is exactly what the in-transaction check exists to see.
 */
function lockdownChangeStatusAtTheCollateralLock(Loan $loan, string $status): void
{
    $changed = false;

    DB::listen(function (QueryExecuted $query) use (&$changed, $loan, $status) {
        $sql = strtolower($query->sql);

        if ($changed || ! str_contains($sql, 'from `collaterals`') || ! str_contains($sql, 'for update')) {
            return;
        }

        $changed = true;
        DB::table('loans')->where('id', $loan->id)->update(['status' => $status]);
    });
}

/**
 * The locking reads `$act` issues inside its transaction, in order.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function lockdownLockingReads(callable $act): array
{
    $recording = false;
    $reads = [];

    Event::listen(TransactionBeginning::class, function () use (&$recording) {
        $recording = true;
    });

    DB::listen(function (QueryExecuted $query) use (&$recording, &$reads) {
        $sql = strtolower($query->sql);

        if ($recording && str_contains($sql, 'for update')) {
            $reads[] = ['sql' => $sql, 'bindings' => $query->bindings];
        }
    });

    $act();
    $recording = false;

    return $reads;
}

/**
 * Assert the one lock order: a single id-ordered statement on `collaterals`,
 * holding `$collateralIds`, before the first lock on `loans`, and no locking
 * read of `loan_collaterals` at all.
 *
 * That last part is what lets these paths queue. A locking read of a loan's
 * pledges takes gap locks, which do not conflict with each other, so two
 * writers both get them and then deadlock on their own inserts into the gap.
 * A loan's pledges need no lock of their own: every writer of them holds the
 * loan's row lock.
 *
 * @param  list<array{sql: string, bindings: array<int, mixed>}>  $reads
 * @param  list<int>  $collateralIds
 */
function lockdownAssertLockOrder(array $reads, array $collateralIds): void
{
    $collateralLocks = array_keys(array_filter($reads, fn (array $read): bool => str_contains($read['sql'], 'from `collaterals`')));
    $loanLocks = array_keys(array_filter($reads, fn (array $read): bool => str_contains($read['sql'], 'from `loans`')));

    $pledgeLocks = array_filter($reads, fn (array $read): bool => str_contains($read['sql'], '`loan_collaterals`'));

    expect($pledgeLocks)->toBeEmpty('loan_collaterals was read with a lock, which takes gap locks that deadlock concurrent pledge writes')
        ->and($collateralLocks)->toHaveCount(1, 'the collateral rows were locked in more than one statement')
        ->and($loanLocks)->not->toBeEmpty('the loan row was never locked')
        ->and($collateralLocks[0])->toBeLessThan($loanLocks[0], 'the loan was locked before its collateral');

    sort($collateralIds);

    expect(lockdownLockedIds($reads[$collateralLocks[0]]))->toBe($collateralIds);

    if (count($collateralIds) > 1) {
        expect($reads[$collateralLocks[0]]['sql'])->toContain('order by `id` asc');
    }
}

/**
 * The ids a locking read asks for, whether bound (`= ?`, `in (?, ?)`) or
 * inlined, as whereKey() inlines integer keys.
 *
 * @param  array{sql: string, bindings: array<int, mixed>}  $read
 * @return list<int>
 */
function lockdownLockedIds(array $read): array
{
    $bindings = $read['bindings'];
    $sql = preg_replace_callback('/\?/', function () use (&$bindings): string {
        return (string) array_shift($bindings);
    }, $read['sql']);

    preg_match('/`id` (?:= (\d+)|in \(([\d, ]+)\))/', $sql, $match);

    return array_map('intval', explode(',', $match[1] !== '' ? $match[1] : $match[2]));
}

/**
 * A real loan, built the way an operator builds one: collateral attached while
 * it is a draft, then submitted, approved, released, and paid once, which moves
 * it to `ongoing`. Returns the loan and the collateral it holds.
 *
 * @return array{0: Loan, 1: Collateral}
 */
function lockdownOngoingLoanHoldingCollateral(TestCase $test, Borrower $borrower, $admin, float $snapshotValue): array
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
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
    ], $admin);

    $collateral = Collateral::factory()->create(['borrower_id' => $borrower->id, 'amount' => 300000]);
    $test->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => $snapshotValue,
    ])->assertCreated();

    $service->submitForReview($loan);
    $service->approve($loan, $admin, 'Approved for testing');
    $service->release($loan->fresh(), $admin);

    $test->postJson("/api/loans/{$loan->id}/repayments", [
        'payment_date' => now()->toDateString(),
        'amount_paid' => 5000,
        'method' => 'cash',
    ])->assertCreated();

    $loan->refresh();
    expect($loan->status)->toBe('ongoing');

    return [$loan, $collateral];
}

/**
 * Restructure `$source` through the real endpoint at its whole outstanding
 * balance — ₱70,800 owed less the ₱5,000 paid — and return the new draft.
 */
function lockdownRestructure(TestCase $test, Loan $source): Loan
{
    $response = $test->postJson("/api/loans/{$source->id}/restructure", [
        'borrower_id' => $source->borrower_id,
        'loan_product_id' => $source->loan_product_id,
        'principal_amount' => 65800,
        'start_date' => now()->toDateString(),
    ])->assertCreated();

    return Loan::findOrFail($response->json('data.id'));
}

// ── 1. attach, detach and the list only while the loan is editable ───────

it('refuses an attach to a loan that is no longer editable, and writes nothing', function (string $status) {
    $loan = lockdownLoan($this->loanDefaults, $status);
    $before = ($this->state)();

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 250000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'status' => 'Collateral can only be attached or detached while the loan is in draft or for_review status.',
        ]);

    expect(($this->state)())->toBe($before);
})->with('lockdown statuses that are not editable');

it('refuses a detach from a loan that is no longer editable, and writes nothing', function (string $status) {
    $loan = lockdownLoan($this->loanDefaults, $status);
    lockdownPledge($loan, $this->collateral, 250000);
    $before = ($this->state)();

    $this->deleteJson("/api/loans/{$loan->id}/collaterals/{$this->collateral->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'status' => 'Collateral can only be attached or detached while the loan is in draft or for_review status.',
        ]);

    expect(($this->state)())->toBe($before);
})->with('lockdown statuses that are not editable');

it('refuses a collateral list on a loan that is no longer editable, and writes nothing', function (string $status) {
    $loan = lockdownLoan($this->loanDefaults, $status);
    lockdownPledge($loan, $this->collateral, 250000);
    $added = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $before = ($this->state)();

    $this->putJson("/api/loans/{$loan->id}", [
        'purpose' => 'Changed',
        'collaterals' => [['collateral_id' => $added->id, 'snapshot_value' => 1000]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(($this->state)())->toBe($before);
})->with('lockdown statuses that are not editable');

it('reads the loan status again under its lock, so an attach cannot slip past a status change', function () {
    $loan = lockdownLoan($this->loanDefaults, 'for_review');
    lockdownChangeStatusAtTheCollateralLock($loan, 'approved');

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 250000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'status' => 'Collateral can only be attached or detached while the loan is in draft or for_review status.',
        ]);

    expect($loan->collaterals()->count())->toBe(0)
        ->and(($this->auditRows)('collateral_attached'))->toBeEmpty();
});

it('reads the loan status again under its lock, so a detach cannot slip past a status change', function () {
    $loan = lockdownLoan($this->loanDefaults, 'for_review');
    lockdownPledge($loan, $this->collateral, 250000);
    lockdownChangeStatusAtTheCollateralLock($loan, 'approved');

    $this->deleteJson("/api/loans/{$loan->id}/collaterals/{$this->collateral->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($loan->collaterals()->pluck('collaterals.id')->all())->toBe([$this->collateral->id])
        ->and(($this->auditRows)('collateral_detached'))->toBeEmpty();
});

it('reads the loan status again under its lock, so a loan edit cannot change collateral past a status change', function () {
    // The status check updateLoan() makes before its transaction passes here:
    // the loan is still for_review when the request arrives. Only the read
    // made under the loan's row lock sees the approval.
    $loan = lockdownLoan($this->loanDefaults, 'for_review');
    lockdownPledge($loan, $this->collateral, 250000);
    $added = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    lockdownChangeStatusAtTheCollateralLock($loan, 'approved');

    $this->putJson("/api/loans/{$loan->id}", [
        'purpose' => 'Changed',
        'collaterals' => [['collateral_id' => $added->id, 'snapshot_value' => 1000]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'status' => 'Collateral can only be attached or detached while the loan is in draft or for_review status.',
        ]);

    expect($loan->collaterals()->pluck('collaterals.id')->all())->toBe([$this->collateral->id])
        ->and($loan->fresh()->purpose)->not->toBe('Changed')
        ->and(($this->auditRows)('collateral_attached'))->toBeEmpty()
        ->and(($this->auditRows)('collateral_detached'))->toBeEmpty();
});

it('still attaches to an editable loan and records who did it', function (string $status) {
    $loan = lockdownLoan($this->loanDefaults, $status);

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 250000.5,
    ])->assertCreated();

    $entry = ($this->auditRows)('collateral_attached')->sole();

    expect($loan->collaterals()->pluck('collaterals.id')->all())->toBe([$this->collateral->id])
        ->and($entry->auditable_type)->toBe(Loan::class)
        ->and($entry->auditable_id)->toBe($loan->id)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->old_values)->toEqual(['collateral_id' => $this->collateral->id, 'snapshot_value' => null])
        ->and($entry->new_values)->toEqual(['collateral_id' => $this->collateral->id, 'snapshot_value' => 250000.5]);
})->with('lockdown editable statuses');

it('still detaches from an editable loan and records who did it', function (string $status) {
    $loan = lockdownLoan($this->loanDefaults, $status);
    lockdownPledge($loan, $this->collateral, 180000.25);

    $this->deleteJson("/api/loans/{$loan->id}/collaterals/{$this->collateral->id}")->assertOk();

    $entry = ($this->auditRows)('collateral_detached')->sole();

    expect($loan->collaterals()->count())->toBe(0)
        ->and($entry->auditable_type)->toBe(Loan::class)
        ->and($entry->auditable_id)->toBe($loan->id)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->old_values)->toEqual(['collateral_id' => $this->collateral->id, 'snapshot_value' => 180000.25])
        ->and($entry->new_values)->toEqual(['collateral_id' => $this->collateral->id, 'snapshot_value' => null]);
})->with('lockdown editable statuses');

it('still answers a detach of a collateral the loan does not hold with a 422', function () {
    $loan = lockdownLoan($this->loanDefaults, 'draft');

    $this->deleteJson("/api/loans/{$loan->id}/collaterals/{$this->collateral->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['collateral' => 'This collateral is not attached to the loan.']);

    expect(($this->auditRows)('collateral_detached'))->toBeEmpty();
});

it('records one row per collateral a loan edit attaches or detaches, and none for the ones it keeps', function (string $status) {
    $loan = lockdownLoan($this->loanDefaults, $status);
    $kept = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $dropped = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $added = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    lockdownPledge($loan, $kept, 150000);
    lockdownPledge($loan, $dropped, 80000);

    $this->putJson("/api/loans/{$loan->id}", [
        'collaterals' => [
            ['collateral_id' => $kept->id, 'snapshot_value' => 999],
            ['collateral_id' => $added->id, 'snapshot_value' => 45000.5],
        ],
    ])->assertOk();

    $attached = ($this->auditRows)('collateral_attached')->sole();
    $detached = ($this->auditRows)('collateral_detached')->sole();

    expect($loan->collaterals()->orderBy('collaterals.id')->pluck('collaterals.id')->all())->toBe([$kept->id, $added->id])
        ->and($attached->auditable_id)->toBe($loan->id)
        ->and($attached->user_id)->toBe($this->admin->id)
        ->and($attached->old_values)->toEqual(['collateral_id' => $added->id, 'snapshot_value' => null])
        ->and($attached->new_values)->toEqual(['collateral_id' => $added->id, 'snapshot_value' => 45000.5])
        ->and($detached->auditable_id)->toBe($loan->id)
        ->and($detached->user_id)->toBe($this->admin->id)
        ->and($detached->old_values)->toEqual(['collateral_id' => $dropped->id, 'snapshot_value' => 80000])
        ->and($detached->new_values)->toEqual(['collateral_id' => $dropped->id, 'snapshot_value' => null])
        ->and(AuditLog::where('action', 'collaterals_updated')->count())->toBe(0);
})->with('lockdown editable statuses');

// ── the restructure copy ─────────────────────────────────────────────────

it('records one attach row per collateral a restructure carries onto its draft', function () {
    [$source, $collateral] = lockdownOngoingLoanHoldingCollateral($this, $this->borrower, $this->admin, 275000.75);
    $second = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    lockdownPledge($source, $second, 50000);
    $after = (int) AuditLog::max('id');

    $draft = lockdownRestructure($this, $source);

    $entries = ($this->auditRows)('collateral_attached', $after);

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('auditable_id')->unique()->all())->toBe([$draft->id])
        ->and($entries->pluck('auditable_type')->unique()->all())->toBe([Loan::class])
        ->and($entries->pluck('user_id')->unique()->all())->toBe([$this->admin->id])
        ->and($entries->map(fn (AuditLog $entry): array => [$entry->old_values, $entry->new_values])->all())->toEqual([
            [['collateral_id' => $collateral->id, 'snapshot_value' => null], ['collateral_id' => $collateral->id, 'snapshot_value' => 275000.75]],
            [['collateral_id' => $second->id, 'snapshot_value' => null], ['collateral_id' => $second->id, 'snapshot_value' => 50000]],
        ]);
});

it('lets a restructure draft re-send the collateral it inherited, and changes nothing', function () {
    [$source, $collateral] = lockdownOngoingLoanHoldingCollateral($this, $this->borrower, $this->admin, 275000.75);
    $draft = lockdownRestructure($this, $source);
    $carried = DB::table('loan_collaterals')->where('loan_id', $draft->id)->sole();
    $attachedRows = ($this->auditRows)('collateral_attached')->count();

    // Any snapshot value: one the draft already holds is not re-appraised.
    $this->putJson("/api/loans/{$draft->id}", [
        'collaterals' => [['collateral_id' => $collateral->id, 'snapshot_value' => 1]],
    ])->assertOk();

    $rows = DB::table('loan_collaterals')->where('loan_id', $draft->id)->get();

    expect($rows)->toHaveCount(1)
        ->and((array) $rows->first())->toBe((array) $carried)
        ->and((float) $rows->first()->snapshot_value)->toBe(275000.75)
        ->and($source->collaterals()->pluck('collaterals.id')->all())->toBe([$collateral->id])
        ->and(($this->auditRows)('collateral_attached')->count())->toBe($attachedRows)
        ->and(($this->auditRows)('collateral_detached'))->toBeEmpty();
});

// ── 2. the amount, while a live loan holds the collateral ────────────────

it('refuses to change the amount of a collateral an approved or unpaid loan holds', function (string $status) {
    $loan = lockdownLoan($this->loanDefaults, $status);
    lockdownPledge($loan, $this->collateral, 250000);
    $before = ($this->state)();

    $this->putJson("/api/collaterals/{$this->collateral->id}", ['amount' => 300000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);

    expect(($this->state)())->toBe($before)
        ->and((float) $this->collateral->fresh()->amount)->toBe(250000.0);
})->with(['approved', 'released', 'ongoing', 'defaulted']);

it('names the live loan when one of several holders is still live', function () {
    $finished = lockdownLoan($this->loanDefaults, 'completed');
    $live = lockdownLoan($this->loanDefaults, 'ongoing');
    lockdownPledge($finished, $this->collateral);
    lockdownPledge($live, $this->collateral);

    $response = $this->putJson("/api/collaterals/{$this->collateral->id}", ['amount' => 300000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => $live->loan_account_number]);

    expect($response->json('errors.amount.0'))->not->toContain($finished->loan_account_number)
        ->and((float) $this->collateral->fresh()->amount)->toBe(250000.0)
        ->and(($this->auditRows)('collateral_value_changed'))->toBeEmpty();
});

it('still changes the amount while only a draft, an application or a finished loan holds it, recording it on that loan', function (string $status) {
    $loan = lockdownLoan($this->loanDefaults, $status);
    lockdownPledge($loan, $this->collateral, 250000);

    $this->putJson("/api/collaterals/{$this->collateral->id}", ['amount' => 300000.4])->assertOk();

    $entry = ($this->auditRows)('collateral_value_changed')->sole();

    expect((float) $this->collateral->fresh()->amount)->toBe(300000.4)
        ->and($entry->auditable_type)->toBe(Loan::class)
        ->and($entry->auditable_id)->toBe($loan->id)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->old_values)->toEqual(['collateral_id' => $this->collateral->id, 'amount' => 250000])
        ->and($entry->new_values)->toEqual(['collateral_id' => $this->collateral->id, 'amount' => 300000.4])
        // The pledge keeps the value it was struck at.
        ->and((float) DB::table('loan_collaterals')->where('loan_id', $loan->id)->value('snapshot_value'))->toBe(250000.0);
})->with(['draft', 'for_review', 'completed', 'restructured', 'rejected', 'void']);

it('records a value change on every loan that holds the collateral', function () {
    $draft = lockdownLoan($this->loanDefaults, 'draft');
    $completed = lockdownLoan($this->loanDefaults, 'completed');
    lockdownPledge($draft, $this->collateral);
    lockdownPledge($completed, $this->collateral);

    $this->putJson("/api/collaterals/{$this->collateral->id}", ['amount' => 260000])->assertOk();

    $entries = ($this->auditRows)('collateral_value_changed');

    expect($entries->pluck('auditable_id')->sort()->values()->all())->toBe(collect([$draft->id, $completed->id])->sort()->values()->all())
        ->and($entries->pluck('user_id')->unique()->all())->toBe([$this->admin->id])
        ->and($entries->pluck('new_values')->unique()->values()->all())->toEqual([['collateral_id' => $this->collateral->id, 'amount' => 260000]]);
});

it('still changes the amount of a collateral no loan holds, leaving only the collateral\'s own audit row', function () {
    $this->putJson("/api/collaterals/{$this->collateral->id}", ['amount' => 300000])->assertOk();

    expect((float) $this->collateral->fresh()->amount)->toBe(300000.0)
        ->and(($this->auditRows)('collateral_value_changed'))->toBeEmpty()
        ->and(AuditLog::where('action', 'updated')
            ->where('auditable_type', Collateral::class)
            ->where('auditable_id', $this->collateral->id)
            ->exists())->toBeTrue();
});

it('lets the edit form re-send an unchanged amount for a collateral a live loan holds', function (mixed $amount) {
    // The form PUTs every field on every save, `amount` included. Only a
    // change to a different figure, to the centavo, is refused.
    $loan = lockdownLoan($this->loanDefaults, 'released');
    lockdownPledge($loan, $this->collateral, 250000);

    $this->putJson("/api/collaterals/{$this->collateral->id}", [
        'borrower_id' => $this->borrower->id,
        'collateral_type_id' => $this->collateral->collateral_type_id,
        'detail_value' => 'TCT-99999',
        'amount' => $amount,
    ])->assertOk();

    expect($this->collateral->fresh()->detail_value)->toBe('TCT-99999')
        ->and((float) $this->collateral->fresh()->amount)->toBe(250000.0)
        ->and(($this->auditRows)('collateral_value_changed'))->toBeEmpty();
})->with([250000, '250000', '250000.00', 250000.001]);

it('judges an amount change the way the column will store it, to the centavo', function () {
    // 300000.035 is stored as 300000.04 by the decimal(14, 2) column, so it is
    // a change, though float arithmetic (300000.035 * 100 = 30000003.4999…)
    // rounds it back to the 300000.03 already held.
    $this->collateral->update(['amount' => '300000.03']);
    $loan = lockdownLoan($this->loanDefaults, 'released');
    lockdownPledge($loan, $this->collateral, 300000.03);

    $this->putJson("/api/collaterals/{$this->collateral->id}", ['amount' => 300000.035])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);

    expect(DB::table('collaterals')->where('id', $this->collateral->id)->value('amount'))->toBe('300000.03')
        ->and(($this->auditRows)('collateral_value_changed'))->toBeEmpty();
});

it('records a half-centavo change as the centavo the column stores', function () {
    $this->collateral->update(['amount' => '300000.03']);
    $loan = lockdownLoan($this->loanDefaults, 'draft');
    lockdownPledge($loan, $this->collateral, 300000.03);

    $this->putJson("/api/collaterals/{$this->collateral->id}", ['amount' => 300000.035])->assertOk();

    $entry = ($this->auditRows)('collateral_value_changed')->sole();

    expect(DB::table('collaterals')->where('id', $this->collateral->id)->value('amount'))->toBe('300000.04')
        ->and($entry->auditable_id)->toBe($loan->id)
        ->and($entry->old_values)->toEqual(['collateral_id' => $this->collateral->id, 'amount' => 300000.03])
        ->and($entry->new_values)->toEqual(['collateral_id' => $this->collateral->id, 'amount' => 300000.04]);
});

it('still edits the other fields of a collateral a live loan holds', function () {
    $loan = lockdownLoan($this->loanDefaults, 'ongoing');
    lockdownPledge($loan, $this->collateral, 250000);
    $otherType = Collateral::factory()->create()->collateral_type_id;

    $this->putJson("/api/collaterals/{$this->collateral->id}", [
        'collateral_type_id' => $otherType,
        'detail_value' => 'TCT-55555',
    ])->assertOk();

    expect($this->collateral->fresh()->collateral_type_id)->toBe($otherType)
        ->and($this->collateral->fresh()->detail_value)->toBe('TCT-55555');
});

// ── deleting a draft takes its collateral off through the same path ─────

it('records each collateral a deleted draft held as detached, by whoever deleted it', function () {
    $loan = lockdownLoan($this->loanDefaults, 'draft');
    $second = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    lockdownPledge($loan, $this->collateral, 150000);
    lockdownPledge($loan, $second, 80000.25);

    $this->deleteJson("/api/loans/{$loan->id}")
        ->assertOk()
        ->assertExactJson(['message' => 'Loan deleted successfully.']);

    $detached = ($this->auditRows)('collateral_detached');

    expect(Loan::find($loan->id))->toBeNull()
        ->and(DB::table('loan_collaterals')->where('loan_id', $loan->id)->count())->toBe(0)
        ->and($detached)->toHaveCount(2)
        ->and($detached->pluck('auditable_type')->unique()->all())->toBe([Loan::class])
        ->and($detached->pluck('auditable_id')->unique()->all())->toBe([$loan->id])
        ->and($detached->pluck('user_id')->unique()->all())->toBe([$this->admin->id])
        ->and($detached->map(fn (AuditLog $entry): array => [$entry->old_values, $entry->new_values])->all())->toEqual([
            [['collateral_id' => $this->collateral->id, 'snapshot_value' => 150000], ['collateral_id' => $this->collateral->id, 'snapshot_value' => null]],
            [['collateral_id' => $second->id, 'snapshot_value' => 80000.25], ['collateral_id' => $second->id, 'snapshot_value' => null]],
        ])
        // The loan's own row, as before.
        ->and(AuditLog::where('action', 'deleted')
            ->where('auditable_type', Loan::class)
            ->where('auditable_id', $loan->id)
            ->exists())->toBeTrue();
});

it('still refuses to delete a loan that is not a draft, and writes nothing', function (string $status) {
    $loan = lockdownLoan($this->loanDefaults, $status);
    lockdownPledge($loan, $this->collateral, 150000);
    $before = ($this->state)();

    $this->deleteJson("/api/loans/{$loan->id}")
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Only draft loans can be deleted.']);

    expect(($this->state)())->toBe($before);
})->with(['for_review', 'approved', 'rejected', 'released', 'ongoing', 'completed', 'defaulted', 'restructured', 'void']);

it('reads the loan status again under its lock, so a delete cannot slip past a status change', function () {
    $loan = lockdownLoan($this->loanDefaults, 'draft');
    lockdownPledge($loan, $this->collateral, 150000);
    $before = ($this->state)();
    lockdownChangeStatusAtTheCollateralLock($loan, 'for_review');

    $this->deleteJson("/api/loans/{$loan->id}")
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Only draft loans can be deleted.']);

    expect(($this->state)())->toBe($before);
});

// ── 4. the lock order ────────────────────────────────────────────────────

it('locks the collateral, then the loan, on an attach', function () {
    $loan = lockdownLoan($this->loanDefaults, 'draft');

    $reads = lockdownLockingReads(fn () => $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated());

    lockdownAssertLockOrder($reads, [$this->collateral->id]);
});

it('locks the collateral, then the loan, on a detach', function () {
    $loan = lockdownLoan($this->loanDefaults, 'draft');
    lockdownPledge($loan, $this->collateral);

    $reads = lockdownLockingReads(fn () => $this->deleteJson("/api/loans/{$loan->id}/collaterals/{$this->collateral->id}")->assertOk());

    lockdownAssertLockOrder($reads, [$this->collateral->id]);
});

it('locks what a loan edit holds and what it lists in one id-ordered statement, then the loan', function () {
    // Ids that interleave: the loan holds the lowest and the highest, the list
    // adds the one in between. Two separately ordered statements would take
    // them out of id order.
    $loan = lockdownLoan($this->loanDefaults, 'draft');
    $low = $this->collateral;
    $middle = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $high = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    lockdownPledge($loan, $low);
    lockdownPledge($loan, $high);

    $reads = lockdownLockingReads(fn () => $this->putJson("/api/loans/{$loan->id}", [
        'collaterals' => [
            ['collateral_id' => $high->id, 'snapshot_value' => 100],
            ['collateral_id' => $middle->id, 'snapshot_value' => 100],
        ],
    ])->assertOk());

    lockdownAssertLockOrder($reads, [$low->id, $middle->id, $high->id]);
});

it('refuses a loan edit whose loan gained an unlisted collateral after the list was read', function () {
    // The list's pledges are read before its transaction, so that the read
    // takes no lock. One attached in between would be detached without its
    // collateral row lock; the edit is refused as a conflict instead.
    $loan = lockdownLoan($this->loanDefaults, 'draft');
    $listed = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $before = ($this->state)();

    $slippedIn = false;
    DB::listen(function (QueryExecuted $query) use (&$slippedIn, $loan) {
        $sql = strtolower($query->sql);

        if ($slippedIn || ! str_contains($sql, 'from `collaterals`') || ! str_contains($sql, 'for update')) {
            return;
        }

        $slippedIn = true;
        lockdownPledge($loan, $this->collateral, 250000);
    });

    $this->putJson("/api/loans/{$loan->id}", [
        'purpose' => 'Changed',
        'collaterals' => [['collateral_id' => $listed->id, 'snapshot_value' => 1000]],
    ])->assertStatus(409)
        ->assertExactJson(['message' => 'Another change to this collateral was saved at the same time. Reload and try again.']);

    expect($slippedIn)->toBeTrue('the pledge never slipped in, so this test proved nothing')
        ->and(($this->state)())->toBe($before);
});

it('locks the source loan\'s collateral, then the source loan, on a restructure', function () {
    [$source, $collateral] = lockdownOngoingLoanHoldingCollateral($this, $this->borrower, $this->admin, 275000.75);
    $second = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    lockdownPledge($source, $second, 50000);

    $reads = lockdownLockingReads(fn () => lockdownRestructure($this, $source));

    lockdownAssertLockOrder($reads, [$collateral->id, $second->id]);

    $sourceLock = collect($reads)->first(fn (array $read): bool => str_contains($read['sql'], 'from `loans`'));

    expect(lockdownLockedIds($sourceLock))->toBe([$source->id]);
});

it('locks the collateral, then its loans in id order, on a value change', function () {
    $first = lockdownLoan($this->loanDefaults, 'completed');
    $second = lockdownLoan($this->loanDefaults, 'draft');
    lockdownPledge($second, $this->collateral);
    lockdownPledge($first, $this->collateral);

    $reads = lockdownLockingReads(fn () => $this->putJson("/api/collaterals/{$this->collateral->id}", ['amount' => 300000])->assertOk());

    lockdownAssertLockOrder($reads, [$this->collateral->id]);

    $loanLock = collect($reads)->first(fn (array $read): bool => str_contains($read['sql'], 'from `loans`'));

    expect($loanLock['sql'])->toContain('order by `id` asc')
        ->and(lockdownLockedIds($loanLock))->toBe([$first->id, $second->id]);
});

it('locks the collateral, then the loan, on a loan delete', function () {
    $loan = lockdownLoan($this->loanDefaults, 'draft');
    $second = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    lockdownPledge($loan, $second);
    lockdownPledge($loan, $this->collateral);

    $reads = lockdownLockingReads(fn () => $this->deleteJson("/api/loans/{$loan->id}")->assertOk());

    lockdownAssertLockOrder($reads, [$this->collateral->id, $second->id]);
});
