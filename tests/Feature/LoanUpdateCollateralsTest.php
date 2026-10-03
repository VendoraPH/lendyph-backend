<?php

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/*
 * PUT /api/loans/{loan} reconciles the loan's collateral when, and only when,
 * the request carries `collaterals`. The New Loan page in edit mode used to do
 * it with detach/attach calls of its own, and detached everything when its
 * list had failed to load.
 */

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();
    $this->travelTo(Carbon::parse('2026-09-01 09:00'));

    $this->product = LoanProduct::factory()->create([
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 6,
        'frequency' => 'monthly',
    ]);
    $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $this->loan = app(LoanService::class)->createLoan([
        'borrower_id' => $this->borrower->id,
        'loan_product_id' => $this->product->id,
        'principal_amount' => 60000,
        'start_date' => '2026-09-01',
    ], $this->admin);

    $this->pledge = function (Loan $loan, float $snapshot, ?Collateral $collateral = null): Collateral {
        $collateral ??= Collateral::factory()->create(['borrower_id' => $loan->borrower_id]);
        $loan->collaterals()->attach($collateral->id, ['snapshot_value' => $snapshot, 'attached_at' => now()]);

        return $collateral;
    };

    $this->pivotRows = fn (Loan $loan): array => DB::table('loan_collaterals')
        ->where('loan_id', $loan->id)
        ->orderBy('collateral_id')
        ->get(['collateral_id', 'snapshot_value', 'attached_at', 'created_at', 'updated_at'])
        ->map(fn (object $row): array => (array) $row)
        ->all();
});

it('leaves collateral exactly as it was when the request has no collaterals key', function () {
    ($this->pledge)($this->loan, 150000);
    ($this->pledge)($this->loan, 80000);
    $before = ($this->pivotRows)($this->loan);

    $this->travelTo(Carbon::parse('2026-09-02 10:00'));

    $this->putJson("/api/loans/{$this->loan->id}", ['purpose' => 'Store expansion'])->assertOk();

    expect($this->loan->fresh()->purpose)->toBe('Store expansion')
        ->and(($this->pivotRows)($this->loan))->toBe($before)
        ->and(AuditLog::where('action', 'collaterals_updated')->count())->toBe(0);
});

it('detaches the unlisted, attaches the new and keeps an existing snapshot', function () {
    $kept = ($this->pledge)($this->loan, 150000);
    $dropped = ($this->pledge)($this->loan, 80000);
    $keptBefore = DB::table('loan_collaterals')->where('loan_id', $this->loan->id)->where('collateral_id', $kept->id)->first();
    $added = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $this->travelTo(Carbon::parse('2026-09-02 10:00'));

    $this->putJson("/api/loans/{$this->loan->id}", [
        'purpose' => 'Store expansion',
        'collaterals' => [
            // A different value for one it already holds is not a re-appraisal.
            ['collateral_id' => $kept->id, 'snapshot_value' => 999],
            ['collateral_id' => $added->id, 'snapshot_value' => 45000.5],
        ],
    ])->assertOk();

    $rows = DB::table('loan_collaterals')->where('loan_id', $this->loan->id)->orderBy('collateral_id')->get()->keyBy('collateral_id');

    expect($rows->keys()->all())->toBe([$kept->id, $added->id])
        ->and((float) $rows[$kept->id]->snapshot_value)->toBe(150000.0)
        ->and($rows[$kept->id]->attached_at)->toBe($keptBefore->attached_at)
        ->and((float) $rows[$added->id]->snapshot_value)->toBe(45000.5)
        ->and($rows[$added->id]->attached_at)->toBe('2026-09-02 10:00:00')
        ->and($this->loan->fresh()->purpose)->toBe('Store expansion');

    $entry = AuditLog::where('action', 'collaterals_updated')->sole();

    expect($entry->auditable_id)->toBe($this->loan->id)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->old_values)->toBe(['collateral_ids' => [$kept->id, $dropped->id]])
        // toEqual: the JSON column reorders keys and stores 80000.0 as 80000.
        ->and($entry->new_values)->toEqual([
            'collateral_ids' => [$kept->id, $added->id],
            'attached' => [['collateral_id' => $added->id, 'snapshot_value' => 45000.5]],
            'detached' => [['collateral_id' => $dropped->id, 'snapshot_value' => 80000]],
        ]);
});

it('writes no collateral audit row when the list matches what the loan holds', function () {
    $held = ($this->pledge)($this->loan, 150000);

    $this->putJson("/api/loans/{$this->loan->id}", [
        'collaterals' => [['collateral_id' => $held->id, 'snapshot_value' => 150000]],
    ])->assertOk();

    expect(AuditLog::where('action', 'collaterals_updated')->count())->toBe(0)
        ->and($this->loan->collaterals()->pluck('collaterals.id')->all())->toBe([$held->id]);
});

it('detaches every collateral for an empty list', function () {
    ($this->pledge)($this->loan, 150000);
    ($this->pledge)($this->loan, 80000);

    $this->putJson("/api/loans/{$this->loan->id}", ['collaterals' => []])->assertOk();

    expect($this->loan->collaterals()->count())->toBe(0)
        ->and(AuditLog::where('action', 'collaterals_updated')->sole()->new_values['collateral_ids'])->toBe([]);
});

it('refuses null, which is not a list', function () {
    ($this->pledge)($this->loan, 150000);
    $before = ($this->pivotRows)($this->loan);

    $this->putJson("/api/loans/{$this->loan->id}", ['purpose' => 'Changed', 'collaterals' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['collaterals' => 'The collaterals field must be a list.']);

    expect(($this->pivotRows)($this->loan))->toBe($before)
        ->and($this->loan->fresh()->purpose)->not->toBe('Changed');
});

it('refuses another borrower\'s collateral', function () {
    $held = ($this->pledge)($this->loan, 150000);
    $someoneElses = Collateral::factory()->create([
        'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
    ]);

    $this->putJson("/api/loans/{$this->loan->id}", [
        'collaterals' => [
            ['collateral_id' => $held->id, 'snapshot_value' => 150000],
            ['collateral_id' => $someoneElses->id, 'snapshot_value' => 1000],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['collaterals.1.collateral_id' => 'This collateral is not registered to this loan\'s borrower.']);

    expect($this->loan->collaterals()->pluck('collaterals.id')->all())->toBe([$held->id]);
});

it('refuses the same collateral listed twice', function () {
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $this->putJson("/api/loans/{$this->loan->id}", [
        'collaterals' => [
            ['collateral_id' => $collateral->id, 'snapshot_value' => 1000],
            ['collateral_id' => $collateral->id, 'snapshot_value' => 2000],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['collaterals.0.collateral_id' => 'Each collateral can be listed only once.']);

    expect($this->loan->collaterals()->count())->toBe(0);
});

it('refuses a missing or out-of-range snapshot value with the attach endpoint\'s rules', function () {
    $collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
    $another = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);

    $this->putJson("/api/loans/{$this->loan->id}", [
        'collaterals' => [
            ['collateral_id' => $collateral->id],
            ['collateral_id' => $another->id, 'snapshot_value' => -1],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['collaterals.0.snapshot_value', 'collaterals.1.snapshot_value']);

    $this->putJson("/api/loans/{$this->loan->id}", [
        'collaterals' => [['collateral_id' => $collateral->id, 'snapshot_value' => 100000000]],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['collaterals.0.snapshot_value']);
});

it('refuses a collateral another active loan holds, and saves nothing from the request', function () {
    $holder = $this->createReleasedLoan();
    $pledged = ($this->pledge)($holder, 300000);
    $this->loan = app(LoanService::class)->createLoan([
        'borrower_id' => $holder->borrower_id,
        'loan_product_id' => $this->product->id,
        'principal_amount' => 60000,
        'start_date' => '2026-09-01',
    ], $this->admin);
    ($this->pledge)($this->loan, 150000);
    $before = ($this->pivotRows)($this->loan);

    $this->putJson("/api/loans/{$this->loan->id}", [
        'purpose' => 'Changed',
        'collaterals' => [['collateral_id' => $pledged->id, 'snapshot_value' => 300000]],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'collaterals.0.collateral_id' => "This collateral is already pledged to active loan {$holder->loan_account_number}. Detach it there first.",
        ]);

    // The detach of $held ran before the refused attach; the transaction took
    // both back, and the loan's own field with them.
    expect(($this->pivotRows)($this->loan))->toBe($before)
        ->and($this->loan->fresh()->purpose)->not->toBe('Changed')
        ->and($holder->collaterals()->pluck('collaterals.id')->all())->toBe([$pledged->id])
        ->and(AuditLog::where('action', 'collaterals_updated')->count())->toBe(0);
});

it('needs collaterals:update to send the key, whatever its value', function () {
    $held = ($this->pledge)($this->loan, 150000);
    $before = ($this->pivotRows)($this->loan);

    $editor = User::factory()->create();
    $editor->givePermissionTo('loans:update');
    $this->actingAs($editor);

    foreach ([[], null, [['collateral_id' => $held->id, 'snapshot_value' => 150000]]] as $collaterals) {
        $this->putJson("/api/loans/{$this->loan->id}", ['collaterals' => $collaterals])->assertForbidden();
    }

    // Without the key the same user still edits the loan.
    $this->putJson("/api/loans/{$this->loan->id}", ['purpose' => 'Edited by loans:update only'])->assertOk();

    expect(($this->pivotRows)($this->loan))->toBe($before);

    $editor->givePermissionTo('collaterals:update');

    $this->putJson("/api/loans/{$this->loan->id}", ['collaterals' => []])->assertOk();

    expect($this->loan->collaterals()->count())->toBe(0);
});

it('keeps the edit status rule: a released loan\'s collateral is not touched', function () {
    $released = $this->createReleasedLoan();
    ($this->pledge)($released, 150000);
    $before = ($this->pivotRows)($released);

    $this->putJson("/api/loans/{$released->id}", ['collaterals' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(($this->pivotRows)($released))->toBe($before);
});
