<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * What Payment Entry needs from GET /loans now that it pages and searches on
 * the server: a search that finds what its browser filter found (a whole name,
 * a loan product), and `sort=next_due`, which puts overdue loans first.
 */
class LoanListSearchAndNextDueTest extends TestCase
{
    use SetupLendyPH;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->product = LoanProduct::factory()->create(['name' => 'Salary Advance Plus']);
    }

    // ── search ───────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function namesOfJuan(): array
    {
        return [
            'first and last' => ['Juan Cruz'],
            'whole name' => ['Juan Dela Cruz'],
            'with suffix' => ['Juan Dela Cruz Jr.'],
            'extra spaces' => ['  Juan   Dela  Cruz '],
            'other case' => ['juan dela cruz'],
            'one part' => ['Dela'],
        ];
    }

    #[DataProvider('namesOfJuan')]
    public function test_search_finds_a_member_by_the_name_as_typed(string $term): void
    {
        $juan = $this->loan(['borrower_id' => $this->juan()->id]);
        $this->loan();

        foreach (['', '&view=summary'] as $view) {
            $this->assertSame([$juan->id], $this->idsFor('search='.urlencode($term).$view), "search for [{$term}]{$view}");
        }
    }

    public function test_search_finds_a_loan_by_its_product_name(): void
    {
        $found = $this->loan(['loan_product_id' => $this->product->id]);
        $this->loan(['loan_product_id' => LoanProduct::factory()->create(['name' => 'Emergency'])->id]);

        $this->assertSame([$found->id], $this->idsFor('search='.urlencode('Advance Plus')));
    }

    public function test_search_still_finds_numbers_and_codes(): void
    {
        $borrower = $this->juan();
        $loan = $this->loan(['borrower_id' => $borrower->id, 'loan_account_number' => 'LN-777001']);
        $this->loan();

        $this->assertSame([$loan->id], $this->idsFor('search='.urlencode($loan->fresh()->application_number)));
        $this->assertSame([$loan->id], $this->idsFor('search=LN-777001'));
        $this->assertSame([$loan->id], $this->idsFor('search='.urlencode($borrower->borrower_code)));
    }

    public function test_a_percent_or_underscore_in_the_search_matches_literally(): void
    {
        $this->loan(['borrower_id' => $this->juan()->id]);

        $this->assertSame([], $this->idsFor('search='.urlencode('Juan%Cruz')));
        $this->assertSame([], $this->idsFor('search='.urlencode('Ju_n')));
    }

    public function test_totals_follow_the_search(): void
    {
        $juan = $this->loan(['borrower_id' => $this->juan()->id, 'status' => 'ongoing']);
        $other = $this->loan(['status' => 'ongoing']);
        $this->period($juan, today()->addDays(5));
        $this->period($other, today()->addDays(5));

        $totals = $this->getJson('/api/loans?search='.urlencode('Juan Dela Cruz'))->assertOk()->json('meta.totals');

        $this->assertEquals(9583.33, $totals['total_outstanding']);
    }

    // ── sort=next_due ────────────────────────────────────────────────────

    public function test_next_due_puts_the_earliest_unpaid_date_first_and_no_date_last(): void
    {
        $paidEarlier = $this->loan();
        $this->period($paidEarlier, today()->subDays(90), ['status' => 'paid']);
        $this->period($paidEarlier, today()->addDays(10), [], 2);

        $overdue = $this->loan();
        $this->period($overdue, today()->subDays(30), ['status' => 'overdue']);
        $this->period($overdue, today()->addDays(1), [], 2);

        $soon = $this->loan();
        $this->period($soon, today()->addDays(2), ['status' => 'partial']);

        $settled = $this->loan();
        $this->period($settled, today()->subDays(60), ['status' => 'paid']);

        $noPeriods = $this->loan();

        $expected = [$overdue->id, $soon->id, $paidEarlier->id];

        $ids = $this->idsFor('sort=next_due&dir=asc');
        $this->assertSame($expected, array_slice($ids, 0, 3));
        $this->assertEqualsCanonicalizing([$settled->id, $noPeriods->id], array_slice($ids, 3));

        $this->assertSame($expected, array_slice($this->idsFor('sort=next_due&dir=asc&view=summary'), 0, 3));
    }

    public function test_next_due_is_ascending_when_no_direction_is_sent(): void
    {
        $later = $this->loan();
        $this->period($later, today()->addDays(10));
        $earlier = $this->loan();
        $this->period($earlier, today()->addDays(1));

        $this->assertSame([$earlier->id, $later->id], $this->idsFor('sort=next_due'));
    }

    public function test_descending_next_due_still_keeps_no_date_last(): void
    {
        $later = $this->loan();
        $this->period($later, today()->addDays(10));
        $earlier = $this->loan();
        $this->period($earlier, today()->addDays(1));
        $noPeriods = $this->loan();

        $this->assertSame([$later->id, $earlier->id, $noPeriods->id], $this->idsFor('sort=next_due&dir=desc'));
    }

    public function test_next_due_agrees_with_each_rows_next_due_date(): void
    {
        foreach ([40, -3, 12, -20, 5] as $days) {
            $this->period($this->loan(), today()->addDays($days));
        }

        $dates = array_column($this->getJson('/api/loans?sort=next_due&view=summary')->assertOk()->json('data'), 'next_due_date');
        $sorted = $dates;
        sort($sorted);

        $this->assertCount(5, $dates);
        $this->assertSame($sorted, $dates);
    }

    public function test_paging_through_equal_dates_repeats_and_skips_nothing(): void
    {
        $due = today()->addDays(3);

        foreach (range(1, 5) as $ignored) {
            $this->period($this->loan(), $due);
        }

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $seen = array_merge($seen, $this->idsFor("sort=next_due&per_page=2&page={$page}"));
        }

        $this->assertCount(5, $seen);
        $this->assertSame($seen, array_unique($seen));
    }

    public function test_the_row_carries_no_trace_of_the_sort_and_the_count_is_whole(): void
    {
        $this->period($this->loan(), today()->addDays(3));
        $this->loan();

        $response = $this->getJson('/api/loans?sort=next_due&per_page=1&view=summary')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertArrayNotHasKey('next_unpaid_due_date', $response->json('data.0'));
    }

    public function test_next_due_costs_a_fixed_number_of_queries_however_many_rows_it_returns(): void
    {
        $this->period($this->loan(), today()->addDays(3));

        $this->getJson('/api/loans?sort=next_due&view=summary')->assertOk();
        $small = $this->queriesForAPage();

        foreach (range(1, 8) as $days) {
            $this->period($this->loan(), today()->addDays($days));
        }

        $this->assertSame($small, $this->queriesForAPage(), 'sort=next_due is doing per-row work');
    }

    private function queriesForAPage(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/loans?sort=next_due&view=summary&per_page=100')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array<int, int>
     */
    private function idsFor(string $query): array
    {
        return array_column($this->getJson('/api/loans?per_page=100&'.$query)->assertOk()->json('data'), 'id');
    }

    private function juan(): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'middle_name' => 'Dela',
            'last_name' => 'Cruz',
            'suffix' => 'Jr.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function loan(array $attributes = []): Loan
    {
        return Loan::factory()->create(array_merge([
            'status' => 'ongoing',
            'borrower_id' => Borrower::factory()->create([
                'branch_id' => $this->branch->id,
                'first_name' => 'Pedro',
                'middle_name' => null,
                'last_name' => 'Santos',
                'suffix' => null,
            ])->id,
            'loan_product_id' => LoanProduct::factory()->create(['name' => 'Regular'])->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function period(Loan $loan, mixed $dueDate, array $attributes = [], int $number = 1): AmortizationSchedule
    {
        return AmortizationSchedule::factory()->create(array_merge([
            'loan_id' => $loan->id,
            'period_number' => $number,
            'due_date' => $dueDate,
        ], $attributes));
    }
}
