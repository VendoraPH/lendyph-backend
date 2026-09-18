<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;

/**
 * The posting roles resolved to account ids, for one posting.
 *
 * {@see PostingRules} never names an account. It asks for a role
 * ("interest_income") and this answers with an id — the indirection that lets a
 * cooperative and a lending corporation share one engine.
 *
 * ## Why this FAILS CLOSED, and what that costs
 *
 * A rule that asked for an unmapped role and got `null` would have three ways
 * to carry on, and all three are worse than refusing:
 *
 * - Skip the line. The entry no longer balances, and {@see JournalPoster} would
 *   reject it — with a message about debits and credits that names neither the
 *   role nor the setting that is missing.
 * - Skip the whole journal. The loan releases, the money moves, and the books
 *   quietly do not mention it. Nothing reports a missing entry, because nothing
 *   knows one was due. This is the failure this module exists to prevent.
 * - Fall back to some default account. The entry balances, posts, and appears
 *   on every statement against an account nobody chose.
 *
 * So an unmapped role throws, the surrounding lending transaction rolls back,
 * and the operator gets a message naming the role and the screen that sets it.
 * Refusing to release a loan is a loud, recoverable failure; releasing one the
 * books do not record is a silent, permanent one.
 *
 * ## The gate: organisations that have not adopted accounting
 *
 * Failing closed on a role cannot mean failing closed on an organisation that
 * has never opened the accounting module — that would stop every release on
 * every deployment the moment this shipped. {@see self::chartExists()} is the
 * one condition that separates the two, and it is the same question
 * {@see ChartOfAccountsSeeder::hasChart()} asks: an organisation with no chart
 * of accounts has no books to keep, so there is no entry to miss. The moment a
 * chart exists the organisation IS keeping books, and every rule the engine
 * runs must resolve completely or refuse.
 */
final class AccountMap
{
    /**
     * @param  array<string, int>  $roles  role => accounting_accounts.id
     */
    private function __construct(private readonly array $roles) {}

    /**
     * Reads the whole mapping in one query.
     *
     * Resolved once per posting and passed down rather than looked up per role:
     * a loan collection names up to six roles, and six round trips inside a
     * lending transaction is six chances to be blocked behind someone else's
     * lock.
     */
    public static function resolve(): self
    {
        return new self(AccountingAccountMapping::resolved());
    }

    /**
     * Builds a map from a literal role => id array. For tests and for callers
     * that already hold a resolved mapping.
     *
     * @param  array<string, int>  $roles
     */
    public static function fromArray(array $roles): self
    {
        return new self($roles);
    }

    /**
     * Whether this organisation keeps books at all.
     *
     * Deliberately NOT cached. It is a primary-key existence check on a table of
     * a few dozen rows, and the alternative — a cached answer that goes stale
     * the moment an administrator seeds a chart — would mean the first
     * collections after adoption silently post nothing, which is the exact
     * failure mode this whole class is built to prevent. Cheap is not the same
     * as free, but correct is worth more than either.
     */
    public static function chartExists(): bool
    {
        return AccountingAccount::query()->exists();
    }

    /**
     * The account a role resolves to.
     *
     * @throws CannotPostToTheBooksException when the role is unmapped
     */
    public function accountFor(string $role): int
    {
        $id = $this->roles[$role] ?? null;

        if ($id === null) {
            throw CannotPostToTheBooksException::because(
                "This transaction posts to the \"{$role}\" account, which has not been set. Open Accounting "
                .'→ Settings → Default Accounts and choose one, then try again. Nothing has been saved: a '
                .'transaction that moved money without an entry in the books would leave the two disagreeing '
                .'with nothing to point at the difference.');
        }

        return $id;
    }

    /** Whether a role has an account behind it, without throwing. */
    public function has(string $role): bool
    {
        return isset($this->roles[$role]);
    }

    /** @return array<string, int> */
    public function all(): array
    {
        return $this->roles;
    }
}
