<?php

namespace App\Services\CsvImport;

use App\Exceptions\MalformedSequenceCodeException;
use App\Models\Borrower;
use App\Models\Loan;
use App\Services\SequenceCode;
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
 *
 * WHY IT PARSES THE LAST CODE. Every increment goes through
 * App\Services\SequenceCode, which is the same parser both model hooks use.
 * That is not tidiness: an unparseable code — an old manual fix, a bad
 * migration — used to read as 0 under a bare `(int) substr()`, so the next
 * allocation restarted at 000001, which already exists. A whole chunk of
 * fifty would then fail on the unique index, and so would the next chunk, and
 * so would a teller's create. Sharing the parser means this class stops on the
 * bad row exactly where the hooks do, with the same exception naming it.
 */
class SequenceAllocator
{
    /**
     * @return list<string>
     *
     * @throws MalformedSequenceCodeException when the highest borrower carries
     *                                        an unparseable code
     */
    public function allocateBorrowerCodes(int $count): array
    {
        $this->assertInTransaction('borrower codes');

        $last = Borrower::query()->orderByDesc('id')->lockForUpdate()->first();

        $first = $last === null
            ? SequenceCode::first(Borrower::CODE_PREFIX)
            : SequenceCode::after(Borrower::CODE_PREFIX, $last->borrower_code, "borrowers.id {$last->id}");

        return $this->sequence(Borrower::CODE_PREFIX, $first, $count);
    }

    /**
     * @return list<string>
     *
     * @throws MalformedSequenceCodeException when the highest loan carries an
     *                                        unparseable application number
     */
    public function allocateApplicationNumbers(int $count): array
    {
        $this->assertInTransaction('application numbers');

        $last = Loan::query()->orderByDesc('id')->lockForUpdate()->first();

        $first = $last === null
            ? SequenceCode::first(Loan::CODE_PREFIX)
            : SequenceCode::after(Loan::CODE_PREFIX, $last->application_number, "loans.id {$last->id}");

        return $this->sequence(Loan::CODE_PREFIX, $first, $count);
    }

    /**
     * The chunk's codes, walked forward from the first one with SequenceCode
     * itself.
     *
     * Every step goes back through the same parser the model hooks use rather
     * than through local arithmetic, because the ONLY thing that makes these
     * values useful is that they are byte-identical to what the hooks will
     * issue. Formatting them a second way here is how a prediction quietly stops
     * being one.
     *
     * @return list<string>
     */
    private function sequence(string $prefix, string $first, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $codes = [$first];

        for ($offset = 1; $offset < $count; $offset++) {
            $codes[] = SequenceCode::after($prefix, $codes[$offset - 1], 'the code allocated immediately before it');
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
