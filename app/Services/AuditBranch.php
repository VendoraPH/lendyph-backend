<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\InterbranchTransfer;
use App\Models\SavingsTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The branch an audit row records (Phase 6 MB-BE6, multi-branch decision 12).
 *
 * One rule for every new row, in this order:
 *   1. the audited record's own `branch_id`; a branch is its own branch, an
 *      interbranch transfer records its source branch, and a savings
 *      transaction its account's branch (permission review);
 *   2. else its loan's branch (`loan_id`);
 *   3. else its member's branch (`borrower_id`);
 *   4. else none.
 *
 * Read from the record's loaded attributes, so a model without the column
 * (or a row with no record at all) asks nothing. The loan and the member are
 * read straight from their tables, past any model scope, because the row must
 * record where the record lives, not what the actor may see.
 */
final class AuditBranch
{
    public static function of(?Model $record): ?int
    {
        if ($record === null) {
            return null;
        }

        $attributes = $record->getAttributes();

        $own = match (true) {
            $record instanceof Branch => self::id($record->getKey()),
            $record instanceof InterbranchTransfer => self::id($attributes['source_branch_id'] ?? null),
            $record instanceof SavingsTransaction => self::accountBranch($attributes['account_id'] ?? null),
            default => self::id($attributes['branch_id'] ?? null),
        };
        if ($own !== null) {
            return $own;
        }

        $loanId = self::id($attributes['loan_id'] ?? null);
        if ($loanId !== null) {
            $loanBranch = self::id(DB::table('loans')->where('id', $loanId)->value('branch_id'));

            if ($loanBranch !== null) {
                return $loanBranch;
            }
        }

        $memberId = self::id($attributes['borrower_id'] ?? null);

        return $memberId === null
            ? null
            : self::id(DB::table('borrowers')->where('id', $memberId)->value('branch_id'));
    }

    private static function accountBranch(mixed $accountId): ?int
    {
        $accountId = self::id($accountId);

        return $accountId === null ? null : self::id(DB::table('savings_accounts')->where('id', $accountId)->value('branch_id'));
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
