<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccountMapping;
use Illuminate\Support\Facades\DB;

/**
 * The accounts that hold loan principal: the gross loans receivable.
 *
 * The account mapped to `loans_receivable`, or, when that account sits under
 * a heading that is not itself a root, every postable account under the same
 * heading (1110 Current and 1120 Past Due under 1100 in the seeded chart).
 * Mapped directly under a root heading such as 1000 Assets, it is that
 * account alone, so the rule never takes in every asset. One rule, shared by the cash
 * flow statement (a release's receivable lines are not withheld amounts) and
 * the accounting dashboard's gross receivable, so the two can never disagree
 * about which accounts are the portfolio.
 *
 * Empty when no chart or no mapping exists.
 */
final class LoanReceivableAccounts
{
    /**
     * @return list<int> account ids, in id order
     */
    public static function ids(): array
    {
        $mappedId = AccountingAccountMapping::resolved()['loans_receivable'] ?? null;

        if ($mappedId === null) {
            return [];
        }

        $mapped = DB::table('accounting_accounts')->where('id', $mappedId)->first(['id', 'parent_id']);

        if ($mapped === null) {
            return [];
        }

        // A root heading ("1000 Assets") is a statement section, not a loan
        // heading: under it the mapped account stands alone.
        $headingId = $mapped->parent_id === null
            ? null
            : DB::table('accounting_accounts')
                ->where('id', $mapped->parent_id)
                ->where('is_group', true)
                ->whereNotNull('parent_id')
                ->value('id');

        if ($headingId === null) {
            return [(int) $mapped->id];
        }

        return DB::table('accounting_accounts')
            ->where('parent_id', $headingId)
            ->where('is_group', false)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
