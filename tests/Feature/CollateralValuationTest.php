<?php

/**
 * `effective_value` and `value_unknown` on CollateralResource: what a collateral
 * is worth as security, computed by the server with the rule the Collateral
 * Register used to apply in the browser.
 *
 *   - A manual collateral is worth its `amount`.
 *   - A share capital collateral is worth its member's balance, credits minus
 *     debits across the whole ledger. It can be negative, it is a known 0 for an
 *     empty ledger, and every one of the member's share capital collaterals
 *     carries all of it.
 *   - A caller without `share_capital:view` gets `value_unknown` and 0 for share
 *     capital. The register must not show a balance the ledger would refuse.
 */

use App\Http\Resources\CollateralResource;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\Loan;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();

    $this->shareCapitalType = CollateralType::where('source', 'share_capital')->firstOrFail();
    $this->manualType = CollateralType::where('name', 'Land Title')->firstOrFail();

    $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
});

/**
 * Each row's valuation as `[effective_value, value_unknown]`, keyed by
 * collateral id so no assertion depends on the index's ordering.
 *
 * JSON drops a float's zero fraction, so a whole-peso value decodes as an int;
 * the type check allows either but refuses a string.
 *
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<int, array{0: float, 1: bool}>
 */
function collateralValuationsById(array $rows): array
{
    return collect($rows)->mapWithKeys(function (array $row): array {
        expect($row)->toHaveKeys(['effective_value', 'value_unknown']);
        expect(is_int($row['effective_value']) || is_float($row['effective_value']))
            ->toBeTrue('effective_value is not a JSON number');
        expect($row['value_unknown'])->toBeBool();

        return [$row['id'] => [(float) $row['effective_value'], $row['value_unknown']]];
    })->all();
}

// ── the rule ─────────────────────────────────────────────────────────────

it('values a manual collateral at its amount', function () {
    $collateral = Collateral::factory()->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->manualType->id,
        'amount' => 250000.50,
    ]);

    // Ledger rows for the same member must not leak into a manual valuation.
    ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => 9000]);

    expect(collateralValuationsById($this->getJson('/api/collaterals')->assertOk()->json('data')))
        ->toEqual([$collateral->id => [250000.50, false]]);
});

it('values share capital collateral at each member\'s ledger balance, credits minus debits', function () {
    $other = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $bystander = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    foreach ([[1000, 0], [2500.25, 0], [0, 300]] as [$credit, $debit]) {
        ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => $credit, 'debit' => $debit]);
    }
    foreach ([[500, 0], [750, 0]] as [$credit, $debit]) {
        ShareCapitalLedger::factory()->create(['borrower_id' => $other->id, 'credit' => $credit, 'debit' => $debit]);
    }
    // A member holding no collateral here; their ledger must not bleed into anyone's figure.
    ShareCapitalLedger::factory()->create(['borrower_id' => $bystander->id, 'credit' => 40000]);

    // The typed-in `amount` is deliberately far from the balance: share capital
    // is valued from the ledger, never from this column.
    $mine = Collateral::factory()->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->shareCapitalType->id,
        'amount' => 99999,
    ]);
    $theirs = Collateral::factory()->create([
        'borrower_id' => $other->id,
        'collateral_type_id' => $this->shareCapitalType->id,
        'amount' => 1,
    ]);

    expect(collateralValuationsById($this->getJson('/api/collaterals')->assertOk()->json('data')))
        ->toEqual([
            $mine->id => [3200.25, false],
            $theirs->id => [1250.0, false],
        ]);
});

it('keeps a negative share capital balance negative', function () {
    ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => 100, 'debit' => 0]);
    ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => 0, 'debit' => 400]);

    $collateral = Collateral::factory()->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->shareCapitalType->id,
    ]);

    expect(collateralValuationsById($this->getJson('/api/collaterals')->assertOk()->json('data')))
        ->toEqual([$collateral->id => [-300.0, false]]);
});

it('values share capital with an empty ledger at a known 0', function () {
    $collateral = Collateral::factory()->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->shareCapitalType->id,
        'amount' => 5000,
    ]);

    expect(collateralValuationsById($this->getJson('/api/collaterals')->assertOk()->json('data')))
        ->toEqual([$collateral->id => [0.0, false]]);
});

it('gives every share capital collateral of one member that member\'s full balance', function () {
    ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => 2000]);

    [$first, $second] = Collateral::factory()->count(2)->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->shareCapitalType->id,
    ])->all();

    expect(collateralValuationsById($this->getJson('/api/collaterals')->assertOk()->json('data')))
        ->toEqual([
            $first->id => [2000.0, false],
            $second->id => [2000.0, false],
        ]);
});

// ── who may see a balance ────────────────────────────────────────────────

it('withholds share capital balances from a caller who cannot view share capital', function () {
    ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => 7500]);

    $shareCapital = Collateral::factory()->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->shareCapitalType->id,
        'amount' => 7500,
    ]);
    $manual = Collateral::factory()->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->manualType->id,
        'amount' => 120000,
    ]);

    $clerk = User::factory()->create();
    $clerk->givePermissionTo('collaterals:view');
    $this->actingAs($clerk);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = $this->getJson('/api/collaterals')->assertOk()->json('data');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect(collateralValuationsById($rows))->toEqual([
        $shareCapital->id => [0.0, true],
        $manual->id => [120000.0, false],
    ]);

    // Not read and then hidden: the balance is never fetched for this caller.
    expect($queries->filter(fn (string $sql) => str_contains($sql, 'share_capital_ledger')))->toBeEmpty();

    $this->getJson("/api/collaterals/{$shareCapital->id}")->assertOk()
        ->assertJsonPath('data.effective_value', 0)
        ->assertJsonPath('data.value_unknown', true);
});

// ── cost ─────────────────────────────────────────────────────────────────

it('values the collateral index in a fixed number of queries however many share capital members it holds', function () {
    // The index is unpaginated. A balance read per row, or per member, is the
    // per-member ledger fan-out this field exists to take off the client.
    $seed = function (int $members): void {
        for ($i = 0; $i < $members; $i++) {
            $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
            ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'credit' => 1000 + $i]);
            ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'credit' => 0, 'debit' => 100]);
            Collateral::factory()->create(['borrower_id' => $member->id, 'collateral_type_id' => $this->shareCapitalType->id]);
            Collateral::factory()->create(['borrower_id' => $member->id, 'collateral_type_id' => $this->manualType->id]);
        }
    };

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->getJson('/api/collaterals')->assertOk()->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Measure a response whose balances were actually read. Skipping the
        // aggregate would also keep the count flat, by answering 0 for everyone.
        $shareCapitalValues = collect($rows)
            ->where('collateral_type.source', 'share_capital')
            ->pluck('effective_value');
        expect($shareCapitalValues)->not->toBeEmpty();
        expect($shareCapitalValues->every(fn ($value) => $value >= 900))->toBeTrue();

        return count($queries);
    };

    // Warm-up: the first authorized request resolves the permission tables,
    // which Spatie then keeps in memory.
    $this->getJson('/api/collaterals')->assertOk();

    $seed(2);
    $small = $countQueries();

    $seed(10);
    expect($this->getJson('/api/collaterals')->assertOk()->json('data'))->toHaveCount(24);
    expect($countQueries())->toBe($small, 'the collateral index is valuing share capital per row');
});

// ── every endpoint that returns a collateral ─────────────────────────────

it('values a collateral on show, store and update exactly as the index does', function () {
    ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => 4200.75]);
    ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => 0, 'debit' => 200.25]);

    $created = $this->postJson('/api/collaterals', [
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->shareCapitalType->id,
        'amount' => 10,
    ])->assertCreated();
    $shareCapitalId = $created->json('data.id');

    $manualId = $this->postJson('/api/collaterals', [
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->manualType->id,
        'amount' => 80000,
    ])->assertCreated()->json('data.id');

    $updatedShareCapital = $this->putJson("/api/collaterals/{$shareCapitalId}", ['amount' => 20])->assertOk();
    $updatedManual = $this->putJson("/api/collaterals/{$manualId}", ['amount' => 95000])->assertOk();

    $index = collateralValuationsById($this->getJson('/api/collaterals')->assertOk()->json('data'));

    expect($index)->toEqual([
        $shareCapitalId => [4000.5, false],
        $manualId => [95000.0, false],
    ]);

    expect(collateralValuationsById([$created->json('data')]))->toEqual([$shareCapitalId => $index[$shareCapitalId]]);
    expect(collateralValuationsById([$updatedShareCapital->json('data')]))->toEqual([$shareCapitalId => $index[$shareCapitalId]]);
    expect(collateralValuationsById([$updatedManual->json('data')]))->toEqual([$manualId => $index[$manualId]]);

    foreach ([$shareCapitalId, $manualId] as $id) {
        expect(collateralValuationsById([$this->getJson("/api/collaterals/{$id}")->assertOk()->json('data')]))
            ->toEqual([$id => $index[$id]]);
    }
});

it('values the collaterals on the loan collateral list and the attach response', function () {
    ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => 15000]);

    $loan = Loan::factory()->create([
        'borrower_id' => $this->member->id,
        'branch_id' => $this->branch->id,
        'created_by' => $this->admin->id,
    ]);
    $shareCapital = Collateral::factory()->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->shareCapitalType->id,
        'amount' => 1,
    ]);
    $manual = Collateral::factory()->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->manualType->id,
        'amount' => 300000,
    ]);

    $attached = $this->postJson("/api/loans/{$loan->id}/collaterals", [
        'collateral_id' => $shareCapital->id,
        'snapshot_value' => 15000,
    ])->assertCreated();

    expect(collateralValuationsById([$attached->json('data')]))->toEqual([$shareCapital->id => [15000.0, false]]);

    $loan->collaterals()->attach($manual->id, ['snapshot_value' => 300000, 'attached_at' => now()]);

    expect(collateralValuationsById($this->getJson("/api/loans/{$loan->id}/collaterals")->assertOk()->json('data')))
        ->toEqual([
            $shareCapital->id => [15000.0, false],
            $manual->id => [300000.0, false],
        ]);
});

it('omits the valuation from a resource that was never valued rather than reporting 0', function () {
    ShareCapitalLedger::factory()->create(['borrower_id' => $this->member->id, 'credit' => 500]);

    $collateral = Collateral::factory()->create([
        'borrower_id' => $this->member->id,
        'collateral_type_id' => $this->shareCapitalType->id,
    ])->load('collateralType');

    expect((new CollateralResource($collateral))->resolve(request()))
        ->not->toHaveKey('effective_value')
        ->not->toHaveKey('value_unknown');
});
