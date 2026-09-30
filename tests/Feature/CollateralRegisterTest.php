<?php

/**
 * GET /api/collaterals/register — the Collateral Register, grouped by member,
 * filtered, sorted, totalled and paged by the server.
 *
 * It replaces a screen that drained the whole book and did all of that in the
 * browser, so the figures pinned here are the ones that screen showed:
 *
 *   - search and type narrow ROWS, and a group's figures cover only its rows;
 *   - a row's value is `effective_value`, and an unknown one is counted rather
 *     than summed;
 *   - the two count cards describe the whole book, the value card the filter;
 *   - a caller who may not see names sees "Member #{id}", and can neither
 *     search nor sort by the name withheld.
 */

use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\Loan;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();

    $this->shareCapitalType = CollateralType::where('source', 'share_capital')->firstOrFail();
    $this->landTitle = CollateralType::where('name', 'Land Title')->firstOrFail();
    $this->chattel = CollateralType::where('name', 'Chattel')->firstOrFail();
});

/**
 * A member with exactly the name given, so the displayed name is predictable.
 */
function registerMember(int $branchId, string $first, string $last, array $overrides = []): Borrower
{
    return Borrower::factory()->create(array_merge([
        'branch_id' => $branchId,
        'first_name' => $first,
        'middle_name' => null,
        'last_name' => $last,
        'suffix' => null,
    ], $overrides));
}

function registerCollateral(Borrower $member, CollateralType $type, array $overrides = []): Collateral
{
    return Collateral::factory()->create(array_merge([
        'borrower_id' => $member->id,
        'collateral_type_id' => $type->id,
    ], $overrides));
}

/**
 * Hold the collateral on a loan in the given status, writing the pivot row
 * directly so any status can be set up cheaply.
 */
function registerPledge(Collateral $collateral, string $status = 'released'): Loan
{
    $loan = Loan::factory()->create(['borrower_id' => $collateral->borrower_id, 'status' => $status]);
    $loan->collaterals()->attach($collateral->id, ['snapshot_value' => 1, 'attached_at' => now()]);

    return $loan;
}

/**
 * A group's figures with numbers normalised to float: JSON drops a whole
 * number's zero fraction, so 1000.0 arrives as 1000.
 *
 * @param  array<string, mixed>  $group
 * @return array<string, mixed>
 */
function registerFigures(array $group): array
{
    return [
        'borrower_id' => $group['borrower_id'],
        'borrower_name' => $group['borrower_name'],
        'collaterals_count' => $group['collaterals_count'],
        'tagged_count' => $group['tagged_count'],
        'total_value' => (float) $group['total_value'],
        'unknown_count' => $group['unknown_count'],
    ];
}

/**
 * @param  array<string, mixed>  $query
 * @return array<int, int> the member ids of every group, in order, across every page
 */
function registerDrain(object $test, array $query): array
{
    $ids = [];
    $page = 1;

    do {
        $response = $test->getJson('/api/collaterals/register?'.http_build_query($query + ['page' => $page]))->assertOk();
        $ids = array_merge($ids, array_column($response->json('data'), 'borrower_id'));
        $page++;
    } while ($page <= $response->json('meta.last_page'));

    return $ids;
}

// ── grouping and figures ─────────────────────────────────────────────────

it('groups the rows by member with each group\'s figures and its rows newest first', function () {
    $celia = registerMember($this->branch->id, 'Celia', 'Capital');
    $dan = registerMember($this->branch->id, 'Dan', 'Dizon');

    ShareCapitalLedger::factory()->create(['borrower_id' => $celia->id, 'credit' => 3000, 'debit' => 0]);
    ShareCapitalLedger::factory()->create(['borrower_id' => $celia->id, 'credit' => 0, 'debit' => 500]);

    $land = registerCollateral($celia, $this->landTitle, ['amount' => 250000.65, 'created_at' => now()->subDay()]);
    $shareCapital = registerCollateral($celia, $this->shareCapitalType, ['amount' => 1, 'created_at' => now()]);
    $chattel = registerCollateral($celia, $this->chattel, ['amount' => 900, 'created_at' => now()]);
    registerPledge($land);
    registerPledge($shareCapital);
    // A loan outside the active statuses does not tag what it holds.
    registerPledge($chattel, 'completed');

    registerCollateral($dan, $this->chattel, ['amount' => 1000]);

    $response = $this->getJson('/api/collaterals/register')->assertOk();

    expect(array_map('registerFigures', $response->json('data')))->toEqual([
        [
            'borrower_id' => $celia->id,
            'borrower_name' => 'Celia Capital',
            'collaterals_count' => 3,
            'tagged_count' => 2,
            'total_value' => 253400.65,
            'unknown_count' => 0,
        ],
        [
            'borrower_id' => $dan->id,
            'borrower_name' => 'Dan Dizon',
            'collaterals_count' => 1,
            'tagged_count' => 0,
            'total_value' => 1000.0,
            'unknown_count' => 0,
        ],
    ]);

    // Newest first; the two rows sharing a created_at fall back to id, newest id first.
    $rows = $response->json('data.0.collaterals');
    expect(array_column($rows, 'id'))->toBe([$chattel->id, $shareCapital->id, $land->id]);

    // The rows are the full CollateralResource, valued, and add up to the group.
    expect((float) $rows[1]['effective_value'])->toBe(2500.0)
        ->and($rows[1]['value_unknown'])->toBeFalse()
        ->and($rows[1]['collateral_type']['source'])->toBe('share_capital')
        ->and($rows[2]['active_loans'])->toHaveCount(1)
        ->and($rows[0]['active_loans'])->toBe([]);
    expect(round(array_sum(array_column($rows, 'effective_value')), 2))->toBe(253400.65);

    expect($response->json('meta.names_hidden'))->toBeFalse();
    expect($response->json('meta.total'))->toBe(2);
});

it('counts unknown share capital rows per group and leaves them out of every value', function () {
    $ana = registerMember($this->branch->id, 'Ana', 'Aquino');
    $ben = registerMember($this->branch->id, 'Ben', 'Bautista');

    ShareCapitalLedger::factory()->create(['borrower_id' => $ana->id, 'credit' => 5000]);
    ShareCapitalLedger::factory()->create(['borrower_id' => $ben->id, 'credit' => 2000]);

    $anaShares = registerCollateral($ana, $this->shareCapitalType, ['amount' => 5000]);
    registerCollateral($ana, $this->landTitle, ['amount' => 1000]);
    registerCollateral($ben, $this->shareCapitalType, ['amount' => 2000]);

    $clerk = User::factory()->create();
    $clerk->givePermissionTo(['collaterals:view', 'borrowers:view']);
    $this->actingAs($clerk);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->getJson('/api/collaterals/register?sort=total_value')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Ben's only row is unknown, so his known total is 0 and he sorts first.
    expect(array_map('registerFigures', $response->json('data')))->toEqual([
        [
            'borrower_id' => $ben->id,
            'borrower_name' => 'Ben Bautista',
            'collaterals_count' => 1,
            'tagged_count' => 0,
            'total_value' => 0.0,
            'unknown_count' => 1,
        ],
        [
            'borrower_id' => $ana->id,
            'borrower_name' => 'Ana Aquino',
            'collaterals_count' => 2,
            'tagged_count' => 0,
            'total_value' => 1000.0,
            'unknown_count' => 1,
        ],
    ]);

    $anaRow = collect($response->json('data.1.collaterals'))->firstWhere('id', $anaShares->id);
    expect($anaRow['value_unknown'])->toBeTrue()
        ->and((float) $anaRow['effective_value'])->toBe(0.0);

    expect((float) $response->json('meta.totals.total_value'))->toBe(1000.0)
        ->and($response->json('meta.totals.unknown_count'))->toBe(2);

    // Not read and then hidden: the ledger is never queried for this caller.
    expect($queries->filter(fn (string $sql) => str_contains($sql, 'share_capital_ledger')))->toBeEmpty();
});

// ── totals ───────────────────────────────────────────────────────────────

it('totals the whole book for the count cards and the filtered rows for value', function () {
    $ana = registerMember($this->branch->id, 'Ana', 'Aquino');
    $ben = registerMember($this->branch->id, 'Ben', 'Bautista');
    $cora = registerMember($this->branch->id, 'Cora', 'Cruz');

    registerPledge(registerCollateral($ana, $this->landTitle, ['amount' => 100]));
    registerCollateral($ana, $this->chattel, ['amount' => 200]);
    registerCollateral($ben, $this->landTitle, ['amount' => 300.25]);
    ShareCapitalLedger::factory()->create(['borrower_id' => $cora->id, 'credit' => 50]);
    registerPledge(registerCollateral($cora, $this->shareCapitalType));

    $unfiltered = $this->getJson('/api/collaterals/register')->assertOk();
    expect($unfiltered->json('meta.totals'))->toEqual([
        'total_collaterals' => 4,
        'tagged_to_active_loans' => 2,
        'total_value' => 650.25,
        'unknown_count' => 0,
        'members' => 3,
    ]);

    $landOnly = $this->getJson("/api/collaterals/register?collateral_type_id={$this->landTitle->id}")->assertOk();
    expect($landOnly->json('meta.totals'))->toEqual([
        'total_collaterals' => 4,
        'tagged_to_active_loans' => 2,
        'total_value' => 400.25,
        'unknown_count' => 0,
        'members' => 2,
    ]);
    expect($landOnly->json('meta.total'))->toBe(2);

    $nothing = $this->getJson('/api/collaterals/register?search=no-such-collateral')->assertOk();
    expect($nothing->json('data'))->toBe([])
        ->and($nothing->json('meta.total'))->toBe(0)
        ->and($nothing->json('meta.totals'))->toEqual([
            'total_collaterals' => 4,
            'tagged_to_active_loans' => 2,
            'total_value' => 0,
            'unknown_count' => 0,
            'members' => 0,
        ]);
});

// ── filtering ────────────────────────────────────────────────────────────

it('searches the member name as displayed, the detail and the type name', function (string $search, array $expected) {
    $rosa = registerMember($this->branch->id, 'Rosa', 'Villanueva', ['middle_name' => 'Maria', 'suffix' => 'Jr.']);
    $pedro = registerMember($this->branch->id, 'Pedro', 'Lim');
    $juan = registerMember($this->branch->id, 'Juan', 'Dizon');

    registerCollateral($rosa, $this->landTitle, ['detail_value' => 'TCT-777']);
    registerCollateral($rosa, $this->landTitle, ['detail_value' => 'OCT-1']);
    registerCollateral($pedro, $this->chattel, ['detail_value' => null]);
    registerCollateral($juan, $this->shareCapitalType, ['detail_value' => 'PLG-9']);

    $labels = ['rosa' => $rosa->id, 'pedro' => $pedro->id, 'juan' => $juan->id];

    $groups = $this->getJson('/api/collaterals/register?'.http_build_query(['search' => $search]))
        ->assertOk()
        ->json('data');

    expect(collect($groups)->mapWithKeys(fn (array $group) => [$group['borrower_id'] => $group['collaterals_count']])->all())
        ->toBe(collect($expected)->mapWithKeys(fn (int $count, string $label) => [$labels[$label] => $count])->all());

    if (isset($expected['rosa'])) {
        expect($groups[0]['borrower_name'])->toBe($rosa->full_name);
    }
})->with([
    'the full name across middle name and suffix, any case' => ['maria VILLANUEVA jr', ['rosa' => 2]],
    'a detail, narrowing the group to that row' => ['tct-777', ['rosa' => 1]],
    'a type name, on a row with no detail' => ['chattel', ['pedro' => 1]],
    'a surname' => ['lim', ['pedro' => 1]],
    'a percent sign, literally' => ['%', []],
    'an underscore, literally' => ['_', []],
]);

it('filters rows by collateral type', function () {
    $ana = registerMember($this->branch->id, 'Ana', 'Aquino');
    $ben = registerMember($this->branch->id, 'Ben', 'Bautista');

    registerCollateral($ana, $this->landTitle);
    $anaChattel = registerCollateral($ana, $this->chattel, ['amount' => 700]);
    $benChattel = registerCollateral($ben, $this->chattel, ['amount' => 300]);
    registerCollateral($ben, $this->shareCapitalType);

    $groups = $this->getJson("/api/collaterals/register?collateral_type_id={$this->chattel->id}")->assertOk()->json('data');

    expect(array_map(fn (array $group) => [
        $group['borrower_id'],
        $group['collaterals_count'],
        (float) $group['total_value'],
        array_column($group['collaterals'], 'id'),
    ], $groups))->toBe([
        [$ana->id, 1, 700.0, [$anaChattel->id]],
        [$ben->id, 1, 300.0, [$benChattel->id]],
    ]);
});

// ── sorting and paging ───────────────────────────────────────────────────

it('sorts groups by each figure in both directions, breaking ties by name then id', function (string $sort, string $direction, array $expected) {
    //        name           collaterals  total  tagged
    // m1     Carla Cruz     1            300    1
    // m2     Ana Reyes      2            300    0
    // m3     Ben Santos     3            150    2
    // m4     Ana Reyes      1            500    0     (same name as m2, higher id)
    $m1 = registerMember($this->branch->id, 'Carla', 'Cruz');
    $m2 = registerMember($this->branch->id, 'Ana', 'Reyes');
    $m3 = registerMember($this->branch->id, 'Ben', 'Santos');
    $m4 = registerMember($this->branch->id, 'Ana', 'Reyes');

    registerPledge(registerCollateral($m1, $this->landTitle, ['amount' => 300]));
    registerCollateral($m2, $this->landTitle, ['amount' => 100]);
    registerCollateral($m2, $this->chattel, ['amount' => 200]);
    registerPledge(registerCollateral($m3, $this->chattel, ['amount' => 50]));
    registerPledge(registerCollateral($m3, $this->chattel, ['amount' => 50]));
    registerCollateral($m3, $this->chattel, ['amount' => 50]);
    registerCollateral($m4, $this->landTitle, ['amount' => 500]);

    $labels = ['m1' => $m1->id, 'm2' => $m2->id, 'm3' => $m3->id, 'm4' => $m4->id];

    expect(registerDrain($this, ['sort' => $sort, 'direction' => $direction, 'per_page' => 3]))
        ->toBe(array_map(fn (string $label) => $labels[$label], $expected));
})->with([
    'member asc (the default)' => ['member', 'asc', ['m2', 'm4', 'm3', 'm1']],
    'member desc' => ['member', 'desc', ['m1', 'm3', 'm2', 'm4']],
    'collaterals asc' => ['collaterals', 'asc', ['m4', 'm1', 'm2', 'm3']],
    'collaterals desc' => ['collaterals', 'desc', ['m3', 'm2', 'm4', 'm1']],
    'total_value asc' => ['total_value', 'asc', ['m3', 'm2', 'm1', 'm4']],
    'total_value desc' => ['total_value', 'desc', ['m4', 'm2', 'm1', 'm3']],
    'tagged asc' => ['tagged', 'asc', ['m2', 'm4', 'm1', 'm3']],
    'tagged desc' => ['tagged', 'desc', ['m3', 'm1', 'm2', 'm4']],
]);

it('defaults to member name ascending', function () {
    $zed = registerMember($this->branch->id, 'Zed', 'Zamora');
    $abe = registerMember($this->branch->id, 'Abe', 'Abad');
    registerCollateral($zed, $this->landTitle);
    registerCollateral($abe, $this->landTitle);

    expect(array_column($this->getJson('/api/collaterals/register')->assertOk()->json('data'), 'borrower_id'))
        ->toBe([$abe->id, $zed->id]);
});

it('pages groups without repeating or skipping one when every group ties', function (string $sort, string $direction) {
    $ids = [];
    for ($i = 0; $i < 7; $i++) {
        $member = registerMember($this->branch->id, 'Same', 'Name');
        registerCollateral($member, $this->landTitle, ['amount' => 100]);
        $ids[] = $member->id;
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $drained = registerDrain($this, ['sort' => $sort, 'direction' => $direction, 'per_page' => 2]);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($drained)->toBe($ids);

    // Because the SQL says so, not because MySQL happened to agree today: a
    // run of ties can come back in any order the plan likes.
    $pageQueries = $queries->filter(fn (string $sql) => str_contains($sql, 'group by `borrowers`.`id`')
        && preg_match('/ limit 2 offset \d+$/', $sql) === 1);

    expect($pageQueries)->toHaveCount(4);

    // The name is the tiebreak, ascending, unless it is the sort itself.
    $nameDirection = $sort === 'member' ? $direction : 'asc';

    foreach ($pageQueries as $sql) {
        expect(Str::of($sql)->afterLast(' order by ')->before(' limit ')->toString())
            ->toEndWith("`borrower_name` {$nameDirection}, `borrowers`.`id` asc");
    }
})->with(['member', 'collaterals', 'total_value', 'tagged'])->with(['asc', 'desc']);

it('pages member groups, never splitting a member\'s rows across pages', function () {
    $ana = registerMember($this->branch->id, 'Ana', 'Aquino');
    $ben = registerMember($this->branch->id, 'Ben', 'Bautista');
    registerCollateral($ana, $this->landTitle);
    registerCollateral($ana, $this->chattel);
    registerCollateral($ana, $this->landTitle);
    registerCollateral($ben, $this->landTitle);

    $first = $this->getJson('/api/collaterals/register?per_page=1')->assertOk();

    expect($first->json('data'))->toHaveCount(1)
        ->and($first->json('data.0.borrower_id'))->toBe($ana->id)
        ->and($first->json('data.0.collaterals'))->toHaveCount(3)
        ->and($first->json('meta.total'))->toBe(2)
        ->and($first->json('meta.last_page'))->toBe(2);

    expect($this->getJson('/api/collaterals/register?per_page=1&page=2')->assertOk()->json('data.0.borrower_id'))
        ->toBe($ben->id);

    // Clamped, like every list, rather than refused.
    expect($this->getJson('/api/collaterals/register?per_page=500')->assertOk()->json('meta.per_page'))->toBe(100);
});

// ── what the caller may see ──────────────────────────────────────────────

it('shows every member as "Member #id" to a caller without borrowers:view, and searches only that', function () {
    $rosa = registerMember($this->branch->id, 'Rosa', 'Villanueva');
    $pedro = registerMember($this->branch->id, 'Pedro', 'Lim');
    registerCollateral($rosa, $this->landTitle);
    registerCollateral($pedro, $this->landTitle);

    $clerk = User::factory()->create();
    $clerk->givePermissionTo(['collaterals:view', 'share_capital:view']);
    $this->actingAs($clerk);

    $response = $this->getJson('/api/collaterals/register')->assertOk();

    expect($response->json('meta.names_hidden'))->toBeTrue();
    expect(collect($response->json('data'))->pluck('borrower_name', 'borrower_id')->all())->toEqual([
        $rosa->id => "Member #{$rosa->id}",
        $pedro->id => "Member #{$pedro->id}",
    ]);

    // The withheld name is not searchable, or the search box would reveal it.
    expect($this->getJson('/api/collaterals/register?search=villanueva')->assertOk()->json('data'))->toBe([]);

    expect(array_column(
        $this->getJson('/api/collaterals/register?'.http_build_query(['search' => "Member #{$rosa->id}"]))->assertOk()->json('data'),
        'borrower_id',
    ))->toBe([$rosa->id]);
});

it('refuses a caller without collaterals:view', function () {
    $outsider = User::factory()->create();
    $outsider->givePermissionTo(['borrowers:view', 'share_capital:view']);
    $this->actingAs($outsider);

    $this->getJson('/api/collaterals/register')->assertForbidden();
});

it('rejects a malformed query', function (array $query, string $field) {
    $this->getJson('/api/collaterals/register?'.http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown sort' => [['sort' => 'name'], 'sort'],
    'unknown direction' => [['direction' => 'up'], 'direction'],
    'per_page below 1' => [['per_page' => 0], 'per_page'],
    'per_page not a number' => [['per_page' => 'all'], 'per_page'],
    'page below 1' => [['page' => 0], 'page'],
    'type id of 0' => [['collateral_type_id' => 0], 'collateral_type_id'],
    'type id not a number' => [['collateral_type_id' => 'land'], 'collateral_type_id'],
    'search over 100 characters' => [['search' => str_repeat('a', 101)], 'search'],
]);

// ── cost ─────────────────────────────────────────────────────────────────

it('answers in a fixed number of queries however many members and rows a page holds', function () {
    // The screen this replaces read one ledger per member. Grouping, valuing
    // or tagging per group or per row here would move that fan-out to the
    // server rather than remove it.
    $seed = function (int $members): void {
        for ($i = 0; $i < $members; $i++) {
            $member = registerMember($this->branch->id, 'Member', "Number {$i}");
            ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'credit' => 1000 + $i]);
            ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'credit' => 0, 'debit' => 100]);
            registerCollateral($member, $this->shareCapitalType);
            registerPledge(registerCollateral($member, $this->landTitle, ['amount' => 5000]));
        }
    };

    $countQueries = function (int $perPage): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $groups = $this->getJson("/api/collaterals/register?per_page={$perPage}")->assertOk()->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Measure a response that did the work: balances read, rows tagged.
        $rows = collect($groups)->pluck('collaterals')->flatten(1);
        expect($rows->where('collateral_type.source', 'share_capital')->every(fn (array $row) => $row['effective_value'] >= 900))->toBeTrue();
        expect($rows->where('collateral_type.source', 'manual')->every(fn (array $row) => count($row['active_loans']) === 1))->toBeTrue();

        return count($queries);
    };

    // Warm-up: the first authorized request resolves the permission tables,
    // which Spatie then keeps in memory.
    $this->getJson('/api/collaterals/register')->assertOk();

    $seed(2);
    $small = $countQueries(15);

    $seed(10);
    expect($this->getJson('/api/collaterals/register?per_page=100')->assertOk()->json('data'))->toHaveCount(12);
    expect($countQueries(15))->toBe($small, 'the register is doing per-group or per-row work')
        ->and($countQueries(100))->toBe($small, 'the register\'s cost grows with the page size')
        ->and($countQueries(5))->toBe($small);
});
