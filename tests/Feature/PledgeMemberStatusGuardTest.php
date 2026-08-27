<?php

use App\Http\Controllers\Api\ShareCapitalPledgeController;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Every write path into a share-capital pledge has to agree with the read path.
 *
 * GET /api/pledges is member-scoped, so a pledge belonging to a `pending` or
 * `rejected` registration no longer appears anywhere an operator can see it.
 * Without a matching guard on the writes, `auto_credit` can still be switched
 * on for one of those rows — and nobody can switch it back off, because the
 * screen that would show it filters the row out. processAutoCredit() refuses to
 * post for non-members today, which makes the flag inert rather than harmless:
 * it is invisible state that one change to the write path turns into real
 * ledger credits for somebody who never joined the cooperative.
 */
uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);
});

/**
 * A borrower in the given registration status, plus their auto-created pledge.
 *
 * @return array{0: Borrower, 1: ShareCapitalPledge}
 */
function borrowerWithPledgeInStatus(int $branchId, string $status): array
{
    $borrower = Borrower::factory()->create([
        'branch_id' => $branchId,
        'status' => $status,
        'pledge_amount' => 500,
    ]);

    return [$borrower, $borrower->shareCapitalPledge];
}

dataset('non-member statuses', Borrower::NON_MEMBER_STATUSES);

// `inactive` and `blacklisted` are members in poor standing, NOT non-members.
// They legitimately hold share capital, so the guard must let them through.
dataset('member statuses', ['active', 'inactive', 'blacklisted']);

it('refuses to toggle auto-credit on a non-member pledge', function (string $status) {
    [, $pledge] = borrowerWithPledgeInStatus($this->branch->id, $status);

    $this->patchJson("/api/pledges/{$pledge->id}/auto-credit")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);

    $this->assertDatabaseHas('share_capital_pledges', [
        'id' => $pledge->id,
        'auto_credit' => false,
    ]);
})->with('non-member statuses');

it('refuses to update a non-member pledge', function (string $status) {
    [, $pledge] = borrowerWithPledgeInStatus($this->branch->id, $status);

    $this->putJson("/api/pledges/{$pledge->id}", ['amount' => 9999, 'schedule' => '30'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);

    $this->assertDatabaseHas('share_capital_pledges', [
        'id' => $pledge->id,
        'amount' => 500.00,
        'schedule' => '15/30',
    ]);
})->with('non-member statuses');

it('refuses a manual ledger entry against a non-member pledge', function (string $status) {
    [, $pledge] = borrowerWithPledgeInStatus($this->branch->id, $status);

    $this->postJson("/api/pledges/{$pledge->id}/entries", [
        'amount' => 500,
        'type' => 'credit',
        'date' => now()->toDateString(),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);

    $this->assertDatabaseCount('share_capital_ledger', 0);
})->with('non-member statuses');

it('refuses a bulk ledger batch containing a non-member pledge', function () {
    // The single-entry guard is worth nothing if the same pledge id can be
    // posted to the bulk route instead. The member's entry must not commit
    // either — the batch is refused whole.
    [, $memberPledge] = borrowerWithPledgeInStatus($this->branch->id, 'active');
    [, $applicantPledge] = borrowerWithPledgeInStatus($this->branch->id, 'pending');

    $entry = fn (int $pledgeId) => [
        'pledge_id' => $pledgeId,
        'amount' => 500,
        'type' => 'credit',
        'date' => now()->toDateString(),
    ];

    $this->postJson('/api/pledges/bulk-entries', [
        'entries' => [$entry($memberPledge->id), $entry($applicantPledge->id)],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);

    $this->assertDatabaseCount('share_capital_ledger', 0);
});

it('still toggles auto-credit for a member', function (string $status) {
    [, $pledge] = borrowerWithPledgeInStatus($this->branch->id, $status);

    $this->patchJson("/api/pledges/{$pledge->id}/auto-credit")
        ->assertSuccessful()
        ->assertJsonPath('auto_credit', true);

    $this->assertDatabaseHas('share_capital_pledges', [
        'id' => $pledge->id,
        'auto_credit' => true,
    ]);
})->with('member statuses');

it('still updates a member pledge', function (string $status) {
    [, $pledge] = borrowerWithPledgeInStatus($this->branch->id, $status);

    $this->putJson("/api/pledges/{$pledge->id}", ['amount' => 1250, 'schedule' => '30'])
        ->assertSuccessful()
        ->assertJsonPath('data.amount', 1250)
        ->assertJsonPath('data.schedule', '30');
})->with('member statuses');

it('still records a manual ledger entry for a member', function (string $status) {
    [$borrower, $pledge] = borrowerWithPledgeInStatus($this->branch->id, $status);

    $this->postJson("/api/pledges/{$pledge->id}/entries", [
        'amount' => 500,
        'type' => 'credit',
        'date' => now()->toDateString(),
    ])->assertCreated();

    $this->assertDatabaseHas('share_capital_ledger', [
        'borrower_id' => $borrower->id,
        'credit' => 500,
    ]);
})->with('member statuses');

it('keeps a pending applicant out of auto-credit even after an approval attempt on the pledge', function () {
    // The end-to-end statement of the hole this closes: the flag can never be
    // set, so the nightly run has nothing invisible to skip.
    [$applicant, $pledge] = borrowerWithPledgeInStatus($this->branch->id, 'pending');

    $this->patchJson("/api/pledges/{$pledge->id}/auto-credit")->assertUnprocessable();

    $this->postJson('/api/auto-credit/process')
        ->assertCreated()
        ->assertJsonPath('data.member_count', 0);

    expect(ShareCapitalPledge::find($pledge->id)->auto_credit)->toBeFalse();
    $this->assertDatabaseMissing('share_capital_ledger', ['borrower_id' => $applicant->id]);
});

// ── POST /api/share-capital/ledger ───────────────────────────────────────────
//
// The same ShareCapitalLedger row as manualEntry()/bulkEntry(), reached by
// `borrower_id` instead of a pledge id. It was the last route into share
// capital that did not ask whether the borrower is a member, which made the
// pledge-side guard only partly real.

it('refuses a direct ledger entry for a non-member', function (string $status) {
    [$borrower] = borrowerWithPledgeInStatus($this->branch->id, $status);

    $this->postJson('/api/share-capital/ledger', [
        'borrower_id' => $borrower->id,
        'date' => now()->toDateString(),
        'description' => 'Initial contribution',
        'type' => 'credit',
        'amount' => 2000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);

    $this->assertDatabaseCount('share_capital_ledger', 0);
})->with('non-member statuses');

it('still records a direct ledger entry for a member', function (string $status) {
    [$borrower] = borrowerWithPledgeInStatus($this->branch->id, $status);

    $this->postJson('/api/share-capital/ledger', [
        'borrower_id' => $borrower->id,
        'date' => now()->toDateString(),
        'description' => 'Initial contribution',
        'type' => 'credit',
        'amount' => 2000,
    ])->assertCreated();

    $this->assertDatabaseHas('share_capital_ledger', [
        'borrower_id' => $borrower->id,
        'credit' => 2000,
    ]);
})->with('member statuses');

it('closes every route into share capital for a non-member', function (string $status) {
    // The whole invariant in one spec: four write paths, one answer. If a new
    // route is ever added, this is the test that should have been updated.
    [$borrower, $pledge] = borrowerWithPledgeInStatus($this->branch->id, $status);

    $ledgerEntry = [
        'date' => now()->toDateString(),
        'description' => 'Backdoor credit',
        'type' => 'credit',
        'amount' => 2000,
    ];

    $this->patchJson("/api/pledges/{$pledge->id}/auto-credit")->assertUnprocessable();
    $this->putJson("/api/pledges/{$pledge->id}", ['amount' => 9999])->assertUnprocessable();
    $this->postJson("/api/pledges/{$pledge->id}/entries", $ledgerEntry)->assertUnprocessable();
    $this->postJson('/api/pledges/bulk-entries', [
        'entries' => [array_merge($ledgerEntry, ['pledge_id' => $pledge->id])],
    ])->assertUnprocessable();
    $this->postJson('/api/share-capital/ledger', array_merge($ledgerEntry, [
        'borrower_id' => $borrower->id,
    ]))->assertUnprocessable();

    $this->assertDatabaseCount('share_capital_ledger', 0);
    $this->assertDatabaseHas('share_capital_pledges', [
        'id' => $pledge->id,
        'amount' => 500.00,
        'auto_credit' => false,
    ]);
})->with('non-member statuses');

it('fails closed when a pledge has no borrower to check', function () {
    // Unreachable over HTTP — share_capital_pledges.borrower_id is a
    // restrictOnDelete foreign key, so an orphan cannot exist. Asserted anyway
    // because the guard used to be written as "reject the known-bad list", and
    // `in_array(null, NON_MEMBER_STATUSES, true)` is false: a null status fell
    // straight through the rejection and was ALLOWED. A guard should not depend
    // on a constraint declared in another file to be safe.
    $pledge = new ShareCapitalPledge;
    $pledge->setRelation('borrower', null);

    $controller = app(ShareCapitalPledgeController::class);
    $guard = new ReflectionMethod($controller, 'assertBorrowerIsMember');

    expect(fn () => $guard->invoke($controller, $pledge))
        ->toThrow(ValidationException::class);
});
