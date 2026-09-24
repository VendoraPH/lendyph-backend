<?php

/**
 * Draining a paginated list must return every row EXACTLY ONCE.
 *
 * The frontend no longer reads one page of a list and hopes. `fetchAllPages`
 * in `src/lib/paginate.ts` follows `meta.last_page` and treats the union of the
 * pages as the whole set — the users screen, the account-officer and borrower
 * pickers, the payment history, the GCash walk-in picker, the member ledger.
 * That is only sound if every page is cut from the SAME ordering, and separate
 * LIMIT/OFFSET queries only agree on an ordering when it is TOTAL.
 *
 * `latest()` orders by `created_at` alone. Rows sharing a second — seeded data,
 * a CSV import, anything written in one request — tie, and MySQL may arrange a
 * run of ties differently on every query. The drained list then serves one row
 * twice and another not at all, and nothing about it looks short: a skipped row
 * is a member or a user who is silently not on screen.
 *
 * The fix is the primary key as the final tiebreaker, in the primary sort's
 * direction. Each case below makes EVERY row its endpoint lists tie on the
 * primary sort key, drains the list two rows at a time the way the frontend
 * does, and checks three things:
 *
 *  1. no row is served twice, and none is missing;
 *  2. the rows arrive in key order — the order the tiebreaker defines, and so
 *     the same order on every request;
 *  3. every paginated SELECT ends its ORDER BY with that key.
 *
 * (1) is the real failure, and it does happen here. Against the controllers
 * as they were, on MySQL 8.0, several of these lists drained as
 * [6, 5, 3, 4, 5, 6, 7] — rows 5 and 6 twice, rows 1 and 2 never — because a
 * small LIMIT can be sorted differently from a larger OFFSET, and MySQL's own
 * manual warns that LIMIT changes the order of ties. But whether it happens on
 * a given run depends on the plan: the same list passed in one run and failed
 * in another, and a list whose sort column is indexed can read its ties in key
 * order anyway, because InnoDB keeps secondary-index entries sorted by the
 * key. So a passing (1) proves little on its own. (3) fails without the fix
 * whatever plan MySQL picks.
 *
 * GET /api/share-capital/ledger already ended in `id`. It is here because the
 * dashboard and the member ledger drain it, so an edit that drops the
 * tiebreaker should fail in this file rather than on a member's balance.
 *
 * The audit-log CSV export is the same defect without a paginator: chunk()
 * walks the result with LIMIT/OFFSET, 500 rows at a time, so it needs the same
 * total order or the file repeats and drops rows at a chunk boundary.
 */

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();
});

function tiedPaginationLoan(int $branchId, int $adminId): Loan
{
    return Loan::factory()->create([
        'branch_id' => $branchId,
        'created_by' => $adminId,
        'status' => 'ongoing',
    ]);
}

it('serves every row exactly once, in key order, when the whole list ties on its sort key', function (array $case) {
    ['url' => $url, 'model' => $model, 'direction' => $direction, 'expected' => $expected] = $case;

    $table = (new $model)->getTable();
    $key = (new $model)->getKeyName();

    // Enough rows that the ties straddle several page boundaries at two a page.
    expect(count($expected))->toBeGreaterThanOrEqual(7);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $seen = [];
    $page = 1;

    do {
        $response = $this->getJson($url.(str_contains($url, '?') ? '&' : '?')."per_page=2&page={$page}")->assertOk();
        $seen = [...$seen, ...array_column($response->json('data'), 'id')];
        $lastPage = (int) $response->json('meta.last_page');
    } while (++$page <= $lastPage && $page <= 50);

    DB::disableQueryLog();

    $drained = '['.implode(', ', $seen).']';

    // 1. Exactly once each — the property the frontend's drain relies on.
    $servedTwice = array_keys(array_filter(array_count_values($seen), fn (int $n) => $n > 1));
    $neverServed = array_values(array_diff($expected, $seen));

    expect($servedTwice)->toBe([], 'Served on two pages: ['.implode(', ', $servedTwice)."]. Drained {$drained}.")
        ->and($neverServed)->toBe([], 'Never served: ['.implode(', ', $neverServed)."]. Drained {$drained}.")
        ->and($seen)->toHaveCount(count($expected));

    // 2. In key order, which is what makes it the same order on every page.
    expect($seen)->toBe($expected, "Ties did not come back in `{$key} {$direction}` order. Drained {$drained}.");

    // 3. Because the SQL says so, not because MySQL happened to agree today.
    //    `limit 2` singles out the page query from any `first()` a resource
    //    might run against the same table.
    $pageQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, "from `{$table}`")
            && str_contains($sql, ' order by ')
            && preg_match('/ limit 2 offset \d+$/', $sql) === 1)
        ->values();

    expect($pageQueries)->toHaveCount($lastPage);

    foreach ($pageQueries as $sql) {
        $orderBy = Str::of($sql)->afterLast(' order by ')->before(' limit ')->toString();

        expect($orderBy)->toMatch(
            '/(^|, )(`'.$table.'`\.)?`'.$key.'` '.$direction.'$/',
            "ORDER BY does not end with the primary key: `{$orderBy}`",
        );
    }
})->with([
    'GET /api/users' => function () {
        User::factory()->count(6)->create();
        // The seeded users too: the whole list is one run of equal keys.
        User::query()->update(['created_at' => '2026-01-15 09:00:00']);

        return [
            'url' => '/api/users',
            'model' => User::class,
            'direction' => 'desc',
            'expected' => User::query()->orderByDesc('id')->pluck('id')->all(),
        ];
    },

    'GET /api/borrowers' => function () {
        Borrower::factory()->count(7)->create(['branch_id' => $this->branch->id]);
        Borrower::query()->update(['created_at' => '2026-01-15 09:00:00']);

        return [
            'url' => '/api/borrowers',
            'model' => Borrower::class,
            'direction' => 'desc',
            'expected' => Borrower::query()->orderByDesc('id')->pluck('id')->all(),
        ];
    },

    'GET /api/audit-logs' => function () {
        foreach (range(1, 7) as $i) {
            AuditLog::create([
                'user_id' => $this->admin->id,
                'action' => 'updated',
                'description' => "Bulk change {$i}",
            ]);
        }
        AuditLog::query()->update(['created_at' => '2026-01-15 09:00:00']);

        return [
            'url' => '/api/audit-logs',
            'model' => AuditLog::class,
            'direction' => 'desc',
            'expected' => AuditLog::query()->orderByDesc('id')->pluck('id')->all(),
        ];
    },

    'GET /api/gcash/non-members' => function () {
        GCashNonMember::factory()->count(7)->create();
        // Sorted by name, so the tie is on the name. Households share one.
        GCashNonMember::query()->update(['full_name' => 'Juan Dela Cruz']);

        return [
            'url' => '/api/gcash/non-members',
            'model' => GCashNonMember::class,
            'direction' => 'asc',
            'expected' => GCashNonMember::query()->orderBy('id')->pluck('id')->all(),
        ];
    },

    'GET /api/gcash/transactions' => function () {
        GCashTransaction::factory()->count(7)->create(['transactor_user_id' => $this->admin->id]);
        GCashTransaction::query()->update(['transaction_date' => '2026-01-15 09:00:00']);

        return [
            'url' => '/api/gcash/transactions',
            'model' => GCashTransaction::class,
            'direction' => 'desc',
            'expected' => GCashTransaction::query()->orderByDesc('id')->pluck('id')->all(),
        ];
    },

    'GET /api/loans/{loan}/adjustments' => function () {
        $loan = tiedPaginationLoan($this->branch->id, $this->admin->id);
        LoanAdjustment::factory()->count(7)->create([
            'loan_id' => $loan->id,
            'adjusted_by' => $this->admin->id,
        ]);
        LoanAdjustment::query()->update(['created_at' => '2026-01-15 09:00:00']);

        return [
            'url' => "/api/loans/{$loan->id}/adjustments",
            'model' => LoanAdjustment::class,
            'direction' => 'desc',
            'expected' => LoanAdjustment::query()->where('loan_id', $loan->id)->orderByDesc('id')->pluck('id')->all(),
        ];
    },

    'GET /api/repayments' => function () {
        $loan = tiedPaginationLoan($this->branch->id, $this->admin->id);
        Repayment::factory()->count(7)->create([
            'loan_id' => $loan->id,
            'received_by' => $this->admin->id,
        ]);
        // `payment_date` is a DATE, so every receipt taken on one day ties —
        // the ordinary case, not a contrived one.
        Repayment::query()->update(['payment_date' => '2026-01-15']);

        return [
            'url' => '/api/repayments',
            'model' => Repayment::class,
            'direction' => 'desc',
            'expected' => Repayment::query()->orderByDesc('id')->pluck('id')->all(),
        ];
    },

    'GET /api/loans/{loan}/repayments' => function () {
        $loan = tiedPaginationLoan($this->branch->id, $this->admin->id);
        Repayment::factory()->count(7)->create([
            'loan_id' => $loan->id,
            'received_by' => $this->admin->id,
        ]);
        Repayment::query()->update(['payment_date' => '2026-01-15']);

        // ASCENDING. Loan::repayments() orders by `payment_date` itself and
        // that clause comes first, so this endpoint is oldest-first whatever
        // the controller's latest() says — and the tiebreaker follows the
        // order the endpoint actually has.
        return [
            'url' => "/api/loans/{$loan->id}/repayments",
            'model' => Repayment::class,
            'direction' => 'asc',
            'expected' => Repayment::query()->where('loan_id', $loan->id)->orderBy('id')->pluck('id')->all(),
        ];
    },

    'GET /api/share-capital/ledger' => function () {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        ShareCapitalLedger::factory()->count(7)->create(['borrower_id' => $borrower->id]);
        ShareCapitalLedger::query()->update(['date' => '2026-01-15']);

        return [
            'url' => '/api/share-capital/ledger',
            'model' => ShareCapitalLedger::class,
            'direction' => 'desc',
            'expected' => ShareCapitalLedger::query()->orderByDesc('id')->pluck('id')->all(),
        ];
    },
]);

it('exports every audit row exactly once when a run of ties spans a chunk boundary', function () {
    // More than one 500-row chunk, all on the same second: the shape a bulk
    // import leaves in the audit trail.
    DB::table('audit_logs')->insert(array_map(fn (int $i) => [
        'user_id' => $this->admin->id,
        'action' => 'updated',
        'description' => sprintf('probe-%04d', $i),
        'created_at' => '2026-01-15 09:00:00',
    ], range(1, 520)));
    AuditLog::query()->update(['created_at' => '2026-01-15 09:00:00']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $csv = $this->get('/api/audit-logs/export')->assertOk()->streamedContent();

    DB::disableQueryLog();

    // Column 5 is Description. Only the probe rows are counted; the seeded
    // baseline's own audit rows ride along in the file and are not the point.
    $probes = collect(explode("\n", trim($csv)))
        ->map(fn (string $line) => str_getcsv($line, escape: '')[5] ?? '')
        ->filter(fn (string $description) => str_starts_with($description, 'probe-'))
        ->values();

    expect($probes)->toHaveCount(520)
        ->and($probes->unique())->toHaveCount(520, 'A row was exported twice, so another was not exported at all.');

    $chunkQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'from `audit_logs`')
            && preg_match('/ limit 500 offset \d+$/', $sql) === 1)
        ->values();

    expect($chunkQueries->count())->toBeGreaterThanOrEqual(2);

    foreach ($chunkQueries as $sql) {
        expect(Str::of($sql)->afterLast(' order by ')->before(' limit ')->toString())
            ->toBe('`created_at` asc, `id` asc');
    }
});
