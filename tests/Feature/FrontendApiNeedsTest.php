<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\Repayment;
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

// ── Borrowers list: stats meta ───────────────────────────────────────

it('includes status stats in /api/borrowers meta', function () {
    Borrower::factory()->count(3)->create(['branch_id' => $this->branch->id, 'status' => 'active']);
    Borrower::factory()->count(2)->create(['branch_id' => $this->branch->id, 'status' => 'inactive']);

    $response = $this->getJson('/api/borrowers?per_page=100')->assertSuccessful();

    expect($response->json('meta.stats.active'))->toBe(3);
    expect($response->json('meta.stats.inactive'))->toBe(2);
});

// ── Loans list: stats meta ───────────────────────────────────────────

it('includes status stats in /api/loans meta', function () {
    $response = $this->getJson('/api/loans')->assertSuccessful();
    expect($response->json('meta.stats'))->toHaveKeys(['draft', 'for_review', 'approved', 'released']);
});

// ── Users list: stats meta ───────────────────────────────────────────

it('includes status stats in /api/users meta', function () {
    $response = $this->getJson('/api/users')->assertSuccessful();
    expect($response->json('meta.stats'))->toHaveKeys(['active', 'inactive']);
});

// ── Repayments: borrower_id filter ───────────────────────────────────

function makeReleasedLoanForApiTest(int $branchId, User $admin, array $overrides = []): array
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

it('filters /api/repayments by borrower_id', function () {
    ['loan' => $loanA, 'borrower' => $borrowerA] = makeReleasedLoanForApiTest($this->branch->id, $this->admin);
    ['loan' => $loanB, 'borrower' => $borrowerB] = makeReleasedLoanForApiTest($this->branch->id, $this->admin);

    $service = app(RepaymentService::class);
    $service->processRepayment($loanA, 5000, now()->toDateString(), $this->admin);
    $service->processRepayment($loanA, 3000, now()->toDateString(), $this->admin);
    $service->processRepayment($loanB, 7000, now()->toDateString(), $this->admin);

    $response = $this->getJson("/api/repayments?borrower_id={$borrowerA->id}")->assertSuccessful();

    expect($response->json('data'))->toHaveCount(2);
    foreach ($response->json('data') as $row) {
        expect($row['borrower_id'])->toBe($borrowerA->id);
    }
});

// ── Audit logs: search + export ──────────────────────────────────────

it('filters audit logs by search param', function () {
    // Trigger some auditable actions
    Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $response = $this->getJson('/api/audit-logs?search=created')->assertSuccessful();

    expect($response->json('data'))->toBeArray();
});

it('includes action stats in audit log meta', function () {
    $response = $this->getJson('/api/audit-logs')->assertSuccessful();
    expect($response->json('meta.stats'))->toHaveKey('actions');
    expect($response->json('meta.stats'))->toHaveKey('total');
});

it('exports audit logs as CSV', function () {
    Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $response = $this->get('/api/audit-logs/export');

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $content = $response->streamedContent();
    expect($content)->toContain('Timestamp');
    expect($content)->toContain('User');
    expect($content)->toContain('Action');
});

// ── Dashboard: period + limit params ─────────────────────────────────

it('accepts period=week|month|year on collections-trend', function () {
    $week = $this->getJson('/api/dashboard/collections-trend?period=week')->assertSuccessful();
    expect($week->json('data'))->toBeArray();
    expect(count($week->json('data')))->toBe(12);
    expect($week->json('data.0.period'))->toBe('week');

    $month = $this->getJson('/api/dashboard/collections-trend?period=month')->assertSuccessful();
    expect(count($month->json('data')))->toBe(12);
    expect($month->json('data.0.period'))->toBe('month');

    $year = $this->getJson('/api/dashboard/collections-trend?period=year')->assertSuccessful();
    expect(count($year->json('data')))->toBe(5);
    expect($year->json('data.0.period'))->toBe('year');
});

it('accepts limit param on recent-transactions', function () {
    $response = $this->getJson('/api/dashboard/recent-transactions?limit=5')->assertSuccessful();
    expect(count($response->json('data')))->toBeLessThanOrEqual(5);
});

// ── Bulk borrower operations ─────────────────────────────────────────

it('bulk deactivates borrowers', function () {
    $borrowers = Borrower::factory()->count(3)->create(['branch_id' => $this->branch->id, 'status' => 'active']);
    $ids = $borrowers->pluck('id')->toArray();

    $response = $this->patchJson('/api/borrowers/bulk-deactivate', ['ids' => $ids])
        ->assertSuccessful();

    expect($response->json('deactivated'))->toHaveCount(3);
    expect($response->json('failed'))->toBeEmpty();

    foreach ($ids as $id) {
        $this->assertDatabaseHas('borrowers', ['id' => $id, 'status' => 'inactive']);
    }
});

it('bulk deletes borrowers', function () {
    $borrowers = Borrower::factory()->count(2)->create(['branch_id' => $this->branch->id]);
    $ids = $borrowers->pluck('id')->toArray();

    $response = $this->deleteJson('/api/borrowers/bulk', ['ids' => $ids])
        ->assertSuccessful();

    expect($response->json('deleted'))->toHaveCount(2);

    foreach ($ids as $id) {
        $this->assertDatabaseMissing('borrowers', ['id' => $id]);
    }
});

it('rejects bulk operations with invalid ids', function () {
    $this->patchJson('/api/borrowers/bulk-deactivate', ['ids' => []])
        ->assertStatus(422);

    $this->patchJson('/api/borrowers/bulk-deactivate', ['ids' => [999999]])
        ->assertStatus(422);
});

// ── Repayment preview ────────────────────────────────────────────────

it('returns repayment allocation preview without persisting', function () {
    ['loan' => $loan, 'borrower' => $borrower] = makeReleasedLoanForApiTest($this->branch->id, $this->admin);

    $repaymentsBefore = Repayment::count();

    $response = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
        'amount_paid' => 5000,
        'payment_date' => now()->toDateString(),
    ])->assertSuccessful();

    // Nothing persisted
    expect(Repayment::count())->toBe($repaymentsBefore);

    // Response shape
    $data = $response->json('data');
    expect($data)->toHaveKey('allocated_to_penalty');
    expect($data)->toHaveKey('allocated_to_current_interest');
    expect($data)->toHaveKey('allocated_to_current_principal');
    expect($data)->toHaveKey('overpayment');
    expect($data['is_preview'])->toBeTrue();
    expect((float) $data['amount_paid'])->toBe(5000.0);
});

it('preview matches actual post allocation', function () {
    ['loan' => $loan, 'borrower' => $borrower] = makeReleasedLoanForApiTest($this->branch->id, $this->admin);

    $previewResponse = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
        'amount_paid' => 5000,
        'payment_date' => now()->toDateString(),
    ])->assertSuccessful();

    $preview = $previewResponse->json('data');

    // Now actually post it
    $actual = app(RepaymentService::class)->processRepayment(
        $loan,
        5000,
        now()->toDateString(),
        $this->admin,
    );

    expect((float) $actual->penalty_applied)->toBe((float) $preview['allocated_to_penalty']);
    expect((float) $actual->current_interest_applied)->toBe((float) $preview['allocated_to_current_interest']);
    expect((float) $actual->current_principal_applied)->toBe((float) $preview['allocated_to_current_principal']);
    expect((float) $actual->overpayment)->toBe((float) $preview['overpayment']);
});
