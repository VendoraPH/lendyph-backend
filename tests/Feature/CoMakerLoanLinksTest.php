<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Which co-makers are on which loans, from both ends, for the borrower page.
 *
 * Three widgets there read a co-maker's `loan_id`, which the API has never
 * sent: the Co-makers tab's loan badge never showed, the Loans tab's per-loan
 * co-maker list was always empty, and the Overview tab flagged every open loan
 * of ₱50,000 or more as missing a co-maker. The link is many-to-many
 * (`co_maker_loan`), so there is no single `loan_id` to send. Instead:
 *
 * - GET /api/loans carries each loan's `co_makers`, as GET /api/loans/{id}
 *   already did;
 * - GET /api/borrowers/{id}/co-makers carries each co-maker's `loans`.
 *
 * Both are eager-loaded, and both lists are held to a fixed query count.
 */
class CoMakerLoanLinksTest extends TestCase
{
    use SetupLendyPH;

    private ?LoanProduct $product = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function borrower(): Borrower
    {
        return Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function loanFor(Borrower $borrower, array $attributes = []): Loan
    {
        $this->product ??= LoanProduct::factory()->create();

        return Loan::factory()->create([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
            ...$attributes,
        ]);
    }

    private function coMakerOf(Borrower $borrower): CoMaker
    {
        return CoMaker::factory()->create(['borrower_id' => $borrower->id]);
    }

    private function link(Loan $loan, CoMaker $coMaker): void
    {
        $loan->coMakers()->attach($coMaker->id, ['added_by' => $this->admin->id]);
    }

    private function countQueries(string $uri): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($uri)->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    }

    // ---------------------------------------------------------------------
    // GET /api/loans — each loan's co_makers
    // ---------------------------------------------------------------------

    public function test_each_loan_in_the_list_carries_its_co_makers(): void
    {
        $borrower = $this->borrower();
        $withTwo = $this->loanFor($borrower, ['principal_amount' => 80000, 'status' => 'ongoing']);
        $withNone = $this->loanFor($borrower, ['principal_amount' => 60000, 'status' => 'ongoing']);
        $first = $this->coMakerOf($borrower);
        $second = $this->coMakerOf($borrower);
        $this->link($withTwo, $first);
        $this->link($withTwo, $second);

        $rows = collect($this->getJson("/api/loans?borrower_id={$borrower->id}")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame([], $rows[$withNone->id]['co_makers']);

        $coMakers = collect($rows[$withTwo->id]['co_makers'])->sortBy('id')->values();
        $this->assertSame([$first->id, $second->id], $coMakers->pluck('id')->all());
        $this->assertSame($first->full_name, $coMakers[0]['full_name']);
        $this->assertSame($borrower->id, $coMakers[0]['borrower_id']);
        // The same entry GET /api/loans/{id} returns, link metadata included —
        // and not the other direction, which only the co-maker list carries.
        $this->assertSame($this->admin->id, $coMakers[0]['added_by']);
        $this->assertNotNull($coMakers[0]['added_at']);
        $this->assertArrayNotHasKey('loans', $coMakers[0]);

        $shown = collect($this->getJson("/api/loans/{$withTwo->id}")->assertOk()->json('data.co_makers'))
            ->sortBy('id')->values()->all();
        $this->assertSame($shown, $coMakers->all());
    }

    public function test_the_loans_list_costs_a_fixed_number_of_queries_however_many_co_makers_it_returns(): void
    {
        $borrower = $this->borrower();
        $this->link($this->loanFor($borrower), $this->coMakerOf($borrower));

        // Warm-up: the first authorized request also resolves the permission
        // tables, which Spatie then keeps in memory.
        $this->getJson('/api/loans')->assertOk();

        $uri = '/api/loans?sort=borrower&dir=asc&per_page=100';
        $small = $this->countQueries($uri);

        for ($i = 0; $i < 8; $i++) {
            $loan = $this->loanFor($this->borrower());
            $this->link($loan, $this->coMakerOf($loan->borrower));
            $this->link($loan, $this->coMakerOf($loan->borrower));
        }

        $this->assertSame(9, $this->getJson($uri)->assertOk()->json('meta.total'));
        $this->assertSame($small, $this->countQueries($uri), 'the loans list is loading co-makers per row');
    }

    // ---------------------------------------------------------------------
    // GET /api/borrowers/{id}/co-makers — each co-maker's loans
    // ---------------------------------------------------------------------

    public function test_each_co_maker_lists_the_loans_it_is_on_in_id_order(): void
    {
        $borrower = $this->borrower();
        $released = $this->loanFor($borrower, [
            'status' => 'released',
            'loan_account_number' => 'LN-000042',
        ]);
        $approved = $this->loanFor($borrower, ['status' => 'approved']);
        $onBoth = $this->coMakerOf($borrower);
        $onNone = $this->coMakerOf($borrower);

        // Linked newest-first, so a list that followed link order rather than
        // loan id would come back reversed.
        $this->link($approved, $onBoth);
        $this->link($released, $onBoth);

        $coMakers = collect($this->getJson("/api/borrowers/{$borrower->id}/co-makers")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame([
            [
                'id' => $released->id,
                'application_number' => $released->application_number,
                'loan_account_number' => 'LN-000042',
                'status' => 'released',
            ],
            [
                'id' => $approved->id,
                'application_number' => $approved->application_number,
                'loan_account_number' => null,
                'status' => 'approved',
            ],
        ], $coMakers[$onBoth->id]['loans']);
        $this->assertSame([], $coMakers[$onNone->id]['loans']);

        // Read from the borrower's side there is no one loan, so no link
        // metadata either.
        $this->assertArrayNotHasKey('added_by', $coMakers[$onBoth->id]);
        $this->assertArrayNotHasKey('added_at', $coMakers[$onBoth->id]);
    }

    public function test_loans_appear_on_the_borrower_co_maker_list_only(): void
    {
        $borrower = $this->borrower();
        $loan = $this->loanFor($borrower);
        $coMaker = $this->coMakerOf($borrower);
        $this->link($loan, $coMaker);

        $this->getJson("/api/co-makers/{$coMaker->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.loans');

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.co_makers.0.id', $coMaker->id)
            ->assertJsonMissingPath('data.co_makers.0.loans');

        $this->getJson("/api/borrowers/{$borrower->id}")
            ->assertOk()
            ->assertJsonPath('data.co_makers.0.id', $coMaker->id)
            ->assertJsonMissingPath('data.co_makers.0.loans');
    }

    public function test_the_co_maker_list_costs_a_fixed_number_of_queries_however_many_loans_it_returns(): void
    {
        $borrower = $this->borrower();
        $this->link($this->loanFor($borrower), $this->coMakerOf($borrower));

        $uri = "/api/borrowers/{$borrower->id}/co-makers";
        $this->getJson($uri)->assertOk();

        $small = $this->countQueries($uri);

        for ($i = 0; $i < 5; $i++) {
            $coMaker = $this->coMakerOf($borrower);

            for ($j = 0; $j < 3; $j++) {
                $this->link($this->loanFor($borrower), $coMaker);
            }
        }

        $this->assertCount(6, $this->getJson($uri)->assertOk()->json('data'));
        $this->assertSame($small, $this->countQueries($uri), 'the co-maker list is loading loans per co-maker');
    }
}
