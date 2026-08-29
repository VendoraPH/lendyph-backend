<?php

namespace Tests\Feature\CsvImport;

use App\Models\Borrower;
use App\Models\Loan;
use App\Services\CsvImport\SequenceAllocator;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class SequenceAllocatorTest extends TestCase
{
    use SetupLendyPH;

    public function test_allocating_outside_a_transaction_fails_loudly(): void
    {
        // lockForUpdate() outside a transaction is not an error, it is a no-op:
        // the lock is released the moment the implicit single-statement
        // transaction commits. The allocator would return plausible codes and
        // protect nothing at all, which is the sort of bug that is only found
        // by the unique-key violation it eventually causes.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/inside a transaction/');

        (new SequenceAllocator)->allocateBorrowerCodes(5);
    }

    public function test_it_predicts_exactly_the_codes_the_model_hooks_go_on_to_issue(): void
    {
        $this->seedAndLogin();

        $allocator = new SequenceAllocator;

        [$allocated, $created] = DB::transaction(function () use ($allocator): array {
            // The lock is taken here and held until this transaction commits,
            // which is what stops a teller's Borrower::create() landing inside
            // the range: it blocks rather than racing.
            $allocated = $allocator->allocateBorrowerCodes(3);

            $created = collect(range(1, 3))
                ->map(fn (int $i) => Borrower::factory()->create([
                    'branch_id' => $this->branch->id,
                    'first_name' => "Importee{$i}",
                ])->borrower_code)
                ->all();

            return [$allocated, $created];
        });

        $this->assertCount(3, $allocated);
        $this->assertMatchesRegularExpression('/^BRW-\d{6}$/', $allocated[0]);

        // The hooks overwrite borrower_code unconditionally, so the allocator's
        // value is a PREDICTION rather than an assignment. It is only a useful
        // prediction if it is exactly right — which it is, because the hook
        // computes the next code the same way, from inside this same locked
        // transaction, in this same order.
        $this->assertSame($allocated, $created);

        // Consecutive, with no gaps for a concurrent create to fall into.
        $numbers = array_map(fn (string $code): int => (int) substr($code, 4), $allocated);
        $this->assertSame([$numbers[0], $numbers[0] + 1, $numbers[0] + 2], $numbers);
    }

    public function test_application_numbers_continue_from_the_highest_existing_loan(): void
    {
        $this->seedAndLogin();

        $existing = $this->createReleasedLoan();
        $expectedNext = (int) substr((string) $existing->application_number, 3) + 1;

        $allocated = DB::transaction(fn (): array => (new SequenceAllocator)->allocateApplicationNumbers(2));

        $this->assertSame([
            'LA-'.str_pad((string) $expectedNext, 6, '0', STR_PAD_LEFT),
            'LA-'.str_pad((string) ($expectedNext + 1), 6, '0', STR_PAD_LEFT),
        ], $allocated);

        // And the hook agrees, which is the only thing that matters.
        $next = DB::transaction(fn () => $this->createReleasedLoan()->application_number);
        $this->assertSame($allocated[0], $next);

        $this->assertSame(2, Loan::query()->count());
    }

    public function test_allocating_nothing_returns_nothing(): void
    {
        $this->seedAndLogin();

        $this->assertSame([], DB::transaction(fn (): array => (new SequenceAllocator)->allocateBorrowerCodes(0)));
    }
}
