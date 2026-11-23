<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\CsvImport\CsvImportProcessor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;

/**
 * Phase 6 MB-BE4: the CSV loan import copies the member's branch onto the
 * loan, so it reads the member with a locking read inside its chunk's
 * transaction. A transfer either waits for the import and then sees the loan,
 * or the import waits for the transfer and copies the new branch.
 */
class CsvImportMemberLockTest extends TestCase
{
    use BuildsCsvImports;

    public function test_the_loan_import_reads_the_member_with_a_locking_read_before_it_writes_the_loan(): void
    {
        $this->seedForImport();
        $product = LoanProduct::factory()->create([
            'name' => 'Salary Loan', 'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly',
            'penalty_rate' => 2.0, 'grace_period_days' => 3, 'min_amount' => 1000, 'max_amount' => 1000000,
        ]);
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);
        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-0001')]);

        $lockedAt = null;
        $insertedAt = null;
        $index = 0;

        DB::listen(function (QueryExecuted $query) use (&$lockedAt, &$insertedAt, &$index): void {
            $sql = strtolower($query->sql);
            $index++;

            if ($lockedAt === null && str_contains($sql, 'from `borrowers`') && str_contains($sql, '`external_account_no`') && str_contains($sql, 'lock in share mode')) {
                $lockedAt = $index;
            }

            if ($insertedAt === null && str_starts_with($sql, 'insert into `loans`')) {
                $insertedAt = $index;
            }
        });

        $this->runToIdle($run);

        $this->assertNotNull(Loan::query()->where('external_loan_no', 'L-0001')->first(), 'the loan was never imported');
        $this->assertNotNull($lockedAt, "The member wasn't read with a locking read.");
        $this->assertLessThan($insertedAt, $lockedAt, 'The member was locked only after the loan was written.');
    }

    private function runToIdle(CsvImportRun $run, int $maxTicks = 200): void
    {
        $processor = new CsvImportProcessor;

        for ($i = 0; $i < $maxTicks; $i++) {
            if ($processor->advance($run)->idle) {
                return;
            }

            $run->refresh();
        }

        $this->fail("The run did not settle within {$maxTicks} ticks.");
    }
}
