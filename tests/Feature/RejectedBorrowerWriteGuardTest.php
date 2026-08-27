<?php

use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\GCashTier;
use App\Models\LoanProduct;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A rejected registration must not be attachable to anything financial.
 *
 * Rejecting used to hard-delete the applicant, so a rejected borrower_id simply
 * did not resolve. This release keeps the row (`status = 'rejected'`) for the
 * audit trail, which means a real borrower id can now point at somebody the
 * cooperative turned away. The frontend passes `members_only=1` to its borrower
 * pickers, but that is a UI filter — these specs cover the API underneath it.
 *
 * The `pending` half of every pair matters just as much as the `rejected` half.
 * "Pending plus a loan" is a real state in this data model, not a contradiction:
 * PruneAbandonedRegistrations says so outright, and the portfolio database
 * holds ten loans across five pending borrowers — a third of its loan book.
 * A gate built on Borrower::NON_MEMBER_STATUSES would cover `pending` too and
 * take that workflow out, which is why these endpoints deliberately do NOT use
 * it. Those specs are the regression guard for anyone tempted to unify the two.
 */
uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();
});

/**
 * A borrower parked in the given registration status.
 */
function borrowerInStatus(int $branchId, string $status): Borrower
{
    return Borrower::factory()->create([
        'branch_id' => $branchId,
        'status' => $status,
    ]);
}

// ── Loans ────────────────────────────────────────────────────────────────────

it('refuses to open a loan for a rejected borrower', function () {
    $rejected = borrowerInStatus($this->branch->id, 'rejected');

    $this->postJson('/api/loans', [
        'borrower_id' => $rejected->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);

    $this->assertDatabaseMissing('loans', ['borrower_id' => $rejected->id]);
});

it('still opens a loan for a pending borrower', function () {
    // THE regression guard. Five pending borrowers hold ten live loans on the
    // portfolio deployment; gating `pending` here would break that outright.
    $pending = borrowerInStatus($this->branch->id, 'pending');

    $this->postJson('/api/loans', [
        'borrower_id' => $pending->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
    ])->assertCreated();

    $this->assertDatabaseHas('loans', ['borrower_id' => $pending->id]);
});

it('refuses to restructure into a rejected borrower', function () {
    $source = $this->createReleasedLoan();
    $source->borrower->update(['status' => 'rejected']);

    $this->postJson("/api/loans/{$source->id}/restructure", [
        'borrower_id' => $source->borrower_id,
        'loan_product_id' => $source->loan_product_id,
        'principal_amount' => 70800.00,
        'start_date' => now()->toDateString(),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);
});

it('still restructures a pending borrower', function () {
    $source = $this->createReleasedLoan();
    $source->borrower->update(['status' => 'pending']);

    $this->postJson("/api/loans/{$source->id}/restructure", [
        'borrower_id' => $source->borrower_id,
        'loan_product_id' => $source->loan_product_id,
        'principal_amount' => 70800.00,
        'start_date' => now()->toDateString(),
    ])->assertCreated();
});

// ── Collaterals ──────────────────────────────────────────────────────────────

it('refuses to register a collateral for a rejected borrower', function () {
    $rejected = borrowerInStatus($this->branch->id, 'rejected');

    $this->postJson('/api/collaterals', [
        'borrower_id' => $rejected->id,
        'collateral_type_id' => CollateralType::first()->id,
        'detail_value' => 'TCT-11111',
        'amount' => 250000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);

    $this->assertDatabaseMissing('collaterals', ['borrower_id' => $rejected->id]);
});

it('still registers a collateral for a pending borrower', function () {
    $pending = borrowerInStatus($this->branch->id, 'pending');

    $this->postJson('/api/collaterals', [
        'borrower_id' => $pending->id,
        'collateral_type_id' => CollateralType::first()->id,
        'detail_value' => 'TCT-22222',
        'amount' => 250000,
    ])->assertCreated();

    $this->assertDatabaseHas('collaterals', ['borrower_id' => $pending->id]);
});

it('refuses to reassign a collateral to a rejected borrower', function () {
    $collateral = Collateral::factory()->create([
        'borrower_id' => borrowerInStatus($this->branch->id, 'active')->id,
    ]);
    $rejected = borrowerInStatus($this->branch->id, 'rejected');

    $this->putJson("/api/collaterals/{$collateral->id}", ['borrower_id' => $rejected->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);

    expect($collateral->fresh()->borrower_id)->not->toBe($rejected->id);
});

it('still reassigns a collateral to a pending borrower', function () {
    $collateral = Collateral::factory()->create([
        'borrower_id' => borrowerInStatus($this->branch->id, 'active')->id,
    ]);
    $pending = borrowerInStatus($this->branch->id, 'pending');

    $this->putJson("/api/collaterals/{$collateral->id}", ['borrower_id' => $pending->id])
        ->assertOk();

    expect($collateral->fresh()->borrower_id)->toBe($pending->id);
});

// ── GCash ────────────────────────────────────────────────────────────────────

it('refuses a GCash transaction for a rejected borrower', function () {
    seedGCashTiersForGuardTest();
    $rejected = borrowerInStatus($this->branch->id, 'rejected');

    $this->postJson('/api/gcash/transactions', [
        'borrower_id' => $rejected->id,
        'type' => 'cash_in',
        'amount' => 1000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['borrower_id']);

    $this->assertDatabaseMissing('gcash_transactions', ['borrower_id' => $rejected->id]);
});

it('still records a GCash transaction for a pending borrower', function () {
    seedGCashTiersForGuardTest();
    $pending = borrowerInStatus($this->branch->id, 'pending');

    $this->postJson('/api/gcash/transactions', [
        'borrower_id' => $pending->id,
        'type' => 'cash_in',
        'amount' => 1000,
    ])->assertCreated();

    $this->assertDatabaseHas('gcash_transactions', ['borrower_id' => $pending->id]);
});

// ── Shared fixtures ──────────────────────────────────────────────────────────

function seedGCashTiersForGuardTest(): void
{
    GCashTier::create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    GCashTier::create(['min_amount' => 1501, 'max_amount' => 5000, 'cash_in_rate' => 30, 'cash_out_rate' => 25, 'display_order' => 2]);
}

// ── Co-makers ────────────────────────────────────────────────────────────────
//
// A co-maker is jointly liable for the loan, so gating the principal
// `borrower_id` and leaving `co_maker_ids` open just moves the hole one field
// to the right. LoanService::createLoan() resolves each id as a CoMaker first
// and otherwise as a Borrower, creating a co-maker record from that person.

it('refuses a rejected borrower as a loan co-maker', function () {
    $borrower = borrowerInStatus($this->branch->id, 'active');
    $rejected = borrowerInStatus($this->branch->id, 'rejected');

    $this->postJson('/api/loans', [
        'borrower_id' => $borrower->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
        'co_maker_ids' => [$rejected->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['co_maker_ids.0']);

    $this->assertDatabaseMissing('loans', ['borrower_id' => $borrower->id]);
    $this->assertDatabaseMissing('co_makers', ['borrower_id' => $rejected->id]);
});

it('still accepts a pending borrower as a loan co-maker', function () {
    $borrower = borrowerInStatus($this->branch->id, 'active');
    $pending = borrowerInStatus($this->branch->id, 'pending');

    $this->postJson('/api/loans', [
        'borrower_id' => $borrower->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
        'co_maker_ids' => [$pending->id],
    ])->assertCreated();
});

it('refuses a co-maker id that matches nothing at all', function () {
    // POST /loans validated `co_maker_ids.*` as a bare `integer`, so any id
    // reached that lookup unchecked. The restructure route already refused it.
    $borrower = borrowerInStatus($this->branch->id, 'active');

    $this->postJson('/api/loans', [
        'borrower_id' => $borrower->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
        'co_maker_ids' => [999999],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['co_maker_ids.0']);
});

it('refuses a rejected borrower as a restructure co-maker', function () {
    $source = $this->createReleasedLoan();
    $rejected = borrowerInStatus($this->branch->id, 'rejected');

    $this->postJson("/api/loans/{$source->id}/restructure", [
        'borrower_id' => $source->borrower_id,
        'loan_product_id' => $source->loan_product_id,
        'principal_amount' => 70800.00,
        'start_date' => now()->toDateString(),
        'co_maker_ids' => [$rejected->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['co_maker_ids.0']);

    $this->assertDatabaseMissing('co_makers', ['borrower_id' => $rejected->id]);
});
