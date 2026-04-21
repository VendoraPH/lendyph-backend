<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);
});

function makeReleasedLoan(int $branchId, User $admin, array $overrides = []): array
{
    $product = LoanProduct::factory()->create([
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 6,
        'frequency' => 'monthly',
    ]);
    $borrower = Borrower::factory()->create(['branch_id' => $branchId]);

    $loanService = app(LoanService::class);
    $loan = $loanService->createLoan(array_merge([
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
    ], $overrides), $admin);

    $loanService->submitForReview($loan);
    $loanService->approve($loan, $admin, 'OK');
    $loanService->release($loan, $admin);

    return ['loan' => $loan->fresh(), 'borrower' => $borrower];
}

it('persists scb_amount on loan creation', function () {
    $product = LoanProduct::factory()->create([
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 6,
        'frequency' => 'monthly',
    ]);
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $loan = app(LoanService::class)->createLoan([
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
        'scb_amount' => 250,
    ], $this->admin);

    expect((float) $loan->scb_amount)->toBe(250.0);
});

it('persists policy_exception fields on loan creation', function () {
    $product = LoanProduct::factory()->create([
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 6,
        'frequency' => 'monthly',
    ]);
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $loan = app(LoanService::class)->createLoan([
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
        'policy_exception' => true,
        'policy_exception_details' => 'Borrower has special standing with the cooperative.',
    ], $this->admin);

    expect($loan->policy_exception)->toBeTrue();
    expect($loan->policy_exception_details)->toBe('Borrower has special standing with the cooperative.');
});

it('exposes scb_amount and policy_exception in LoanResource', function () {
    $product = LoanProduct::factory()->create([
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 6,
        'frequency' => 'monthly',
    ]);
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $loanService = app(LoanService::class);
    $loan = $loanService->createLoan([
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_amount' => 60000,
        'start_date' => now()->toDateString(),
        'scb_amount' => 500,
        'policy_exception' => true,
        'policy_exception_details' => 'PE details here',
    ], $this->admin);

    $response = $this->getJson("/api/loans/{$loan->id}")->assertSuccessful();

    expect((float) $response->json('data.scb_amount'))->toBe(500.0);
    expect($response->json('data.policy_exception'))->toBeTrue();
    expect($response->json('data.policy_exception_details'))->toBe('PE details here');
});

it('does not auto-create any SCB ledger entry on payment (frontend PR 106)', function () {
    // Backend no longer auto-credits SCB on payment — the frontend handles it
    // explicitly by posting to /api/share-capital/ledger with the computed excess.
    ['loan' => $loan, 'borrower' => $borrower] = makeReleasedLoan($this->branch->id, $this->admin, [
        'scb_amount' => 250,
    ]);

    $ledgerBefore = ShareCapitalLedger::where('borrower_id', $borrower->id)->count();

    app(RepaymentService::class)->processRepayment($loan, 25000, now()->toDateString(), $this->admin);

    expect(ShareCapitalLedger::where('borrower_id', $borrower->id)->count())->toBe($ledgerBefore);
});

it('does not cascade excess to future schedules when scb_amount > 0', function () {
    ['loan' => $loan] = makeReleasedLoan($this->branch->id, $this->admin, [
        'scb_amount' => 250,
    ]);

    // Period 1 due ≈ 10000 principal + 1800 interest. Pay 25000 → ~13200 excess.
    $repayment = app(RepaymentService::class)->processRepayment(
        $loan,
        25000,
        now()->toDateString(),
        $this->admin,
    );

    // Period 1 (current) should be fully allocated
    expect((float) $repayment->current_principal_applied)->toBeGreaterThan(0);
    expect((float) $repayment->current_interest_applied)->toBeGreaterThan(0);

    // Period 2+ should NOT have been touched — no next-period allocation
    expect((float) $repayment->next_interest_applied)->toBe(0.0);
    expect((float) $repayment->next_principal_applied)->toBe(0.0);

    // Excess surfaces as overpayment (for the frontend to route to SCB)
    expect((float) $repayment->overpayment)->toBeGreaterThan(0);

    // Period 2 in the DB must still be pending with zero applied
    $period2 = $loan->amortizationSchedules()->where('period_number', 2)->first();
    expect($period2->status)->toBe('pending');
    expect((float) $period2->principal_paid)->toBe(0.0);
    expect((float) $period2->interest_paid)->toBe(0.0);
});

it('cascades excess to future schedules when scb_amount is zero', function () {
    ['loan' => $loan] = makeReleasedLoan($this->branch->id, $this->admin, [
        'scb_amount' => 0,
    ]);

    // Pay enough to overflow period 1 into period 2
    $repayment = app(RepaymentService::class)->processRepayment(
        $loan,
        25000,
        now()->toDateString(),
        $this->admin,
    );

    // Excess should flow to next-period buckets
    expect((float) $repayment->next_interest_applied)->toBeGreaterThan(0);

    // Period 2 has some payment applied
    $period2 = $loan->amortizationSchedules()->where('period_number', 2)->first();
    expect((float) $period2->principal_paid + (float) $period2->interest_paid)->toBeGreaterThan(0);
});

it('breaks down repayment into 6-tier allocation buckets', function () {
    ['loan' => $loan] = makeReleasedLoan($this->branch->id, $this->admin, [
        'scb_amount' => 0,
    ]);

    // Pay enough to overflow the first schedule and touch the second
    $repayment = app(RepaymentService::class)->processRepayment(
        $loan,
        25000,
        now()->toDateString(),
        $this->admin,
    );

    // Current schedule (period 1) is not overdue → current_interest_applied + current_principal_applied
    expect((float) $repayment->current_interest_applied)->toBeGreaterThan(0);
    expect((float) $repayment->current_principal_applied)->toBeGreaterThan(0);

    // Excess flowed to next schedule(s) → next_interest_applied
    expect((float) $repayment->next_interest_applied)->toBeGreaterThan(0);

    // Totals match
    expect((float) $repayment->interest_applied)->toBe(
        round((float) $repayment->overdue_interest_applied + (float) $repayment->current_interest_applied + (float) $repayment->next_interest_applied, 2)
    );
    expect((float) $repayment->principal_applied)->toBe(
        round((float) $repayment->current_principal_applied + (float) $repayment->next_principal_applied, 2)
    );
});
