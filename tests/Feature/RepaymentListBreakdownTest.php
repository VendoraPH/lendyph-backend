<?php

/**
 * A repayment LIST row is the detail payload, so nothing needs fetching per row.
 *
 * The loan detail page reads GET /loans/{loan}/repayments and then calls
 * GET /repayments/{id} once per row, because a comment there says "the list
 * endpoint omits [the breakdown fields]; the detail endpoint includes them".
 * It does not, and it never has: all three endpoints render RepaymentResource
 * over the same eager-loaded relations, so every per-row call returns the
 * object the list already sent. Once that page drains the whole list, the
 * redundant call becomes one extra request per repayment on the loan.
 *
 * What these tests pin, so the per-row call can be deleted and stay deleted:
 *
 *  - every row of both lists IS the detail payload for the same repayment,
 *    key for key and value for value. A field added to `show` alone fails
 *    here instead of quietly making the per-row call necessary again;
 *  - the allocation breakdown is on the row, under the names that page falls
 *    back to (`principal_amount`, `interest_amount`, `penalty_amount`) as well
 *    as the `*_applied` columns, with real non-zero values from the allocator;
 *  - a page costs the same number of queries for 3 rows as for 20, so a
 *    drained list costs queries per PAGE and never per row;
 *  - the OpenAPI `Repayment` schema names exactly the keys a row carries. It
 *    used to list only half of them, and none of the aliases the page reads.
 *
 * Not here, because no endpoint has them: `scb_paid` and `excess_amount`,
 * which the same page also reads. The share-capital build-up split is computed
 * in the browser and posted as a separate share-capital ledger entry with no
 * link back to the repayment, so the server holds no per-repayment figure.
 */

use App\Http\Resources\RepaymentResource;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();
});

/**
 * Three payments through the real allocator on a loan two months in, so the
 * split covers penalty, interest and principal rather than a factory's
 * constants. The last one is voided, so the void fields are populated too.
 */
function recordRepaymentBreakdown(TestCase $test, Loan $loan): void
{
    $firstSchedule = $loan->amortizationSchedules->first();

    foreach ([(float) $firstSchedule->total_due + 500, 5000, 2000] as $amount) {
        $test->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated();
    }

    $last = Repayment::query()->where('loan_id', $loan->id)->latest('id')->firstOrFail();

    $test->patchJson("/api/repayments/{$last->id}/void", ['void_reason' => 'Keyed twice'])->assertOk();
}

/**
 * Serves one page and counts every query it cost.
 *
 * @return array{queries: int, rows: int}
 */
function repaymentListQueryCount(TestCase $test, string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $rows = count($test->getJson($url)->assertOk()->json('data'));

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return ['queries' => $queries, 'rows' => $rows];
}

it('serves each row of both lists exactly as GET /repayments/{id} serves it', function () {
    $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);
    recordRepaymentBreakdown($this, $loan);

    foreach (["/api/loans/{$loan->id}/repayments", '/api/repayments'] as $url) {
        $rows = $this->getJson($url)->assertOk()->json('data');

        expect($rows)->toHaveCount(3);

        foreach ($rows as $row) {
            $detail = $this->getJson("/api/repayments/{$row['id']}")->assertOk()->json('data');

            // Same keys, same values, same types. Only the key ORDER is let go.
            ksort($row);
            ksort($detail);

            expect($row)->toBe($detail, "{$url}: row {$row['id']} is not its detail payload.");
        }
    }
});

it('carries the allocation breakdown on every list row, under each name the loan page reads', function () {
    $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);
    recordRepaymentBreakdown($this, $loan);

    $rows = collect($this->getJson("/api/loans/{$loan->id}/repayments")->assertOk()->json('data'));

    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        $repayment = Repayment::findOrFail($row['id']);

        expect($row)->toMatchArray([
            'amount_paid' => (float) $repayment->amount_paid,
            'principal_applied' => (float) $repayment->principal_applied,
            'principal_amount' => (float) $repayment->principal_applied,
            'interest_applied' => (float) $repayment->interest_applied,
            'interest_amount' => (float) $repayment->interest_applied,
            'penalty_applied' => (float) $repayment->penalty_applied,
            'penalty_amount' => (float) $repayment->penalty_applied,
            'overdue_interest_applied' => (float) $repayment->overdue_interest_applied,
            'current_interest_applied' => (float) $repayment->current_interest_applied,
            'current_principal_applied' => (float) $repayment->current_principal_applied,
            'next_interest_applied' => (float) $repayment->next_interest_applied,
            'next_principal_applied' => (float) $repayment->next_principal_applied,
            'overpayment' => (float) $repayment->overpayment,
            'balance_before' => (float) $repayment->balance_before,
            'balance_after' => (float) $repayment->balance_after,
        ]);
    }

    // A real split. A page of zeros would match the assertions above just as
    // well, and prove nothing about the breakdown reaching the list.
    expect($rows->sum('principal_applied'))->toBeGreaterThan(0)
        ->and($rows->sum('interest_applied'))->toBeGreaterThan(0)
        ->and($rows->sum('penalty_applied'))->toBeGreaterThan(0)
        ->and($rows->firstWhere('status', 'voided'))->toMatchArray(['void_reason' => 'Keyed twice'])
        ->and($rows->firstWhere('status', 'voided')['voided_by_user'])->not->toBeNull();
});

it('costs the same number of queries for a page of 3 repayments on one loan as for 20', function () {
    $loan = Loan::factory()->create([
        'branch_id' => $this->branch->id,
        'created_by' => $this->admin->id,
        'status' => 'ongoing',
    ]);
    [$cashier, $teller, $supervisor] = User::factory()->count(3)->create()->all();

    // Mixed receivers and a voided row in BOTH measurements, so every
    // relation the resource reads has rows to load each time.
    $addRepayments = fn (int $count) => Repayment::factory()
        ->count($count)
        ->sequence(
            ['received_by' => $cashier->id],
            ['received_by' => $teller->id],
            ['received_by' => $cashier->id, 'status' => 'voided', 'voided_by' => $supervisor->id, 'voided_at' => now(), 'void_reason' => 'Keyed twice'],
        )
        ->create(['loan_id' => $loan->id]);

    $url = "/api/loans/{$loan->id}/repayments?per_page=20";

    // Warm-up: the first authorised request also resolves the permission
    // tables, which Spatie then keeps in memory. Measuring it would compare a
    // cold request against a warm one.
    $this->getJson($url)->assertOk();

    $addRepayments(3);
    $small = repaymentListQueryCount($this, $url);

    $addRepayments(17);
    $large = repaymentListQueryCount($this, $url);

    expect($small['rows'])->toBe(3)
        ->and($large['rows'])->toBe(20)
        ->and($large['queries'])->toBe($small['queries'], "3 rows cost {$small['queries']} queries and 20 rows cost {$large['queries']}.");
});

it('costs the same number of queries for a page of GET /repayments spanning 3 loans as spanning 20', function () {
    // The factory gives every repayment its OWN loan, borrower, product,
    // branch and receiving user — the case where a per-row lookup cannot
    // hide behind rows that happen to share a parent.
    $addRepayments = fn (int $count) => Repayment::factory()
        ->count($count)
        ->sequence(
            [],
            [],
            ['status' => 'voided', 'voided_by' => $this->admin->id, 'voided_at' => now(), 'void_reason' => 'Keyed twice'],
        )
        ->create();

    $url = '/api/repayments?per_page=20';

    $this->getJson($url)->assertOk();

    $addRepayments(3);
    $small = repaymentListQueryCount($this, $url);

    $addRepayments(17);
    $large = repaymentListQueryCount($this, $url);

    expect($small['rows'])->toBe(3)
        ->and($large['rows'])->toBe(20)
        ->and(Loan::query()->has('repayments')->count())->toBe(20)
        ->and($large['queries'])->toBe($small['queries'], "3 rows cost {$small['queries']} queries and 20 rows cost {$large['queries']}.");
});

it('documents in the OpenAPI Repayment schema exactly the keys a list row carries', function () {
    $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);
    recordRepaymentBreakdown($this, $loan);

    // The voided row: every conditional key is present on it, non-null.
    $row = collect($this->getJson("/api/loans/{$loan->id}/repayments")->assertOk()->json('data'))
        ->firstWhere('status', 'voided');

    $schema = (new ReflectionClass(RepaymentResource::class))
        ->getAttributes(OA\Schema::class)[0]
        ->newInstance();

    $documented = array_map(fn (OA\Property $property) => $property->property, $schema->properties);

    expect(array_values(array_diff(array_keys($row), $documented)))
        ->toBe([], 'Returned by the list but missing from the Repayment schema.')
        ->and(array_values(array_diff($documented, array_keys($row))))
        ->toBe([], 'Documented in the Repayment schema but not returned by the list.');
});
