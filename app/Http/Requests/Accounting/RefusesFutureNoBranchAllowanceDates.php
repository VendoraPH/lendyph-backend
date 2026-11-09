<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use Illuminate\Support\Carbon;

/**
 * A manual journal with no branch that has a line on the allowance for credit
 * losses can't be dated after today in Manila: on create, on edit, and on
 * reversal (follow-up round 3, Lead's call 1).
 *
 * An allowance run leaves the no-branch balance alone and refuses while one
 * exists on its date, but an entry dated in the future isn't in the books yet.
 * One that moves a no-branch balance into a branch on its own, later, date
 * would let a run in between provide in full against a balance that is about
 * to move, and the next run release it again.
 *
 * The allowance account is the one the `allowance_credit_losses` mapping
 * names (1200 by default), as JournalPoster reads it, never a hard-coded
 * code. With no mapping there is no allowance to protect, and nothing is
 * refused.
 */
trait RefusesFutureNoBranchAllowanceDates
{
    private const ALLOWANCE_ROLE = 'allowance_credit_losses';

    /** Today in the app's time zone (Manila), "2026-11-10". */
    protected function todayInManila(): string
    {
        return Carbon::now(config('app.timezone'))->toDateString();
    }

    /**
     * The allowance account, when one of `$accountIds` is it; otherwise null.
     *
     * @param  array<int, mixed>  $accountIds
     */
    protected function allowanceAccountAmong(array $accountIds): ?AccountingAccount
    {
        $allowanceId = AccountingAccountMapping::query()
            ->where('role', self::ALLOWANCE_ROLE)
            ->value('accounting_account_id');

        if ($allowanceId === null) {
            return null;
        }

        $ids = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $accountIds);

        return in_array((int) $allowanceId, $ids, true)
            ? AccountingAccount::query()->find((int) $allowanceId, ['id', 'code', 'name'])
            : null;
    }

    /** "1200 Allowance for Credit Losses". */
    protected function accountInWords(AccountingAccount $account): string
    {
        return trim("{$account->code} {$account->name}");
    }

    /** "Nov 10, 2026". */
    protected function dayInWords(string $day): string
    {
        return Carbon::parse($day)->format('M j, Y');
    }
}
