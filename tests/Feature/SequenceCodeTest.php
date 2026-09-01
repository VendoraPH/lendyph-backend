<?php

namespace Tests\Feature;

use App\Exceptions\MalformedSequenceCodeException;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\CsvImport\SequenceAllocator;
use App\Services\SequenceCode;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `borrower_code` and `application_number` are allocated as "the highest
 * existing row's number, plus one".
 *
 * Read that number with a bare `(int) substr()` — which all three call sites
 * used to do — and a code the parser cannot read evaluates to 0, so the next
 * allocation is 000001. That code already exists. The unique index rejects it,
 * and it rejects the SAME code on every attempt after that: the sequence never
 * advances again, for anybody. Not just the importer — every teller creating a
 * borrower or a loan fails too, permanently, until somebody finds the one bad
 * row.
 *
 * These tests exist because that failure mode is unbounded and silent at the
 * point it is caused, and bounded and obvious if it stops on the first
 * allocation instead.
 */
class SequenceCodeTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Put a value into `borrower_code` that the model hook would refuse to
     * write, which is exactly how these get there: not through the hook.
     */
    private function corruptHighestBorrowerCode(string $code): Borrower
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        DB::table('borrowers')->where('id', $borrower->id)->update(['borrower_code' => $code]);

        return $borrower->refresh();
    }

    /**
     * Shapes seen in the wild, or one keystroke away from them. `''` stands in
     * for a lost code: the column is NOT NULL, so an emptied one is as close as
     * the database allows, and it must not be read as "no rows yet, start at
     * 1" — the rows below it already hold 000001 upwards.
     *
     * @return array<string, array{string}>
     */
    public static function malformedCodes(): array
    {
        return [
            'truncated to the prefix' => ['BRW-'],
            'a letter among the digits' => ['BRW-00A1'],
            'no prefix at all' => ['000042'],
            'a different family' => ['LA-000042'],
            'trailing text' => ['BRW-000042-OLD'],
            'emptied' => [''],
        ];
    }

    #[DataProvider('malformedCodes')]
    public function test_a_malformed_borrower_code_stops_the_next_create_instead_of_restarting_the_sequence(string $code): void
    {
        $this->seedAndLogin();

        $bad = $this->corruptHighestBorrowerCode($code);
        $before = Borrower::count();

        try {
            Borrower::factory()->create(['branch_id' => $this->branch->id]);
            $this->fail("[{$code}] was accepted. The next code would restart at BRW-000001 and collide forever.");
        } catch (MalformedSequenceCodeException $e) {
            // Named, and it names the row — the whole value of failing here
            // rather than on the unique index is that the message says which
            // row to fix.
            $this->assertStringContainsString("borrowers.id {$bad->id}", $e->getMessage());
            $this->assertSame('BRW', $e->prefix);
            $this->assertSame($code, $e->sequenceCode);
        }

        $this->assertSame($before, Borrower::count(), 'Nothing may be written when the sequence cannot be read.');
    }

    public function test_a_malformed_application_number_stops_the_next_loan_create(): void
    {
        $this->seedAndLogin();

        $loan = $this->createReleasedLoan();
        DB::table('loans')->where('id', $loan->id)->update(['application_number' => 'LA-00X1']);

        $product = LoanProduct::first();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        try {
            Loan::create([
                'borrower_id' => $borrower->id,
                'loan_product_id' => $product->id,
                'branch_id' => $this->branch->id,
                'principal_amount' => 10000,
                'interest_rate' => 3.0,
                'term' => 6,
                'status' => 'draft',
            ]);
            $this->fail('A malformed application_number was accepted.');
        } catch (MalformedSequenceCodeException $e) {
            $this->assertStringContainsString("loans.id {$loan->id}", $e->getMessage());
            $this->assertSame('LA', $e->prefix);
        }

        $this->assertSame(1, Loan::count());
    }

    /**
     * A null code is the same fault, not a fresh start.
     *
     * The column is NOT NULL so the database cannot produce one, but the
     * signature can — and "no code" is tempting to read as "no rows yet, begin
     * at 1", which is precisely the collision. Only an EMPTY TABLE starts the
     * sequence, and that is a different call.
     */
    public function test_a_null_code_is_refused_rather_than_treated_as_an_empty_table(): void
    {
        $this->expectException(MalformedSequenceCodeException::class);

        SequenceCode::after('BRW', null, 'borrowers.id 812');
    }

    /**
     * The importer amplifies the same fault: fifty rows per chunk, every one of
     * them failing on a code the allocator could not read.
     *
     * It has to stop in the same place the hooks do — a prediction that
     * disagrees with what the hooks issue is a unique-key violation on an
     * otherwise ordinary row, which is far harder to diagnose than this.
     */
    public function test_the_importers_allocator_stops_in_the_same_place_the_hooks_do(): void
    {
        $this->seedAndLogin();

        $bad = $this->corruptHighestBorrowerCode('BRW-oops');

        $this->expectException(MalformedSequenceCodeException::class);
        $this->expectExceptionMessageMatches("/borrowers\.id {$bad->id}/");

        DB::transaction(fn () => (new SequenceAllocator)->allocateBorrowerCodes(50));
    }

    public function test_a_well_formed_code_still_increments_and_an_empty_table_starts_at_one(): void
    {
        $this->assertSame('BRW-000001', SequenceCode::first('BRW'));
        $this->assertSame('BRW-000043', SequenceCode::after('BRW', 'BRW-000042', 'borrowers.id 1'));
        $this->assertSame('LA-000100', SequenceCode::after('LA', 'LA-000099', 'loans.id 1'));

        // Six digits is a minimum width, not a ceiling — a coop passing a
        // million members widens the number rather than wrapping it.
        $this->assertSame('BRW-1000000', SequenceCode::after('BRW', 'BRW-999999', 'borrowers.id 1'));
    }
}
