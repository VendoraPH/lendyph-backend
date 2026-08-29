<?php

namespace App\Services\CsvImport;

use App\Models\Borrower;
use App\Models\Loan;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hands out the `borrower_code` and `application_number` values a chunk of
 * imported rows will take, under the same row lock the models themselves use.
 *
 * WHY NOT JUST RESERVE A BLOCK. The obvious design — read the highest code,
 * claim the next 200, release the lock, insert at leisure — is unsafe here, and
 * quietly so. Borrower::booted() and Loan::booted() both issue a code by
 * reading `orderByDesc('id')->lockForUpdate()->first()` and adding one. A
 * teller creating a borrower in the middle of the importer's reserved block
 * reads the importer's own highest INSERTED id, not its reservation, and is
 * handed a code from inside the reserved range. The collision surfaces later,
 * as a unique-key violation on a row that looks perfectly ordinary.
 *
 * So: take the lock, read the maximum, build every code for the chunk, and
 * return them — all inside the CALLER'S transaction, which is what keeps the
 * lock held until the chunk's rows are actually committed. A concurrent
 * create blocks on that lock instead of racing it.
 *
 * WHAT THIS DOES NOT DO. It does not bypass the model hooks, and must not:
 * Borrower::booted()'s `created` hook also writes the share capital pledge row
 * that every borrower is required to have. The hooks overwrite `borrower_code`
 * and `application_number` unconditionally on create — and because they
 * compute the next value exactly as this class does, from inside the same
 * locked transaction, they arrive at the SAME values in the same order. The
 * codes returned here are therefore a prediction the caller can print, report
 * and reference, valid as long as the caller creates the models inside this
 * transaction, in the order the codes were handed out.
 */
class SequenceAllocator
{
    private const BORROWER_PREFIX = 'BRW-';

    private const LOAN_PREFIX = 'LA-';

    private const PAD_LENGTH = 6;

    /**
     * @return list<string>
     */
    public function allocateBorrowerCodes(int $count): array
    {
        $this->assertInTransaction('borrower codes');

        $last = Borrower::query()->orderByDesc('id')->lockForUpdate()->first();
        $next = $last === null ? 1 : (int) substr((string) $last->borrower_code, strlen(self::BORROWER_PREFIX)) + 1;

        return $this->sequence(self::BORROWER_PREFIX, $next, $count);
    }

    /**
     * @return list<string>
     */
    public function allocateApplicationNumbers(int $count): array
    {
        $this->assertInTransaction('application numbers');

        $last = Loan::query()->orderByDesc('id')->lockForUpdate()->first();
        $next = $last === null ? 1 : (int) substr((string) $last->application_number, strlen(self::LOAN_PREFIX)) + 1;

        return $this->sequence(self::LOAN_PREFIX, $next, $count);
    }

    /**
     * @return list<string>
     */
    private function sequence(string $prefix, int $start, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $codes = [];

        for ($offset = 0; $offset < $count; $offset++) {
            $codes[] = $prefix.str_pad((string) ($start + $offset), self::PAD_LENGTH, '0', STR_PAD_LEFT);
        }

        return $codes;
    }

    /**
     * lockForUpdate() outside a transaction is not an error — it is a no-op.
     *
     * The lock is released the instant the implicit single-statement
     * transaction commits, so the allocator would appear to work, return
     * plausible codes, and provide no protection whatsoever. Failing loudly is
     * the only way that mistake is ever noticed.
     */
    private function assertInTransaction(string $what): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                "SequenceAllocator must be called inside a transaction — allocating {$what} outside one takes a "
                .'row lock that is released immediately, so a concurrent create would be issued a code from inside '
                .'the same range.'
            );
        }
    }
}
