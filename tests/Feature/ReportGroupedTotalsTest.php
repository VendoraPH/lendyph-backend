<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/reports/income/by-loan and GET /api/reports/borrowers/released.
 *
 * Both group a list report's rows on the server so the browser never adds
 * money up. Every test here is about a figure or a membership: that the
 * groups' totals are the existing reports' totals, that a group holds rows the
 * 200-row list page would have cut off, that the paginator counts groups, and
 * that the shared filters select the same rows.
 */
class ReportGroupedTotalsTest extends TestCase
{
    use SetupLendyPH;

    private LoanProduct $product;

    private int $loanNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->product = LoanProduct::factory()->create();
    }

    // ── Totals reconcile with the existing reports ───────────────────────

    public function test_income_by_loan_totals_equal_the_income_and_repayments_reports_for_the_same_filters(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();

        $a = $this->releasedLoan($this->borrower(), '2026-01-05 09:00:00', 60000, $branch);
        $b = $this->releasedLoan($this->borrower(), '2026-01-06 09:00:00', 30000, $branch);
        $c = $this->releasedLoan($this->borrower(), '2026-01-07 09:00:00', 45000, $other);

        $this->repayment($a, '2026-02-01', 1800.00, 57.99);
        $this->repayment($a, '2026-03-01', 1800.00, 57.99);
        $this->repayment($b, '2026-02-15', 900.25, 0);
        $this->repayment($b, '2026-04-15', 900.25, 12.34);
        $this->repayment($b, '2026-03-10', 500.00, 99.99, 'voided');
        $this->repayment($c, '2026-02-20', 1350.10, 20.01);
        $this->repayment($c, '2026-05-20', 1350.10, 0);

        $filterSets = [
            'no filter' => '',
            'date range' => 'date_from=2026-02-01&date_to=2026-03-31',
            'branch' => "branch_id={$branch->id}",
            'branch and date range' => "branch_id={$branch->id}&date_from=2026-02-10&date_to=2026-04-30",
        ];

        foreach ($filterSets as $label => $query) {
            $grouped = $this->getJson("/api/reports/income/by-loan?{$query}")->assertOk();
            $income = $this->getJson("/api/reports/income?{$query}")->assertOk();
            $repayments = $this->getJson("/api/reports/repayments?status=posted&{$query}")->assertOk();

            $totals = $grouped->json('totals');

            $this->assertEquals($income->json('data.interest_income'), $totals['interest_income'], "{$label}: interest vs /reports/income");
            $this->assertEquals($income->json('data.penalty_income'), $totals['penalty_income'], "{$label}: penalty vs /reports/income");
            $this->assertEquals($repayments->json('totals.total_interest_applied'), $totals['interest_income'], "{$label}: interest vs /reports/repayments");
            $this->assertEquals($repayments->json('totals.total_penalty_applied'), $totals['penalty_income'], "{$label}: penalty vs /reports/repayments");
            $this->assertSame($repayments->json('totals.count'), $totals['payments'], "{$label}: payments vs /reports/repayments count");
            $this->assertEqualsWithDelta($totals['interest_income'] + $totals['penalty_income'], $totals['total_income'], 0.001, $label);
            $this->assertSame($grouped->json('meta.total'), $totals['count'], "{$label}: totals.count is the number of loans");

            $rows = $grouped->json('data');
            $this->assertSame($totals['count'], count($rows), $label);
            $this->assertSame($totals['payments'], array_sum(array_column($rows, 'payments')), $label);
            $this->assertEqualsWithDelta($totals['interest_income'], array_sum(array_column($rows, 'interest_income')), 0.001, $label);
            $this->assertEqualsWithDelta($totals['penalty_income'], array_sum(array_column($rows, 'penalty_income')), 0.001, $label);
        }

        // Spot-check exact figures for one filter so the equalities above can't
        // pass by everything being zero.
        $branchOnly = $this->getJson("/api/reports/income/by-loan?branch_id={$branch->id}")->assertOk();
        $this->assertSame(
            ['count' => 2, 'payments' => 4, 'interest_income' => 5400.5, 'penalty_income' => 128.32, 'total_income' => 5528.82],
            $branchOnly->json('totals'),
        );
    }

    public function test_borrowers_released_totals_equal_the_releases_report_for_the_same_filters(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();

        $juan = $this->borrower();
        $maria = $this->borrower();

        $this->releasedLoan($juan, '2026-01-05 09:00:00', 60000.50, $branch);
        $this->releasedLoan($juan, '2026-03-05 09:00:00', 70000.25, $branch, 'completed');
        $this->releasedLoan($maria, '2026-02-10 09:00:00', 80000.00, $branch, 'defaulted');
        $this->releasedLoan($maria, '2026-02-11 09:00:00', 15000.00, $other);
        $this->releasedLoan($maria, '2026-02-12 09:00:00', 99999.00, $branch, 'approved');

        $filterSets = [
            'no filter' => '',
            'date range' => 'date_from=2026-02-01&date_to=2026-03-31',
            'branch' => "branch_id={$branch->id}",
            'branch and date range' => "branch_id={$branch->id}&date_from=2026-01-01&date_to=2026-02-28",
        ];

        foreach ($filterSets as $label => $query) {
            $grouped = $this->getJson("/api/reports/borrowers/released?{$query}")->assertOk();
            $releases = $this->getJson("/api/reports/releases?{$query}")->assertOk();

            $totals = $grouped->json('totals');

            $this->assertSame($releases->json('totals.count'), $totals['loan_count'], "{$label}: loan_count vs /reports/releases count");
            $this->assertEquals($releases->json('totals.total_principal'), $totals['total_principal'], "{$label}: principal vs /reports/releases");
            $this->assertSame($grouped->json('meta.total'), $totals['count'], "{$label}: totals.count is the number of borrowers");

            $rows = $grouped->json('data');
            $this->assertSame($totals['count'], count($rows), $label);
            $this->assertSame($totals['loan_count'], array_sum(array_column($rows, 'loan_count')), $label);
            $this->assertEqualsWithDelta($totals['total_principal'], array_sum(array_column($rows, 'total_principal')), 0.001, $label);
        }

        $branchOnly = $this->getJson("/api/reports/borrowers/released?branch_id={$branch->id}")->assertOk();
        $this->assertSame(
            ['count' => 2, 'loan_count' => 3, 'total_principal' => 210000.75],
            $branchOnly->json('totals'),
        );
    }

    // ── Groups cover rows past the list's first page ─────────────────────

    public function test_a_loans_income_row_includes_repayments_on_both_sides_of_the_200th_list_row(): void
    {
        $branch = Branch::factory()->create();

        $target = $this->releasedLoan($this->borrower(), '2025-12-01 09:00:00', 60000, $branch);
        $fillers = [
            $this->releasedLoan($this->borrower(), '2025-12-01 09:00:00', 60000, $branch),
            $this->releasedLoan($this->borrower(), '2025-12-01 09:00:00', 60000, $branch),
            $this->releasedLoan($this->borrower(), '2025-12-01 09:00:00', 60000, $branch),
        ];

        // List order is payment_date desc, so the target's newest receipt is
        // row 1 and its oldest is row 205, with 203 filler receipts between.
        $newest = $this->repayment($target, '2026-09-30', 1800.00, 57.99);
        $oldest = $this->repayment($target, '2026-01-01', 1700.00, 12.01);

        for ($i = 0; $i < 203; $i++) {
            $this->repayment($fillers[$i % 3], sprintf('2026-%02d-%02d', 2 + intdiv($i, 28), 1 + $i % 28), 10.00, 0.50);
        }

        $firstListPage = $this->getJson("/api/reports/repayments?branch_id={$branch->id}&per_page=200")->assertOk();
        $listedIds = array_column($firstListPage->json('data'), 'id');
        $this->assertContains($newest->id, $listedIds);
        $this->assertNotContains($oldest->id, $listedIds, 'The fixture must put the oldest receipt past row 200.');
        $this->assertSame(205, $firstListPage->json('totals.count'));

        $grouped = $this->getJson("/api/reports/income/by-loan?branch_id={$branch->id}&per_page=200")->assertOk();

        $row = collect($grouped->json('data'))->firstWhere('loan_id', $target->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['payments']);
        $this->assertEquals(3500.00, $row['interest_income']);
        $this->assertEquals(70.00, $row['penalty_income']);
        $this->assertEquals(3570.00, $row['total_income']);

        $this->assertSame(4, $grouped->json('meta.total'));
        $this->assertSame(
            ['count' => 4, 'payments' => 205, 'interest_income' => 5530, 'penalty_income' => 171.5, 'total_income' => 5701.5],
            $grouped->json('totals'),
        );
    }

    public function test_a_borrowers_row_includes_loans_on_both_sides_of_the_200th_list_row(): void
    {
        $branch = Branch::factory()->create();

        $target = $this->borrower();
        $fillerBorrowers = [$this->borrower(), $this->borrower(), $this->borrower()];

        // List order is released_at desc: the target's newest loan is row 1,
        // its oldest is row 203, with 201 filler loans between.
        $newest = $this->releasedLoan($target, '2026-09-30 15:00:00', 70000, $branch, 'ongoing');
        $oldest = $this->releasedLoan($target, '2026-01-01 08:00:00', 50000, $branch, 'completed');

        for ($i = 0; $i < 201; $i++) {
            $this->releasedLoan($fillerBorrowers[$i % 3], sprintf('2026-%02d-%02d 10:00:00', 2 + intdiv($i, 28), 1 + $i % 28), 1000, $branch);
        }

        $firstListPage = $this->getJson("/api/reports/releases?branch_id={$branch->id}&per_page=200")->assertOk();
        $listedIds = array_column($firstListPage->json('data'), 'id');
        $this->assertContains($newest->id, $listedIds);
        $this->assertNotContains($oldest->id, $listedIds, 'The fixture must put the oldest loan past row 200.');

        $grouped = $this->getJson("/api/reports/borrowers/released?branch_id={$branch->id}&per_page=200")->assertOk();

        $row = collect($grouped->json('data'))->firstWhere('borrower_id', $target->id);
        $this->assertSame([
            'borrower_id' => $target->id,
            'borrower_name' => $target->full_name,
            'loan_count' => 2,
            'loan_account_numbers' => [$oldest->loan_account_number, $newest->loan_account_number],
            'total_principal' => 120000,
            'last_released_at' => '2026-09-30',
            'latest_loan_status' => 'ongoing',
        ], $row);

        $this->assertSame(4, $grouped->json('meta.total'));
        $this->assertSame(['count' => 4, 'loan_count' => 203, 'total_principal' => 321000], $grouped->json('totals'));
        $this->assertSame(203, $firstListPage->json('totals.count'));
    }

    // ── Pagination counts and walks groups ───────────────────────────────

    public function test_income_by_loan_pages_count_loans_and_walk_every_loan_once_in_order(): void
    {
        $branch = Branch::factory()->create();

        // Interest and penalty per receipt. Four loans tie on 1,000.00 so the
        // loan_id tiebreak is exercised.
        $perReceipt = [
            [500.00, 0], [125.25, 5.00], [500.00, 0], [40.00, 0],
            [250.00, 250.00], [499.99, 0.01], [5.00, 0],
        ];

        $expected = [];
        foreach ($perReceipt as [$interest, $penalty]) {
            $loan = $this->releasedLoan($this->borrower(), '2026-01-02 09:00:00', 60000, $branch);
            // Two receipts per loan so a page of loans is not a page of
            // repayments.
            $this->repayment($loan, '2026-02-01', $interest, $penalty);
            $this->repayment($loan, '2026-03-01', $interest, $penalty);
            $expected[] = ['loan_id' => $loan->id, 'total' => round(2 * ($interest + $penalty), 2)];
        }

        usort($expected, fn ($x, $y) => [$y['total'], $x['loan_id']] <=> [$x['total'], $y['loan_id']]);
        $expectedIds = array_column($expected, 'loan_id');

        $walked = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson("/api/reports/income/by-loan?branch_id={$branch->id}&per_page=3&page={$page}")->assertOk();

            $this->assertSame(7, $response->json('meta.total'), 'meta.total counts loans, not repayments.');
            $this->assertSame(3, $response->json('meta.last_page'));
            $this->assertSame(7, $response->json('totals.count'));
            $this->assertSame(14, $response->json('totals.payments'), 'totals cover every page, not this one.');

            $walked = array_merge($walked, array_column($response->json('data'), 'loan_id'));
        }

        $this->assertSame($expectedIds, $walked, 'Pages walk every loan exactly once, by total_income desc then loan_id asc.');
    }

    public function test_borrowers_released_pages_count_borrowers_and_walk_every_borrower_once_in_order(): void
    {
        $branch = Branch::factory()->create();

        $names = [
            ['Carlos', null, 'Reyes', null], ['Ana', 'Lopez', 'Cruz', null], ['Ben', null, 'Diaz', 'Jr.'],
            ['Ana', null, 'Cruz', null], ['Dina', null, 'Santos', null], ['Ana', null, 'Cruz', null],
            ['Elena', null, 'Bautista', null],
        ];

        $expected = [];
        foreach ($names as [$first, $middle, $last, $suffix]) {
            $borrower = $this->borrower(['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'suffix' => $suffix]);
            // Two loans each so a page of borrowers is not a page of loans.
            $this->releasedLoan($borrower, '2026-01-02 09:00:00', 10000, $branch);
            $this->releasedLoan($borrower, '2026-01-03 09:00:00', 20000, $branch);
            $expected[] = ['borrower_id' => $borrower->id, 'name' => $borrower->full_name];
        }

        usort($expected, fn ($x, $y) => [$x['name'], $x['borrower_id']] <=> [$y['name'], $y['borrower_id']]);

        $walked = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson("/api/reports/borrowers/released?branch_id={$branch->id}&per_page=3&page={$page}")->assertOk();

            $this->assertSame(7, $response->json('meta.total'), 'meta.total counts borrowers, not loans.');
            $this->assertSame(3, $response->json('meta.last_page'));
            $this->assertSame(7, $response->json('totals.count'));
            $this->assertSame(14, $response->json('totals.loan_count'), 'totals cover every page, not this one.');

            foreach ($response->json('data') as $row) {
                $walked[] = ['borrower_id' => $row['borrower_id'], 'name' => $row['borrower_name']];
            }
        }

        $this->assertSame($expected, $walked, 'Pages walk every borrower exactly once, by name asc then borrower_id asc.');
        $this->assertSame('Ana Cruz', $walked[0]['name']);
        $this->assertSame('Ana Lopez Cruz', $walked[2]['name']);
        $this->assertSame('Ben Diaz Jr.', $walked[3]['name']);
    }

    public function test_a_page_costs_the_same_number_of_queries_however_many_groups_it_holds(): void
    {
        $branch = Branch::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $borrower = $this->borrower();
            $loan = $this->releasedLoan($borrower, '2026-01-02 09:00:00', 10000, $branch);
            $this->releasedLoan($borrower, '2026-01-03 09:00:00', 10000, $branch);
            $this->repayment($loan, '2026-02-01', 100, 1);
            $this->repayment($loan, '2026-03-01', 100, 1);
        }

        foreach (['/api/reports/income/by-loan', '/api/reports/borrowers/released'] as $endpoint) {
            // The first request also loads the caller's roles and permissions;
            // keep that out of the comparison.
            $this->getJson("{$endpoint}?branch_id={$branch->id}")->assertOk();

            $counts = [];

            foreach ([1, 6] as $perPage) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $response = $this->getJson("{$endpoint}?branch_id={$branch->id}&per_page={$perPage}")->assertOk();
                DB::disableQueryLog();

                $this->assertCount($perPage, $response->json('data'));
                $counts[$perPage] = count(DB::getQueryLog());
            }

            $this->assertSame($counts[1], $counts[6], "{$endpoint}: a bigger page must not cost more queries.");
        }
    }

    // ── Filters ──────────────────────────────────────────────────────────

    public function test_income_by_loan_applies_the_date_bounds_inclusively_scopes_the_branch_and_skips_voided_receipts(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();

        $loan = $this->releasedLoan($this->borrower(), '2026-01-02 09:00:00', 60000, $branch);
        $elsewhere = $this->releasedLoan($this->borrower(), '2026-01-02 09:00:00', 60000, $other);

        $this->repayment($loan, '2026-03-01', 100.00, 1.00);   // on date_from: in
        $this->repayment($loan, '2026-03-31', 200.00, 2.00);   // on date_to: in
        $this->repayment($loan, '2026-02-28', 400.00, 4.00);   // day before: out
        $this->repayment($loan, '2026-04-01', 800.00, 8.00);   // day after: out
        $this->repayment($loan, '2026-03-15', 1600.00, 16.00, 'voided');
        $this->repayment($elsewhere, '2026-03-15', 3200.00, 32.00);

        $response = $this->getJson("/api/reports/income/by-loan?branch_id={$branch->id}&date_from=2026-03-01&date_to=2026-03-31")
            ->assertOk();

        $this->assertSame([[
            'loan_id' => $loan->id,
            'loan_account_number' => $loan->loan_account_number,
            'borrower_id' => $loan->borrower_id,
            'borrower_name' => $loan->borrower->full_name,
            'payments' => 2,
            'interest_income' => 300,
            'penalty_income' => 3,
            'total_income' => 303,
        ]], $response->json('data'));
        $this->assertSame(['count' => 1, 'payments' => 2, 'interest_income' => 300, 'penalty_income' => 3, 'total_income' => 303], $response->json('totals'));

        // The other branch's loan appears only when the branch filter allows it.
        $unscoped = $this->getJson('/api/reports/income/by-loan?date_from=2026-03-01&date_to=2026-03-31')->assertOk();
        $this->assertEqualsCanonicalizing([$loan->id, $elsewhere->id], array_column($unscoped->json('data'), 'loan_id'));
    }

    public function test_borrowers_released_applies_the_date_bounds_inclusively_scopes_the_branch_and_skips_unreleased_loans(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();

        $borrower = $this->borrower();

        $first = $this->releasedLoan($borrower, '2026-03-01 00:00:00', 1000, $branch, 'completed');      // on date_from: in
        $unnumbered = $this->releasedLoan($borrower, '2026-03-10 09:00:00', 2000, $branch, 'ongoing', null);
        $sameTimeLow = $this->releasedLoan($borrower, '2026-03-31 23:59:59', 4000, $branch, 'restructured'); // on date_to: in
        $sameTimeHigh = $this->releasedLoan($borrower, '2026-03-31 23:59:59', 8000, $branch, 'released');  // tie: higher id wins
        $this->releasedLoan($borrower, '2026-02-28 23:59:59', 16000, $branch);                            // day before: out
        $this->releasedLoan($borrower, '2026-04-01 00:00:00', 32000, $branch);                            // day after: out
        foreach (['draft', 'for_review', 'approved', 'rejected', 'void'] as $status) {
            $this->releasedLoan($borrower, '2026-03-15 09:00:00', 64000, $branch, $status);
        }
        $this->releasedLoan($borrower, '2026-03-15 09:00:00', 128000, $other);

        $this->assertGreaterThan($sameTimeLow->id, $sameTimeHigh->id);

        $response = $this->getJson("/api/reports/borrowers/released?branch_id={$branch->id}&date_from=2026-03-01&date_to=2026-03-31")
            ->assertOk();

        $this->assertSame([[
            'borrower_id' => $borrower->id,
            'borrower_name' => $borrower->full_name,
            'loan_count' => 4,
            'loan_account_numbers' => [
                $first->loan_account_number,
                $sameTimeLow->loan_account_number,
                $sameTimeHigh->loan_account_number,
            ],
            'total_principal' => 15000,
            'last_released_at' => '2026-03-31',
            'latest_loan_status' => 'released',
        ]], $response->json('data'));
        $this->assertNull($unnumbered->loan_account_number);
        $this->assertSame(['count' => 1, 'loan_count' => 4, 'total_principal' => 15000], $response->json('totals'));

        $unscoped = $this->getJson('/api/reports/borrowers/released?date_from=2026-03-01&date_to=2026-03-31')->assertOk();
        $this->assertSame(5, $unscoped->json('data.0.loan_count'), 'Without the branch filter the other branch\'s loan counts too.');
    }

    public function test_an_empty_period_returns_no_rows_and_zero_totals(): void
    {
        $this->getJson('/api/reports/income/by-loan?date_from=1999-01-01&date_to=1999-01-31')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('totals', ['count' => 0, 'payments' => 0, 'interest_income' => 0, 'penalty_income' => 0, 'total_income' => 0]);

        $this->getJson('/api/reports/borrowers/released?date_from=1999-01-01&date_to=1999-01-31')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('totals', ['count' => 0, 'loan_count' => 0, 'total_principal' => 0]);
    }

    // ── Access and validation ────────────────────────────────────────────

    public function test_both_reports_require_reports_view(): void
    {
        $role = Role::create([
            'name' => 'grouped_reports_no_access',
            'guard_name' => 'web',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->syncPermissions(['dashboard:view']);

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertFalse($user->can('reports:view'));

        $this->getJson('/api/reports/income/by-loan')->assertForbidden();
        $this->getJson('/api/reports/borrowers/released')->assertForbidden();
    }

    public function test_both_reports_reject_invalid_filters_with_422(): void
    {
        $invalid = [
            'date_to before date_from' => ['date_from=2026-03-01&date_to=2026-02-01', 'date_to'],
            'zero branch_id' => ['branch_id=0', 'branch_id'],
            'non-date date_from' => ['date_from=not-a-date', 'date_from'],
            'per_page above 1000' => ['per_page=1001', 'per_page'],
            'per_page zero' => ['per_page=0', 'per_page'],
            'span over ten years' => ['date_from=2000-01-01&date_to=2026-01-01', 'date_from'],
        ];

        foreach (['/api/reports/income/by-loan', '/api/reports/borrowers/released'] as $endpoint) {
            foreach ($invalid as [$query, $field]) {
                $this->getJson("{$endpoint}?{$query}")
                    ->assertStatus(422)
                    ->assertJsonValidationErrors([$field]);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function borrower(array $attributes = []): Borrower
    {
        return Borrower::factory()->create(array_merge(['branch_id' => $this->branch->id], $attributes));
    }

    /**
     * A loan inserted straight in a released-or-later state, so hundreds of
     * them stay cheap. `$number` false generates an account number; null
     * leaves it unset.
     */
    private function releasedLoan(
        Borrower $borrower,
        string $releasedAt,
        float $principal,
        Branch $branch,
        string $status = 'ongoing',
        string|false|null $number = false,
    ): Loan {
        return Loan::factory()->create([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $this->product->id,
            'branch_id' => $branch->id,
            'created_by' => $this->admin->id,
            'principal_amount' => $principal,
            'net_proceeds' => $principal,
            'status' => $status,
            'released_at' => $releasedAt,
            'loan_account_number' => $number === false ? sprintf('LN-T%06d', ++$this->loanNumber) : $number,
        ]);
    }

    private function repayment(Loan $loan, string $date, float $interest, float $penalty, string $status = 'posted'): Repayment
    {
        return Repayment::factory()->create([
            'loan_id' => $loan->id,
            'payment_date' => $date,
            'amount_paid' => $interest + $penalty,
            'principal_applied' => 0,
            'interest_applied' => $interest,
            'penalty_applied' => $penalty,
            'status' => $status,
            'received_by' => $this->admin->id,
        ]);
    }
}
