<?php

/**
 * `?<scoping filter>=0` must never widen a list.
 *
 * `Builder::when()` skips its callback for any falsy condition, and `0` and
 * `'0'` are falsy. Every list in this application that gated a filter on the
 * VALUE rather than on its PRESENCE therefore answered a request scoped to one
 * member, branch or user with the whole book instead — and the frontend reaches
 * `0` on its own, via `Number(params.id)` on a route like `/borrowers/0`.
 *
 * The loans list was fixed first, the collateral list second. This file covers
 * every remaining instance in app/Http/Controllers/Api, found by two passes of
 * audit after those two:
 *
 *   - GET /api/repayments            `borrower_id`  (list AND meta.stats)
 *   - GET /api/share-capital/ledger  `borrower_id`
 *   - GET /api/users                 `branch_id`    (list AND meta.stats)
 *   - GET /api/audit-logs            `user_id`      (list AND meta.stats)
 *   - GET /api/pledges               `schedule`, `search`
 *   - GET /api/loan-products         `search`, `status`
 *
 * The last two take strings rather than ids, so a dropped filter widens the list
 * without crossing a member boundary — narrower, but the same defect, and `0` is
 * an ordinary thing to type into a search box.
 *
 * That difference in type changes the CORRECT behaviour, which is why the tests
 * below assert two different things. `0` can never be a valid id, so an id
 * filter of `0` is refused with a 422. `0` can perfectly well be a valid string,
 * so a string filter of `0` is HONOURED — the list narrows, and answers with
 * nothing, rather than 422-ing on a value a user may legitimately have typed.
 * What neither may do is silently widen.
 *
 * GET /api/borrowers had the same hole on `branch_id` and is DELIBERATELY not
 * here: it was fixed separately, in another engineer's branch, and duplicating
 * the coverage would just give two branches a merge conflict over one file.
 *
 * Two properties per endpoint: the falsy value is REFUSED rather than dropped,
 * and a real value still narrows. The second matters as much as the first — a
 * filter that 422s on everything would also pass the first assertion.
 */

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();

    $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $this->stranger = Borrower::factory()->create(['branch_id' => $this->branch->id]);
});

// ── GET /api/repayments ──────────────────────────────────────────────────

function falsyLoanFor(Borrower $borrower, Branch $branch, User $admin): Loan
{
    return Loan::factory()->create([
        'borrower_id' => $borrower->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'branch_id' => $branch->id,
        'created_by' => $admin->id,
        'status' => 'ongoing',
    ]);
}

it('refuses borrower_id=0 on the repayment list instead of returning every payment', function () {
    Repayment::factory()->count(3)->create([
        'loan_id' => falsyLoanFor($this->stranger, $this->branch, $this->admin)->id,
        'received_by' => $this->admin->id,
    ]);

    $this->getJson('/api/repayments?borrower_id=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors('borrower_id');
});

it('scopes both the repayment rows and meta.stats to a real borrower', function () {
    Repayment::factory()->count(2)->create([
        'loan_id' => falsyLoanFor($this->borrower, $this->branch, $this->admin)->id,
        'received_by' => $this->admin->id,
    ]);
    Repayment::factory()->count(3)->create([
        'loan_id' => falsyLoanFor($this->stranger, $this->branch, $this->admin)->id,
        'received_by' => $this->admin->id,
    ]);

    $response = $this->getJson("/api/repayments?borrower_id={$this->borrower->id}")->assertOk();

    // The tabs and the rows have to agree. A stats block still counting the
    // whole organisation while the page shows one member is the same bug wearing
    // a different hat.
    expect($response->json('meta.total'))->toBe(2)
        ->and($response->json('meta.stats.posted'))->toBe(2);
});

it('still returns every repayment when no borrower filter is sent', function () {
    Repayment::factory()->count(2)->create([
        'loan_id' => falsyLoanFor($this->borrower, $this->branch, $this->admin)->id,
        'received_by' => $this->admin->id,
    ]);
    Repayment::factory()->count(3)->create([
        'loan_id' => falsyLoanFor($this->stranger, $this->branch, $this->admin)->id,
        'received_by' => $this->admin->id,
    ]);

    expect($this->getJson('/api/repayments')->assertOk()->json('meta.total'))->toBe(5);
});

// ── GET /api/share-capital/ledger ────────────────────────────────────────

it('refuses borrower_id=0 on the share capital ledger instead of returning every entry', function () {
    ShareCapitalLedger::factory()->count(3)->create(['borrower_id' => $this->stranger->id]);

    $this->getJson('/api/share-capital/ledger?borrower_id=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors('borrower_id');
});

it('scopes the share capital ledger to a real borrower', function () {
    ShareCapitalLedger::factory()->count(2)->create(['borrower_id' => $this->borrower->id]);
    ShareCapitalLedger::factory()->count(3)->create(['borrower_id' => $this->stranger->id]);

    $rows = $this->getJson("/api/share-capital/ledger?borrower_id={$this->borrower->id}")
        ->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and(array_unique(array_column($rows, 'borrower_id')))->toBe([$this->borrower->id]);
});

// ── GET /api/users ───────────────────────────────────────────────────────

it('refuses branch_id=0 on the user list instead of returning every user', function () {
    $this->getJson('/api/users?branch_id=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors('branch_id');
});

it('scopes both the user rows and meta.stats to a real branch', function () {
    $otherBranch = Branch::factory()->create();
    User::factory()->count(3)->create(['branch_id' => $otherBranch->id, 'status' => 'active']);

    $mine = $this->getJson("/api/users?branch_id={$this->branch->id}")->assertOk();
    $theirs = $this->getJson("/api/users?branch_id={$otherBranch->id}")->assertOk();

    expect($theirs->json('meta.total'))->toBe(3)
        ->and($theirs->json('meta.stats.active'))->toBe(3)
        // The seeded users all sit on the default branch, so this is simply
        // "not the other branch's three" — the point is that the two scopes
        // partition rather than that either equals the whole.
        ->and($mine->json('meta.total'))->toBeGreaterThan(0)
        ->and($mine->json('meta.total') + $theirs->json('meta.total'))
        ->toBe($this->getJson('/api/users')->assertOk()->json('meta.total'));
});

// ── GET /api/audit-logs ──────────────────────────────────────────────────

it('refuses user_id=0 on the audit log instead of returning the whole trail', function () {
    $this->getJson('/api/audit-logs?user_id=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');
});

it('scopes the audit log to a real user', function () {
    $other = User::factory()->create(['branch_id' => $this->branch->id]);

    AuditLog::create([
        'user_id' => $other->id,
        'action' => 'test_action',
        'description' => 'only this one belongs to the other user',
    ]);

    $rows = $this->getJson("/api/audit-logs?user_id={$other->id}")->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['action'])->toBe('test_action');
});

it('refuses user_id=0 on the audit log CSV export too, before any bytes stream', function () {
    // export() shares buildQuery() with index(), so it inherited the same hole —
    // and it is the worse of the two, being a file the operator keeps. The
    // validation is deliberately hoisted out of the streamed closure: thrown from
    // inside it, a 422 would be appended to a half-written CSV instead of
    // replacing it.
    $response = $this->getJson('/api/audit-logs/export?user_id=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    // No download was started at all: had the throw happened inside the streamed
    // closure, the attachment headers would already be on the wire.
    expect($response->headers->get('content-disposition'))->toBeNull();
});

it('scopes the audit log action chips to the same user as the rows', function () {
    // The chips and the rows have to agree, the same rule the repayment and user
    // lists follow. Left global, every chip promised results the page could not
    // produce.
    $other = User::factory()->create(['branch_id' => $this->branch->id]);

    AuditLog::create(['user_id' => $other->id, 'action' => 'lonely_action', 'description' => 'theirs']);
    AuditLog::create(['user_id' => $this->admin->id, 'action' => 'lonely_action', 'description' => 'mine']);

    $scoped = $this->getJson("/api/audit-logs?user_id={$other->id}")->assertOk();

    expect($scoped->json('meta.stats.actions.lonely_action'))->toBe(1)
        ->and($scoped->json('meta.total'))->toBe(1);

    // Unfiltered, the same chip counts both.
    expect($this->getJson('/api/audit-logs')->assertOk()->json('meta.stats.actions.lonely_action'))->toBe(2);
});

// ── GET /api/pledges ─────────────────────────────────────────────────────

it('applies a falsy pledge filter instead of dropping it', function (string $key) {
    // NOT a 422, and the difference is the point. `0` cannot be a valid id, so
    // the id filters above refuse it. `0` CAN be a valid string — a search term,
    // or a schedule value in some future enum — so the right behaviour here is to
    // honour the filter and answer with what matches, which is nothing.
    //
    // Before the fix this returned the entire pledge register, because `when()`
    // saw a falsy value and skipped the callback.
    expect($this->getJson('/api/pledges')->assertOk()->json('meta.total'))->toBeGreaterThan(0);

    expect($this->getJson("/api/pledges?{$key}=0")->assertOk()->json('data'))
        ->toBeEmpty('the filter was dropped and the whole register came back');
})->with(['schedule', 'search']);

it('still filters pledges on a real schedule', function () {
    // A pledge is auto-created per member and the column is an enum of
    // '15' / '30' / '15/30', so rather than assume what the seed contains this
    // splits the two borrowers from beforeEach across two schedules and asks for
    // one of them.
    ShareCapitalPledge::where('borrower_id', $this->borrower->id)->update(['schedule' => '15']);
    ShareCapitalPledge::where('borrower_id', $this->stranger->id)->update(['schedule' => '30']);

    $rows = $this->getJson('/api/pledges?schedule=15')->assertOk()->json('data');

    expect($rows)->not->toBeEmpty()
        ->and(array_unique(array_column($rows, 'schedule')))->toBe(['15'])
        ->and(array_column($rows, 'borrower_id'))->toContain($this->borrower->id)
        ->and(array_column($rows, 'borrower_id'))->not->toContain($this->stranger->id);
});

// ── GET /api/loan-products ───────────────────────────────────────────────

it('applies a falsy loan product filter instead of dropping it', function (string $key) {
    // Same distinction as the pledge list: a string filter of `0` is honoured,
    // not refused. This list is unpaginated, so dropping the filter returned
    // every product in the catalogue.
    LoanProduct::factory()->count(2)->create(['name' => 'Ordinary Loan', 'status' => 'active']);

    expect($this->getJson('/api/loan-products')->assertOk()->json('data'))->not->toBeEmpty();

    expect($this->getJson("/api/loan-products?{$key}=0")->assertOk()->json('data'))
        ->toBeEmpty('the filter was dropped and the whole catalogue came back');
})->with(['search', 'status']);

it('still filters loan products on a real search term', function () {
    LoanProduct::factory()->create(['name' => 'Zephyr Special']);
    LoanProduct::factory()->count(2)->create(['name' => 'Ordinary Loan']);

    $rows = $this->getJson('/api/loan-products?search=Zephyr')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Zephyr Special');
});
