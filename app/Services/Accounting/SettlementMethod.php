<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccountMapping;

/**
 * Which money account a lending event settled through.
 *
 * The lending tables and the accounting module use DIFFERENT vocabularies for
 * the same idea, and the gap is the whole reason this class exists:
 *
 * - `repayments.method` is an enum of `cash`, `gcash`, `maya`, `bank_transfer`,
 *   `online`, `auto_pay` — how the borrower paid.
 * - {@see AccountingAccountMapping::ROLES} names four settlement
 *   roles — `cash`, `gcash`, `maya`, `bank` — which are what a posting rule can
 *   ask for, and which mirror `SettlementMethod` in `src/types/accounting.ts`.
 *
 * Three of the six line up by name. The other three do not, and translating
 * them anywhere other than here would mean three copies of a judgement call:
 * `bank_transfer`, `online` and `auto_pay` are all money arriving in the
 * organisation's bank, not in its drawer.
 *
 * FAILS CLOSED on a method it does not recognise. The lending enum has already
 * grown once (`auto_pay` was added by a migration in April), and the next
 * addition must stop the posting rather than quietly file the money under cash
 * — a collection posted to the wrong asset account still balances, still
 * appears on every statement, and is only ever found by counting the drawer.
 */
final class SettlementMethod
{
    /**
     * The settlement roles a posting rule may name, in
     * {@see AccountingAccountMapping::ROLES} order.
     *
     * @var list<string>
     */
    public const ROLES = ['cash', 'gcash', 'maya', 'bank'];

    /**
     * `repayments.method` → settlement role.
     *
     * `online` and `auto_pay` both resolve to `bank`: an online payment lands in
     * the organisation's bank account, and auto-pay is a standing debit
     * arrangement against one. Neither is cash on hand, and putting them there
     * would overstate the drawer by every such payment.
     *
     * @var array<string, string>
     */
    private const FROM_LENDING = [
        'cash' => 'cash',
        'gcash' => 'gcash',
        'maya' => 'maya',
        'bank_transfer' => 'bank',
        'online' => 'bank',
        'auto_pay' => 'bank',
    ];

    /**
     * The settlement role a `repayments.method` value posts through.
     *
     * @throws CannotPostToTheBooksException when the method has no declared role
     */
    public static function fromLendingMethod(string $method): string
    {
        $role = self::FROM_LENDING[$method] ?? null;

        if ($role === null) {
            throw CannotPostToTheBooksException::because(
                "There is no accounting settlement account defined for the payment method \"{$method}\", so "
                .'this transaction cannot be posted to the books and has NOT been recorded. Posting it to the '
                .'wrong account would balance, appear on every statement, and only ever be caught by counting '
                .'the cash.');
        }

        return $role;
    }

    /**
     * Guards a role supplied directly by a rule caller rather than translated
     * from a lending enum — a fund transfer's two legs, say.
     *
     * @throws CannotPostToTheBooksException when the role is not a settlement account
     */
    public static function assertIsSettlementRole(string $role): string
    {
        if (! in_array($role, self::ROLES, true)) {
            throw CannotPostToTheBooksException::because(
                "\"{$role}\" is not a settlement account. Money can only move through "
                .implode(', ', self::ROLES).'.');
        }

        return $role;
    }
}
