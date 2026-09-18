<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use Illuminate\Validation\ValidationException;

/**
 * Turning a posting role or an account id into an account that may be posted
 * to — or refusing, loudly, when it cannot.
 *
 * ## Everything here FAILS CLOSED, and that is the whole point
 *
 * The alternative is not hypothetical. A recorder that could not find its
 * `accounts_payable` account has three options: write the expense with no
 * journal, write a one-sided journal, or refuse. The first two both produce a
 * screen that looks right — the expense is listed, the total is correct, the
 * dialog closed without an error — while the books are either missing the cost
 * entirely or are out of balance in a way that only the trial balance will
 * eventually show, long after anyone can say which entry caused it.
 *
 * So a missing mapping is a 422 with the name of the role and the screen that
 * sets it, and nothing at all is written. An expense that refuses to record is
 * a support ticket. An expense that records without its journal is a set of
 * books that has to be reconstructed.
 *
 * Used by the expense recorder and the fund transfer recorder. Kept as a trait
 * rather than a service so it composes into whichever class ends up owning a
 * posting path, without adding a constructor dependency to every one of them.
 */
trait ResolvesPostingAccounts
{
    /**
     * The account a posting role resolves to. Throws when the role is unset.
     *
     * @param  string  $role  One of AccountingAccountMapping::ROLES.
     * @param  string  $what  What was being recorded, for the message.
     */
    protected function requirePostingRole(string $role, string $what): AccountingAccount
    {
        $accountId = AccountingAccountMapping::query()
            ->where('role', $role)
            ->value('accounting_account_id');

        if ($accountId === null) {
            throw ValidationException::withMessages([
                'account_mapping' => [
                    "{$what} has to post to the {$role} account, and no account is mapped to that role yet. "
                    .'Set it under Accounting → Settings → Default Accounts first. Nothing has been recorded.',
                ],
            ]);
        }

        $account = AccountingAccount::query()->find($accountId);

        if ($account === null) {
            // The foreign key on accounting_account_mappings restricts deletes,
            // so this should be unreachable. Unreachable and unchecked are
            // different things, and the cost of being wrong here is a journal
            // line pointing at an account that does not exist.
            throw ValidationException::withMessages([
                'account_mapping' => [
                    "The {$role} role points at an account that no longer exists. Re-point it under "
                    .'Accounting → Settings → Default Accounts. Nothing has been recorded.',
                ],
            ]);
        }

        $this->assertPostable($account, $role);

        return $account;
    }

    /**
     * An account by id, checked as a MONEY account — the cash, bank, GCash or
     * Maya account a payment actually moves through.
     *
     * `cash_kind` is required, and not as a formality: it is what puts an
     * account on the Cash & Bank screen and into the dashboard's cash figures.
     * Crediting a payment to an account without one takes the money out of the
     * books while leaving it in every figure the organisation reads as "what we
     * hold", and both halves of that are silent.
     *
     * @param  string  $field  The request field at fault, so the 422 lands on
     *                         the right input.
     */
    protected function requireMoneyAccount(int $accountId, string $field): AccountingAccount
    {
        $account = AccountingAccount::query()->find($accountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                $field => ['That account does not exist.'],
            ]);
        }

        $this->assertPostable($account, $field);

        if ($account->cash_kind === null) {
            throw ValidationException::withMessages([
                $field => [
                    "{$account->code} {$account->name} is not a money account, so money cannot move through it. "
                    .'Pick a cash, bank, GCash or Maya account.',
                ],
            ]);
        }

        return $account;
    }

    /**
     * An account by id, checked as an EXPENSE account — where the cost lands.
     *
     * The type is checked rather than assumed because the entry balances either
     * way. Point a cost at an income account and the income statement reports
     * revenue that never happened, with debits and credits still equal and
     * every report rendering without complaint.
     */
    protected function requireExpenseAccount(int $accountId, string $field): AccountingAccount
    {
        $account = AccountingAccount::query()->find($accountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                $field => ['That account does not exist.'],
            ]);
        }

        $this->assertPostable($account, $field);

        if ($account->type !== 'expense') {
            throw ValidationException::withMessages([
                $field => [
                    "{$account->code} {$account->name} is {$account->type}, not an expense account. "
                    .'The cost would land on the wrong statement and the entry would still balance, '
                    .'so nothing would report the mistake.',
                ],
            ]);
        }

        return $account;
    }

    /**
     * Active, and not a heading.
     *
     * JournalPoster re-checks both at post time and would refuse there too —
     * this is here so the refusal names the field the user chose rather than
     * arriving as a validation error about a journal the user never saw.
     */
    private function assertPostable(AccountingAccount $account, string $field): void
    {
        if ($account->is_group) {
            throw ValidationException::withMessages([
                $field => [
                    "{$account->code} {$account->name} is a heading. Its balance is the total of the accounts "
                    .'beneath it, so posting to it as well would count the same money twice. Pick one of its '
                    .'children.',
                ],
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                $field => ["{$account->code} {$account->name} is deactivated and no longer accepts entries."],
            ]);
        }
    }
}
