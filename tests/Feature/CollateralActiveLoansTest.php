<?php

/**
 * The contract the collateral screens need to answer "is this collateral already
 * pledged?" without fanning out over the loan list — plus the guard that makes
 * that answer worth trusting.
 *
 * Two things are being pinned here:
 *
 *   1. `active_loans` on CollateralResource — an ARRAY of {id, loan_account_number}
 *      for every holder in Loan::ACTIVE_STATUSES, computed by the database in one
 *      join instead of by the client in one request per loan.
 *   2. attach() refuses a collateral another active loan already holds, so the
 *      badge the client renders is a report on an enforced rule and not a
 *      decoration over an unenforced one.
 */

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();

    $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $this->collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    // SetupLendyPH keeps $branch and $admin protected, so the shared loan
    // attributes are stashed here for the file-level helper below.
    $this->loanDefaults = [
        'borrower_id' => $this->borrower->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'branch_id' => $this->branch->id,
        'created_by' => $this->admin->id,
    ];
});

/**
 * A loan in an exact status, cheaply.
 *
 * The lifecycle helper (createReleasedLoan) cannot produce `completed`,
 * `defaulted` or `void`, and nothing under test here reads a loan attribute
 * other than `status` and `loan_account_number`.
 */
function loanInStatus(array $defaults, string $status): Loan
{
    static $serial = 0;
    $serial++;

    return Loan::factory()->create(array_merge($defaults, [
        'status' => $status,
        'loan_account_number' => 'LN-'.str_pad((string) $serial, 6, '0', STR_PAD_LEFT),
    ]));
}

/**
 * Write the pivot row directly, deliberately bypassing the attach guard.
 *
 * Used to build the states the guard cannot itself produce — most importantly a
 * collateral on two active loans, which is what a draft loan being RELEASED
 * while another active loan holds the same collateral leaves behind.
 */
function pledgeDirectly(Loan $loan, Collateral $collateral, float $snapshotValue = 100.0): void
{
    $loan->collaterals()->attach($collateral->id, [
        'snapshot_value' => $snapshotValue,
        'attached_at' => now(),
    ]);
}

/**
 * What createReleasedLoan() leaves owed: ₱60,000 principal + ₱10,800 interest
 * over six monthly periods. Restructuring the exact figure keeps these tests off
 * the shortfall path, which needs remarks and `loans:write_off`.
 */
const SOURCE_OUTSTANDING = 70800.00;

function restructureOf(TestCase $test, Loan $source): Loan
{
    $response = $test->postJson("/api/loans/{$source->id}/restructure", [
        'borrower_id' => $source->borrower_id,
        'loan_product_id' => $source->loan_product_id,
        'principal_amount' => SOURCE_OUTSTANDING,
        'start_date' => now()->toDateString(),
    ])->assertCreated();

    return Loan::findOrFail($response->json('data.id'));
}

/**
 * Restructure approval is dual control, so the sign-off is a second user.
 */
function releaseViaApi(TestCase $test, Loan $loan, User $actingAdmin): void
{
    $approver = tap(
        User::factory()->create(),
        fn (User $user) => $user->assignRole(Role::where('name', 'admin')->first()),
    );

    $test->patchJson("/api/loans/{$loan->id}/submit")->assertOk();
    $test->actingAs($approver);
    $test->patchJson("/api/loans/{$loan->id}/approve", ['approval_remarks' => 'ok'])->assertOk();
    $test->actingAs($actingAdmin);
    $test->patchJson("/api/loans/{$loan->id}/release")->assertOk();
}

// ── active_loans: the shape ──────────────────────────────────────────────

it('reports the active loan holding a collateral on the index', function (string $status) {
    $loan = loanInStatus($this->loanDefaults, $status);
    pledgeDirectly($loan, $this->collateral);

    $row = $this->getJson('/api/collaterals')->assertOk()->json('data.0');

    expect($row['id'])->toBe($this->collateral->id)
        ->and($row['active_loans'])->toBe([
            ['id' => $loan->id, 'loan_account_number' => $loan->loan_account_number],
        ]);
})->with(Loan::ACTIVE_STATUSES);

it('reports no active loans for a collateral held only by a loan that is not active', function (string $status) {
    pledgeDirectly(loanInStatus($this->loanDefaults, $status), $this->collateral);

    expect($this->getJson('/api/collaterals')->assertOk()->json('data.0.active_loans'))->toBe([]);
})->with(['draft', 'for_review', 'approved', 'rejected', 'completed', 'defaulted', 'restructured', 'void']);

it('reports an empty array for a collateral attached to nothing', function () {
    expect($this->getJson('/api/collaterals')->assertOk()->json('data.0.active_loans'))->toBe([]);
});

it('lists both loans when one collateral sits on two active loans', function () {
    $released = loanInStatus($this->loanDefaults, 'released');
    $ongoing = loanInStatus($this->loanDefaults, 'ongoing');

    pledgeDirectly($released, $this->collateral);
    pledgeDirectly($ongoing, $this->collateral);

    $activeLoans = $this->getJson('/api/collaterals')->assertOk()->json('data.0.active_loans');

    expect($activeLoans)->toHaveCount(2)
        ->and(array_column($activeLoans, 'id'))->toEqualCanonicalizing([$released->id, $ongoing->id])
        ->and(array_column($activeLoans, 'loan_account_number'))
        ->toEqualCanonicalizing([$released->loan_account_number, $ongoing->loan_account_number]);
});

it('counts only the active holders when a collateral has active and inactive ones', function () {
    $active = loanInStatus($this->loanDefaults, 'released');
    pledgeDirectly($active, $this->collateral);
    pledgeDirectly(loanInStatus($this->loanDefaults, 'completed'), $this->collateral);
    pledgeDirectly(loanInStatus($this->loanDefaults, 'draft'), $this->collateral);

    expect($this->getJson('/api/collaterals')->assertOk()->json('data.0.active_loans'))
        ->toBe([['id' => $active->id, 'loan_account_number' => $active->loan_account_number]]);
});

// ── active_loans: which routes carry it ──────────────────────────────────

it('carries active_loans on the single collateral route', function () {
    $loan = loanInStatus($this->loanDefaults, 'released');
    pledgeDirectly($loan, $this->collateral);

    expect($this->getJson("/api/collaterals/{$this->collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $loan->id, 'loan_account_number' => $loan->loan_account_number]]);
});

it('carries active_loans on a loan collateral list, including the loan being viewed', function () {
    $loan = loanInStatus($this->loanDefaults, 'released');
    pledgeDirectly($loan, $this->collateral);

    // The field answers "who holds this", not "who ELSE holds this" — a client
    // wanting other holders filters this loan out itself.
    expect($this->getJson("/api/loans/{$loan->id}/collaterals")->assertOk()->json('data.0.active_loans'))
        ->toBe([['id' => $loan->id, 'loan_account_number' => $loan->loan_account_number]]);
});

it('carries active_loans on create, update and attach responses', function () {
    $type = $this->collateral->collateral_type_id;

    $created = $this->postJson('/api/collaterals', [
        'borrower_id' => $this->borrower->id,
        'collateral_type_id' => $type,
        'amount' => 1000,
    ])->assertCreated();
    expect($created->json('data.active_loans'))->toBe([]);

    $id = $created->json('data.id');
    expect($this->putJson("/api/collaterals/{$id}", ['amount' => 2000])->assertOk()->json('data.active_loans'))
        ->toBe([]);

    $loan = loanInStatus($this->loanDefaults, 'released');
    $attached = $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $id,
        'snapshot_value' => 2000,
    ])->assertCreated();

    expect($attached->json('data.active_loans'))
        ->toBe([['id' => $loan->id, 'loan_account_number' => $loan->loan_account_number]]);
});

// ── cost ─────────────────────────────────────────────────────────────────

it('answers the collateral index in a fixed number of queries however many rows it returns', function () {
    // active_loans is a per-row question. Answered lazily on an UNPAGINATED
    // index it is one query per collateral, which is the same fan-out — moved
    // from the client to the server — that this field exists to remove.
    $seed = function (int $count) {
        for ($i = 0; $i < $count; $i++) {
            $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
            pledgeDirectly(loanInStatus($this->loanDefaults, 'released'), $collateral);
            pledgeDirectly(loanInStatus($this->loanDefaults, 'completed'), $collateral);
        }
    };

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->getJson('/api/collaterals')->assertOk()->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Measure a response that actually carries the field. Dropping the
        // eager load also makes the count flat, because whenLoaded() then omits
        // active_loans entirely — flat and absent is not the thing being pinned.
        foreach ($rows as $row) {
            expect($row)->toHaveKey('active_loans');
        }

        return count($queries);
    };

    // Warm-up: the first authorized request also resolves the permission tables,
    // which Spatie then keeps in memory. Measuring it would compare a cold
    // request against a warm one.
    $this->getJson('/api/collaterals')->assertOk();

    $seed(2);
    $small = $countQueries();

    $seed(10);
    expect($this->getJson('/api/collaterals')->assertOk()->json('data'))->toHaveCount(13);
    expect($countQueries())->toBe($small, 'the collateral index is doing per-row work');
});

// ── the guard ────────────────────────────────────────────────────────────

it('refuses to attach a collateral that another active loan already holds', function (string $holderStatus) {
    $holder = loanInStatus($this->loanDefaults, $holderStatus);
    pledgeDirectly($holder, $this->collateral);

    $target = loanInStatus($this->loanDefaults, 'released');

    $this->postJson("/api/loans/{$target->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 500,
    ])->assertStatus(422)
        ->assertJsonValidationErrors('collateral_id')
        ->assertJsonFragment(['collateral_id' => ["This collateral is already pledged to active loan {$holder->loan_account_number}. Detach it there first."]]);

    $this->assertDatabaseMissing('loan_collaterals', [
        'loan_id' => $target->id,
        'collateral_id' => $this->collateral->id,
    ]);
    $this->assertDatabaseCount('loan_collaterals', 1);
})->with(Loan::ACTIVE_STATUSES);

it('refuses the attach even when the loan being attached to is not itself active', function () {
    // The status test is on the CURRENT holders, not on the target: a draft
    // application must not quietly reserve a collateral that a released loan
    // is standing on.
    $holder = loanInStatus($this->loanDefaults, 'released');
    pledgeDirectly($holder, $this->collateral);

    $draft = loanInStatus($this->loanDefaults, 'draft');

    $this->postJson("/api/loans/{$draft->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 500,
    ])->assertStatus(422);

    $this->assertDatabaseCount('loan_collaterals', 1);
});

it('names every conflicting loan when the collateral is on more than one active loan', function () {
    $first = loanInStatus($this->loanDefaults, 'released');
    $second = loanInStatus($this->loanDefaults, 'ongoing');
    pledgeDirectly($first, $this->collateral);
    pledgeDirectly($second, $this->collateral);

    $message = $this->postJson('/api/loans/'.loanInStatus($this->loanDefaults, 'released')->id.'/collaterals', [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 500,
    ])->assertStatus(422)->json('errors.collateral_id.0');

    expect($message)->toContain($first->loan_account_number)
        ->and($message)->toContain($second->loan_account_number)
        ->and($message)->toContain('active loans');
});

it('attaches a collateral whose only other loan is not active', function (string $holderStatus) {
    pledgeDirectly(loanInStatus($this->loanDefaults, $holderStatus), $this->collateral);

    $target = loanInStatus($this->loanDefaults, 'released');

    $this->postJson("/api/loans/{$target->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 500,
    ])->assertCreated();

    $this->assertDatabaseHas('loan_collaterals', [
        'loan_id' => $target->id,
        'collateral_id' => $this->collateral->id,
    ]);
})->with(['draft', 'for_review', 'approved', 'rejected', 'completed', 'defaulted', 'restructured', 'void']);

it('still refuses to re-attach the same collateral to the same active loan', function () {
    $loan = loanInStatus($this->loanDefaults, 'released');
    pledgeDirectly($loan, $this->collateral);

    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 500,
    ])->assertStatus(422)
        ->assertJsonFragment(['collateral_id' => ['This collateral is already attached to the loan.']]);
});

it('takes a row lock on the collateral inside a transaction before it decides', function () {
    // A read-then-write check without this serialization is not a guard: two
    // requests pledging the same collateral to two DIFFERENT loans both pass it
    // and both insert, and the unique index on (loan_id, collateral_id) cannot
    // stop them because the loan ids differ.
    $loan = loanInStatus($this->loanDefaults, 'released');

    $transactionsOpened = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactionsOpened) {
        $transactionsOpened++;
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 500,
    ])->assertCreated();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $lockingReads = collect($queries)
        ->pluck('query')
        ->map(fn (string $sql) => strtolower($sql))
        ->filter(fn (string $sql) => str_contains($sql, 'from `collaterals`') && str_contains($sql, 'for update'));

    expect($lockingReads)->not->toBeEmpty('attach() decided without locking the collateral row')
        ->and($transactionsOpened)->toBeGreaterThan(0, 'attach() checked and wrote outside a transaction');
});

// ── release: the guard has to let go ─────────────────────────────────────

it('frees the collateral for another loan once it is detached', function () {
    $holder = loanInStatus($this->loanDefaults, 'released');
    pledgeDirectly($holder, $this->collateral);

    $target = loanInStatus($this->loanDefaults, 'released');
    $payload = ['collateral_id' => $this->collateral->id, 'snapshot_value' => 500];

    $this->postJson("/api/loans/{$target->id}/collaterals", $payload)->assertStatus(422);

    $this->deleteJson("/api/loans/{$holder->id}/collaterals/{$this->collateral->id}")->assertOk();

    $this->postJson("/api/loans/{$target->id}/collaterals", $payload)->assertCreated();

    expect($this->getJson("/api/collaterals/{$this->collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $target->id, 'loan_account_number' => $target->loan_account_number]]);
});

it('frees the collateral for another loan once the holding loan leaves an active status', function () {
    $holder = loanInStatus($this->loanDefaults, 'ongoing');
    pledgeDirectly($holder, $this->collateral);

    $target = loanInStatus($this->loanDefaults, 'released');
    $payload = ['collateral_id' => $this->collateral->id, 'snapshot_value' => 500];

    $this->postJson("/api/loans/{$target->id}/collaterals", $payload)->assertStatus(422);

    // No write to loan_collaterals is involved: the pivot row survives as the
    // historical record of what secured the completed loan, and both the guard
    // and active_loans read live loan status rather than a cached flag.
    $holder->update(['status' => 'completed']);

    $this->postJson("/api/loans/{$target->id}/collaterals", $payload)->assertCreated();

    $this->assertDatabaseHas('loan_collaterals', [
        'loan_id' => $holder->id,
        'collateral_id' => $this->collateral->id,
    ]);
    expect($this->getJson("/api/collaterals/{$this->collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $target->id, 'loan_account_number' => $target->loan_account_number]]);
});

// ── restructure: the collateral follows the debt ─────────────────────────

it('carries the source loan collateral onto the restructured loan', function () {
    $source = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $source->borrower_id]);

    $this->postJson("/api/loans/{$source->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $newLoan = restructureOf($this, $source);

    // On the new loan…
    $this->assertDatabaseHas('loan_collaterals', [
        'loan_id' => $newLoan->id,
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ]);

    // …and still on the source, which stays live and collectible until the new
    // loan is released. Inheritance copies, it does not move.
    $this->assertDatabaseHas('loan_collaterals', [
        'loan_id' => $source->id,
        'collateral_id' => $collateral->id,
    ]);

    // The new loan is a draft, so the lock state still names only the source.
    expect($this->getJson("/api/collaterals/{$collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $source->id, 'loan_account_number' => $source->loan_account_number]]);
});

it('carries the original snapshot value forward instead of re-appraising it', function () {
    $source = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $source->borrower_id, 'amount' => 250000]);

    $this->postJson("/api/loans/{$source->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    // The collateral is re-valued on the register between pledge and restructure.
    $this->putJson("/api/collaterals/{$collateral->id}", ['amount' => 400000])->assertOk();

    $newLoan = restructureOf($this, $source);

    // The inherited row keeps the figure an operator actually stated. Restructure
    // takes no snapshot_value input, so deriving 400000 from the live amount would
    // be the server asserting an appraisal nobody signed off on.
    expect((float) $newLoan->collaterals()->first()->pivot->snapshot_value)->toBe(250000.0);
});

it('reports the restructured loan and not the source once the new loan is released', function () {
    $source = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $source->borrower_id]);

    $this->postJson("/api/loans/{$source->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $newLoan = restructureOf($this, $source);
    releaseViaApi($this, $newLoan, $this->admin);

    // closeRestructuredSource() takes the source out of Loan::ACTIVE_STATUSES.
    // Without the inheritance this is the moment the title would read as FREE
    // while still securing a live balance.
    expect($source->fresh()->status)->toBe('restructured');

    // Pick the row by id, not by position: beforeEach() seeds a collateral too,
    // and the index orders on `created_at`, which ties within the same second.
    $row = collect($this->getJson('/api/collaterals')->assertOk()->json('data'))
        ->firstWhere('id', $collateral->id);

    expect($row['active_loans'])
        ->toBe([['id' => $newLoan->id, 'loan_account_number' => $newLoan->fresh()->loan_account_number]]);
});

it('never lets the collateral be taken by another loan at any point in the restructure lifecycle', function () {
    $source = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $source->borrower_id]);

    $this->postJson("/api/loans/{$source->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $rival = loanInStatus($this->loanDefaults, 'released');
    $grab = fn () => $this->postJson("/api/loans/{$rival->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ]);

    // This is the anti-race property that matters, stated as an invariant rather
    // than as a thread interleaving: there is no window in the whole workflow
    // where a rival loan can take this collateral. Before the restructure the
    // source holds it; between application and release BOTH hold it; after
    // release the new loan holds it.
    $grab()->assertStatus(422);

    $newLoan = restructureOf($this, $source);
    $grab()->assertStatus(422);

    releaseViaApi($this, $newLoan, $this->admin);
    $grab()->assertStatus(422);

    $this->assertDatabaseMissing('loan_collaterals', [
        'loan_id' => $rival->id,
        'collateral_id' => $collateral->id,
    ]);
});

it('reads the source collateral under a row lock inside the restructure transaction', function () {
    $source = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $source->borrower_id]);

    $this->postJson("/api/loans/{$source->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $transactionsOpened = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactionsOpened) {
        $transactionsOpened++;
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    restructureOf($this, $source);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // The copy locks the same `collaterals` rows CollateralController::attach()
    // locks before it decides, so a concurrent attach serializes behind it
    // rather than racing it.
    $lockingReads = collect($queries)
        ->pluck('query')
        ->map(fn (string $sql) => strtolower($sql))
        ->filter(fn (string $sql) => str_contains($sql, 'from `collaterals`') && str_contains($sql, 'for update'));

    expect($lockingReads)->not->toBeEmpty('the restructure copied collateral without locking it')
        ->and($transactionsOpened)->toBeGreaterThan(0);
});

it('inherits nothing when the source loan holds no collateral', function () {
    $source = $this->createReleasedLoan();

    restructureOf($this, $source);

    $this->assertDatabaseCount('loan_collaterals', 0);
});

it('records the inherited collateral ids on the restructure audit entry', function () {
    $source = $this->createReleasedLoan();
    $collateral = Collateral::factory()->create(['borrower_id' => $source->borrower_id]);

    $this->postJson("/api/loans/{$source->id}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 250000,
    ])->assertCreated();

    $newLoan = restructureOf($this, $source);

    $audit = AuditLog::where('action', 'restructure_created')
        ->where('auditable_id', $newLoan->id)
        ->firstOrFail();

    expect($audit->new_values['inherited_collateral_ids'])->toBe([$collateral->id]);
});

// ── no unguarded back door ───────────────────────────────────────────────

it('has no write path into loan_collaterals outside the two that are accounted for', function () {
    // A guard on one controller is only a guard while the writers are known. So
    // this enumerates them and fails on any new one, naming the file.
    //
    // Three widenings over the obvious version, each of which a real change in
    // this branch would otherwise have walked straight through:
    //
    //  - The relation alternation is not just `collaterals()`. `Collateral::loans()`
    //    and `Collateral::activeLoans()` are both writable BelongsToMany over the
    //    SAME pivot, so `$collateral->activeLoans()->attach(...)` inserts into
    //    loan_collaterals too. (Only pivot-only verbs are matched, so the HasMany
    //    `loans()` on Borrower/Branch/LoanProduct cannot false-positive: none of
    //    these methods exist on a HasMany. `coMakers()` is a different pivot and is
    //    deliberately not listed.)
    //  - The raw-table pattern does not require the write verb to be adjacent, so
    //    `DB::table('loan_collaterals')->where(...)->update(...)` is caught, and
    //    raw SQL through DB::statement/unprepared/insert/update is caught too.
    //  - It scans database/ as well as app/. Migrations and seeders in this
    //    codebase write data, not just schema.
    //
    // Known and excluded on purpose: TimezoneShift::shift() reaches this table
    // through a table name held in a variable, so no literal-name pattern can see
    // it. It rewrites `attached_at`/`created_at`/`updated_at` only and never
    // `loan_id` or `collateral_id`, so it cannot create or move a pledge.
    $pivotRelationWrite = '/->\s*(?:collaterals|loans|activeLoans)\(\)\s*->\s*'
        .'(?:attach|sync|syncWithoutDetaching|toggle|updateExistingPivot)\s*\(/';
    $rawTableWrite = '/(?:DB::|->)table\(\s*[\'"]loan_collaterals[\'"]\s*\)/';
    $rawSqlWrite = '/DB::(?:statement|unprepared|insert|update)\([^;]*loan_collaterals/s';

    $writes = [];

    foreach ([app_path(), database_path()] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            $isWrite = preg_match($pivotRelationWrite, $source)
                || preg_match($rawTableWrite, $source)
                || preg_match($rawSqlWrite, $source);

            if ($isWrite) {
                $writes[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    sort($writes);

    expect($writes)->toBe([
        // The endpoint. Guarded by assertNotPledgedToAnotherActiveLoan().
        'app/Http/Controllers/Api/CollateralController.php',
        // Restructure inheritance. Deliberately unguarded — it moves collateral
        // from a live loan to the loan replacing it, which the guard would
        // reject; see LoanService::inheritCollaterals().
        'app/Services/LoanService.php',
    ], 'a new write into loan_collaterals appeared; either route it through the same active-loan guard as CollateralController::attach(), or account for it here and say why it is exempt');
});
