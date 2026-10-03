<?php

/**
 * A defaulted loan still owes its balance, so the collateral securing it stays
 * pledged: it counts as an active holder for every pledge check, and the loan
 * itself cannot be voided away.
 */

use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();

    $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $this->collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id, 'amount' => 250000]);

    $this->loanDefaults = [
        'borrower_id' => $this->borrower->id,
        'loan_product_id' => LoanProduct::factory()->create()->id,
        'branch_id' => $this->branch->id,
        'created_by' => $this->admin->id,
    ];
});

function defaultedLoanInStatus(array $defaults, string $status): Loan
{
    static $serial = 900;
    $serial++;

    return Loan::factory()->create(array_merge($defaults, [
        'status' => $status,
        'loan_account_number' => 'LN-'.str_pad((string) $serial, 6, '0', STR_PAD_LEFT),
    ]));
}

function defaultedPledgeDirectly(Loan $loan, Collateral $collateral, float $snapshotValue = 100.0): void
{
    $loan->collaterals()->attach($collateral->id, [
        'snapshot_value' => $snapshotValue,
        'attached_at' => now(),
    ]);
}

function defaultedApprovedLoan(Borrower $borrower, User $admin, ?Closure $whileDraft = null): Loan
{
    $product = LoanProduct::factory()->create([
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 6,
        'frequency' => 'monthly',
    ]);

    $service = app(LoanService::class);

    $loan = $service->createLoan([
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
    ], $admin);

    if ($whileDraft !== null) {
        $whileDraft($loan);
    }

    $service->submitForReview($loan);
    $service->approve($loan, $admin, 'Approved for testing');

    return $loan->fresh();
}

// ── pledging ─────────────────────────────────────────────────────────────

it('refuses to attach a collateral a defaulted loan holds, naming that loan', function () {
    $defaulted = defaultedLoanInStatus($this->loanDefaults, 'defaulted');
    defaultedPledgeDirectly($defaulted, $this->collateral);
    $draft = defaultedLoanInStatus($this->loanDefaults, 'draft');

    $this->postJson("/api/loans/{$draft->id}/collaterals", [
        'collateral_id' => $this->collateral->id,
        'snapshot_value' => 500,
    ])->assertStatus(422)
        ->assertJsonFragment(['collateral_id' => ["This collateral is already pledged to active loan {$defaulted->loan_account_number}. Detach it there first."]]);

    $this->assertDatabaseMissing('loan_collaterals', ['loan_id' => $draft->id]);
});

it('refuses a loan edit listing a collateral a defaulted loan holds', function () {
    $defaulted = defaultedLoanInStatus($this->loanDefaults, 'defaulted');
    defaultedPledgeDirectly($defaulted, $this->collateral);
    $draft = defaultedLoanInStatus($this->loanDefaults, 'draft');

    $this->putJson("/api/loans/{$draft->id}", [
        'collaterals' => [['collateral_id' => $this->collateral->id, 'snapshot_value' => 500]],
    ])->assertStatus(422)
        ->assertJsonFragment(['collaterals.0.collateral_id' => ["This collateral is already pledged to active loan {$defaulted->loan_account_number}. Detach it there first."]]);

    $this->assertDatabaseMissing('loan_collaterals', ['loan_id' => $draft->id]);
});

it('refuses to release a loan whose collateral a defaulted loan holds', function () {
    $loan = defaultedApprovedLoan($this->borrower, $this->admin, whileDraft: fn (Loan $draft) => defaultedPledgeDirectly($draft, $this->collateral, 250000));

    $defaulted = defaultedLoanInStatus($this->loanDefaults, 'defaulted');
    defaultedPledgeDirectly($defaulted, $this->collateral);

    $this->patchJson("/api/loans/{$loan->id}/release")
        ->assertStatus(422)
        ->assertJsonFragment(['collateral' => ["This loan holds collateral already pledged to active loan {$defaulted->loan_account_number}. Detach it from that loan first."]]);

    expect($loan->fresh()->status)->toBe('approved')
        ->and($loan->fresh()->loan_account_number)->toBeNull();
});

it('reports a defaulted holder in active_loans', function () {
    $defaulted = defaultedLoanInStatus($this->loanDefaults, 'defaulted');
    defaultedPledgeDirectly($defaulted, $this->collateral);

    expect($this->getJson("/api/collaterals/{$this->collateral->id}")->assertOk()->json('data.active_loans'))
        ->toBe([['id' => $defaulted->id, 'loan_account_number' => $defaulted->loan_account_number]]);
});

it('counts a collateral a defaulted loan holds as tagged in the register', function () {
    $type = CollateralType::factory()->create();
    $collateral = Collateral::factory()->create([
        'borrower_id' => $this->borrower->id,
        'collateral_type_id' => $type->id,
        'amount' => 100,
    ]);
    defaultedPledgeDirectly(defaultedLoanInStatus($this->loanDefaults, 'defaulted'), $collateral);

    $response = $this->getJson('/api/collaterals/register')->assertOk();

    expect($response->json('meta.totals.tagged_to_active_loans'))->toBe(1)
        ->and(collect($response->json('data'))->firstWhere('borrower_id', $this->borrower->id)['tagged_count'])->toBe(1);
});

// ── voiding ──────────────────────────────────────────────────────────────

it('refuses to void a defaulted loan with a 422 and leaves it defaulted', function () {
    $defaulted = defaultedLoanInStatus($this->loanDefaults, 'defaulted');

    $this->patchJson("/api/loans/{$defaulted->id}/void")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($defaulted->fresh()->status)->toBe('defaulted');
});
