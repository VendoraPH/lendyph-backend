<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The query contract GET /api/loans owes the loans list screen.
 *
 * That screen used to fetch the newest 15 loans and then compute its KPI cards,
 * tab counts, filters, sorting and pagination from that slice — so an operator
 * with 38 loans was shown a filtered view of 15, which is the same bug the
 * members list had. Everything here holds the server-side replacement in place:
 * multi-status filtering, the product and date filters, the whitelisted sort,
 * and tab counts that stay whole-book while the page narrows.
 */
class LoanListTest extends TestCase
{
    use SetupLendyPH;

    private ?Borrower $sharedBorrower = null;

    private ?LoanProduct $sharedProduct = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── status: single value, list, and the virtual `active` set ─────────

    public function test_status_accepts_a_comma_separated_list(): void
    {
        $released = $this->createLoan(['status' => 'released']);
        $ongoing = $this->createLoan(['status' => 'ongoing']);
        $this->createLoan(['status' => 'draft']);
        $this->createLoan(['status' => 'completed']);

        $response = $this->getJson('/api/loans?status=released,ongoing')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$released->id, $ongoing->id],
            array_column($response->json('data'), 'id'),
        );
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_a_status_the_enum_cannot_hold_matches_nothing_instead_of_erroring(): void
    {
        $released = $this->createLoan(['status' => 'released']);
        $this->createLoan(['status' => 'draft']);

        // `current` and `past_due` were never enum members and are no longer
        // offered. A client still sending one has to get an empty result, not a
        // 422 that takes the whole page down.
        $this->assertSame(0, $this->getJson('/api/loans?status=past_due')->assertOk()->json('meta.total'));

        $mixed = $this->getJson('/api/loans?status=released,current,past_due')->assertOk();

        $this->assertSame([$released->id], array_column($mixed->json('data'), 'id'));
    }

    public function test_status_still_accepts_a_single_value(): void
    {
        $released = $this->createLoan(['status' => 'released']);
        $this->createLoan(['status' => 'draft']);

        $response = $this->getJson('/api/loans?status=released')->assertOk();

        $this->assertSame([$released->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_status_active_is_shorthand_for_the_whole_active_set(): void
    {
        $this->createLoan(['status' => 'released']);
        $this->createLoan(['status' => 'ongoing']);
        $this->createLoan(['status' => 'draft']);
        $this->createLoan(['status' => 'defaulted']);

        $shorthand = $this->getJson('/api/loans?status=active')->assertOk();
        $spelledOut = $this->getJson('/api/loans?status=released,ongoing')->assertOk();
        // What a client pinned to the OLD active set still sends. It resolves
        // to the same rows only because the two retired values match nothing —
        // which is the argument for sending `active` and letting the server own
        // the set, rather than hardcoding a list that goes stale in silence.
        $stale = $this->getJson('/api/loans?status=released,current,ongoing,past_due')->assertOk();

        $expected = array_column($shorthand->json('data'), 'id');

        $this->assertSame($expected, array_column($spelledOut->json('data'), 'id'));
        $this->assertSame($expected, array_column($stale->json('data'), 'id'));

        // `defaulted` still owes money but is not an active loan, so the card
        // and the tab it opens must both leave it out.
        $this->assertSame(2, $shorthand->json('meta.total'));
        $this->assertSame(2, $shorthand->json('meta.stats.active'));
    }

    public function test_status_tolerates_spacing_and_an_empty_value(): void
    {
        $released = $this->createLoan(['status' => 'released']);
        $draft = $this->createLoan(['status' => 'draft']);

        $spaced = $this->getJson('/api/loans?status=released,%20draft')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$released->id, $draft->id],
            array_column($spaced->json('data'), 'id'),
        );

        // An empty tab value means "no status filter", not "match nothing".
        $this->assertSame(2, $this->getJson('/api/loans?status=')->assertOk()->json('meta.total'));
    }

    // ── loan_product_id ─────────────────────────────────────────────────

    public function test_filters_by_loan_product_id(): void
    {
        $agri = LoanProduct::factory()->create(['name' => 'Agri Loan']);
        $salary = LoanProduct::factory()->create(['name' => 'Salary Loan']);

        $agriLoan = $this->createLoan(['loan_product_id' => $agri->id]);
        $this->createLoan(['loan_product_id' => $salary->id]);

        $response = $this->getJson("/api/loans?loan_product_id={$agri->id}")->assertOk();

        $this->assertSame([$agriLoan->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($agri->id, $response->json('data.0.loan_product_id'));
    }

    // ── date_from / date_to ─────────────────────────────────────────────

    public function test_date_range_is_an_inclusive_whole_day_window_on_created_at(): void
    {
        $justBefore = $this->createLoan(['created_at' => '2026-05-31 23:59:59']);
        $firstMoment = $this->createLoan(['created_at' => '2026-06-01 00:00:00']);
        $lateOnTheLastDay = $this->createLoan(['created_at' => '2026-06-30 23:45:00']);
        $justAfter = $this->createLoan(['created_at' => '2026-07-01 00:00:01']);

        $response = $this->getJson('/api/loans?date_from=2026-06-01&date_to=2026-06-30&per_page=100')->assertOk();
        $ids = array_column($response->json('data'), 'id');

        // The whole of the 30th, not midnight on the 30th — a loan captured at
        // 23:45 is the reason date_to is an endOfDay comparison.
        $this->assertEqualsCanonicalizing([$firstMoment->id, $lateOnTheLastDay->id], $ids);
        $this->assertNotContains($justBefore->id, $ids);
        $this->assertNotContains($justAfter->id, $ids);
    }

    public function test_each_date_bound_works_on_its_own(): void
    {
        $old = $this->createLoan(['created_at' => '2026-01-15 10:00:00']);
        $recent = $this->createLoan(['created_at' => '2026-06-15 10:00:00']);

        $this->assertSame(
            [$recent->id],
            array_column($this->getJson('/api/loans?date_from=2026-06-01')->assertOk()->json('data'), 'id'),
        );
        $this->assertSame(
            [$old->id],
            array_column($this->getJson('/api/loans?date_to=2026-05-31')->assertOk()->json('data'), 'id'),
        );
    }

    public function test_rejects_a_malformed_date(): void
    {
        $this->getJson('/api/loans?date_from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_from');
    }

    // ── sort / dir ──────────────────────────────────────────────────────

    public function test_sorts_by_every_whitelisted_key_in_both_directions(): void
    {
        $loans = $this->createSortableLoans();
        $ascending = [$loans['A']->id, $loans['B']->id, $loans['C']->id, $loans['D']->id];

        // One test rather than fourteen because every case pays for its own
        // migrate:fresh; the message names the case that failed.
        foreach (['application_number', 'borrower', 'product', 'amount', 'term', 'status', 'created_at'] as $sort) {
            $this->assertSame(
                $ascending,
                $this->idsFor("?sort={$sort}&dir=asc"),
                "sort={$sort}&dir=asc",
            );

            $this->assertSame(
                array_reverse($ascending),
                $this->idsFor("?sort={$sort}&dir=desc"),
                "sort={$sort}&dir=desc",
            );
        }
    }

    public function test_status_sorts_in_tab_order_rather_than_alphabetically(): void
    {
        $this->createSortableLoans();
        $this->createLoan(['status' => 'ongoing']);

        // Alphabetically these are completed, defaulted, draft, ongoing,
        // released. The list orders them by the tab row the user is looking at
        // instead, with statuses that have no tab (here `defaulted`) last.
        //
        // `ongoing` sitting between released and completed is the Current tab's
        // position — that tab points at `ongoing` now. Unranked it would fall
        // in with `defaulted` at the bottom, putting every live, paying loan
        // below the finished ones.
        $this->assertSame(
            ['draft', 'released', 'ongoing', 'completed', 'defaulted'],
            array_column($this->getJson('/api/loans?sort=status&dir=asc')->assertOk()->json('data'), 'status'),
        );
    }

    public function test_defaults_to_newest_first_by_created_at(): void
    {
        $loans = $this->createSortableLoans();

        $this->assertSame(
            [$loans['D']->id, $loans['C']->id, $loans['B']->id, $loans['A']->id],
            $this->idsFor(''),
        );
    }

    public function test_rejects_a_sort_key_that_is_not_whitelisted(): void
    {
        $this->createLoan();

        // A real column, but not one the list offers: the value is matched
        // against a whitelist, never handed to the database.
        $this->getJson('/api/loans?sort=net_proceeds')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');

        $this->getJson('/api/loans?sort=loans.id; drop table loans')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');

        $this->getJson('/api/loans?dir=sideways')
            ->assertStatus(422)
            ->assertJsonValidationErrors('dir');

        // Still standing.
        $this->getJson('/api/loans')->assertOk();
    }

    public function test_empty_query_parameters_fall_back_to_the_defaults(): void
    {
        // A URLSearchParams built from "nothing selected" emits `sort=&dir=`
        // rather than omitting the keys. Those must read as defaults, not as a
        // 422 on an empty enum value.
        $loans = $this->createSortableLoans();

        $response = $this->getJson('/api/loans?sort=&dir=&status=&per_page=&loan_product_id=&date_from=&date_to=&search=')
            ->assertOk();

        $this->assertSame(15, $response->json('meta.per_page'));
        $this->assertSame(4, $response->json('meta.total'));
        $this->assertSame(
            [$loans['D']->id, $loans['C']->id, $loans['B']->id, $loans['A']->id],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_filters_combine_with_a_sort_that_joins(): void
    {
        // `borrowers` and `loan_products` both carry a `status`, and
        // `borrowers` a `branch_id` and `created_at` as well, so an unqualified
        // filter becomes an ambiguous-column SQL error the moment one of those
        // tables is joined for sorting.
        $loans = $this->createSortableLoans();
        $branchId = $this->branch->id;

        $byBorrower = $this->getJson(
            "/api/loans?sort=borrower&dir=asc&branch_id={$branchId}&status=draft,released&date_from=2026-01-01&search=LA-"
        )->assertOk();

        $this->assertSame([$loans['A']->id, $loans['B']->id], array_column($byBorrower->json('data'), 'id'));

        $byProduct = $this->getJson("/api/loans?sort=product&dir=desc&status=draft,released&branch_id={$branchId}")->assertOk();

        $this->assertSame([$loans['B']->id, $loans['A']->id], array_column($byProduct->json('data'), 'id'));
    }

    // ── pagination ──────────────────────────────────────────────────────

    public function test_per_page_clamps_at_100(): void
    {
        $this->createLoan();

        $this->assertSame(100, $this->getJson('/api/loans?per_page=9999')->assertOk()->json('meta.per_page'));
        $this->assertSame(15, $this->getJson('/api/loans')->assertOk()->json('meta.per_page'));
        $this->getJson('/api/loans?per_page=0')->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_paginates_server_side(): void
    {
        $this->createSortableLoans();

        $page2 = $this->getJson('/api/loans?sort=created_at&dir=asc&per_page=2&page=2')->assertOk();

        $this->assertCount(2, $page2->json('data'));
        $this->assertSame(4, $page2->json('meta.total'));
        $this->assertSame(2, $page2->json('meta.current_page'));
        $this->assertSame(2, $page2->json('meta.last_page'));
    }

    public function test_pages_never_repeat_a_loan_when_the_sort_values_tie(): void
    {
        // Five loans captured in the same second. With no tiebreak MySQL is
        // free to order them differently per page, which repeats one loan and
        // hides another as the user pages through.
        for ($i = 0; $i < 5; $i++) {
            $this->createLoan(['created_at' => '2026-03-01 09:00:00']);
        }

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $seen = array_merge($seen, $this->idsFor("?per_page=2&page={$page}"));
        }

        $this->assertCount(5, $seen);
        $this->assertSame($seen, array_unique($seen));
    }

    // ── cost ────────────────────────────────────────────────────────────

    public function test_the_list_costs_a_fixed_number_of_queries_however_many_rows_it_returns(): void
    {
        // The eager loads and the aliased extension_count are the difference
        // between one query per page and one COUNT plus one borrower lookup per
        // ROW. Selecting `loans.*` explicitly — which the sort joins need — must
        // not knock the extension_count subselect off the select list and put
        // that per-row COUNT back.
        for ($i = 0; $i < 3; $i++) {
            $this->createLoan();
        }

        // Warm-up: the first authorized request of a test also resolves the
        // permission tables, which Spatie then keeps in memory. Measuring it
        // would compare a cold request against a warm one.
        $this->getJson('/api/loans')->assertOk();

        $small = $this->countQueriesForOnePage();

        for ($i = 0; $i < 12; $i++) {
            $this->createLoan();
        }

        $this->assertSame(15, $this->getJson('/api/loans')->assertOk()->json('meta.total'));
        $this->assertSame($small, $this->countQueriesForOnePage(), 'the loans list is doing per-row work');
    }

    private function countQueriesForOnePage(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/loans?sort=borrower&dir=asc&per_page=100')->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    }

    // ── meta.stats ──────────────────────────────────────────────────────

    public function test_meta_stats_cover_every_tab_on_the_loans_screen(): void
    {
        $this->createLoan(['status' => 'released']);
        $this->createLoan(['status' => 'released']);
        $this->createLoan(['status' => 'ongoing']);
        $this->createLoan(['status' => 'draft']);
        $this->createLoan(['status' => 'completed']);

        $stats = $this->getJson('/api/loans')->assertOk()->json('meta.stats');

        // Every tab on the loans screen has a count, so none of them can read
        // as an undefined badge once the frontend stops counting rows itself.
        // The Current tab reads `ongoing`.
        $this->assertSame([], array_diff(
            ['draft', 'for_review', 'approved', 'rejected', 'released', 'ongoing', 'completed', 'active'],
            array_keys($stats),
        ));

        // And nothing reports a status the `loans.status` enum cannot hold. A
        // key that is structurally always 0 is an invitation to add the enum
        // member; past due is an `ongoing` loan with an overdue schedule, so it
        // needs a schedule-derived filter rather than a status.
        $this->assertSame([], array_intersect(['current', 'past_due'], array_keys($stats)));

        $this->assertSame(2, $stats['released']);
        $this->assertSame(1, $stats['ongoing']);
        $this->assertSame(1, $stats['draft']);
        $this->assertSame(1, $stats['completed']);
        // 2 released + 1 ongoing.
        $this->assertSame(3, $stats['active']);
    }

    public function test_stats_stay_global_while_the_filters_narrow_the_page(): void
    {
        $product = LoanProduct::factory()->create(['name' => 'Agri Loan']);

        $this->createLoan(['status' => 'draft', 'created_at' => '2026-01-05 09:00:00']);
        $this->createLoan(['status' => 'draft', 'created_at' => '2026-01-06 09:00:00']);
        $this->createLoan(['status' => 'draft', 'created_at' => '2026-01-07 09:00:00']);
        $this->createLoan(['status' => 'released', 'created_at' => '2026-06-01 09:00:00', 'loan_product_id' => $product->id]);
        $this->createLoan(['status' => 'released', 'created_at' => '2026-06-02 09:00:00']);

        $response = $this->getJson(
            "/api/loans?status=released&loan_product_id={$product->id}&date_from=2026-06-01&date_to=2026-06-01&search=LA-"
        )->assertOk();

        // The page is one row...
        $this->assertSame(1, $response->json('meta.total'));

        // ...and the tabs still describe the whole book.
        $this->assertSame(3, $response->json('meta.stats.draft'));
        $this->assertSame(2, $response->json('meta.stats.released'));
        $this->assertSame(2, $response->json('meta.stats.active'));
    }

    public function test_stats_follow_branch_and_borrower_scope(): void
    {
        $mine = $this->createLoan(['status' => 'released']);
        $this->createLoan([
            'status' => 'released',
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
        ]);

        $response = $this->getJson("/api/loans?borrower_id={$mine->borrower_id}")->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.stats.released'));
        $this->assertSame(1, $response->json('meta.stats.active'));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * @return array<int, int>
     */
    private function idsFor(string $query): array
    {
        return array_column($this->getJson('/api/loans'.$query)->assertOk()->json('data'), 'id');
    }

    /**
     * Four loans that hold the SAME relative order under every sortable key, so
     * an expected result can be written once as A, B, C, D.
     *
     * The statuses are picked to tell tab order apart from alphabetical order:
     * A-Z would be completed, defaulted, draft, released — a different sequence
     * — and `defaulted` has no tab, so it must land last.
     *
     * @return array<string, Loan>
     */
    private function createSortableLoans(): array
    {
        $rows = [
            'A' => ['Alvarez', 'Ana', 'Agri Loan', 10000, 3, 'draft', '2026-01-01 09:00:00'],
            'B' => ['Bautista', 'Ben', 'Business Loan', 20000, 6, 'released', '2026-01-02 09:00:00'],
            'C' => ['Cruz', 'Cara', 'Calamity Loan', 30000, 9, 'completed', '2026-01-03 09:00:00'],
            'D' => ['Dizon', 'Dan', 'Development Loan', 40000, 12, 'defaulted', '2026-01-04 09:00:00'],
        ];

        $loans = [];

        foreach ($rows as $label => [$lastName, $firstName, $productName, $amount, $term, $status, $createdAt]) {
            $borrower = Borrower::factory()->create([
                'branch_id' => $this->branch->id,
                'last_name' => $lastName,
                'first_name' => $firstName,
            ]);

            $loans[$label] = $this->createLoan([
                'borrower_id' => $borrower->id,
                'loan_product_id' => LoanProduct::factory()->create(['name' => $productName])->id,
                'principal_amount' => $amount,
                'term' => $term,
                'status' => $status,
                'created_at' => $createdAt,
            ]);
        }

        return $loans;
    }

    private function createLoan(array $attributes = []): Loan
    {
        $this->sharedBorrower ??= Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->sharedProduct ??= LoanProduct::factory()->create();

        return Loan::factory()->create(array_merge([
            'borrower_id' => $this->sharedBorrower->id,
            'loan_product_id' => $this->sharedProduct->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ], $attributes));
    }
}
