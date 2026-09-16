<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;

/**
 * Account classification rules — which side an account grows on, which
 * statement it lands on, and whether it may be posted to at all. The PHP half
 * of `@/lib/accounting/account` on the frontend.
 *
 * Everything here is derived from the account's own properties rather than
 * looked up per caller, so the balance sheet, the trial balance and the general
 * ledger cannot disagree about what an account means.
 */
final class AccountRules
{
    /** The five statement classifications every account rolls up into. */
    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Which side increases the account. */
    public const NORMAL_BALANCES = ['debit', 'credit'];

    /** Where the money physically sits. Drives the Cash & Bank screen. */
    public const CASH_KINDS = ['cash', 'bank', 'gcash', 'maya', 'wallet'];

    /**
     * Leading digit → classification, the convention the default chart follows.
     */
    private const TYPE_BY_PREFIX = [
        '1' => 'asset',
        '2' => 'liability',
        '3' => 'equity',
        '4' => 'income',
        '5' => 'expense',
    ];

    /**
     * The classification implied by an account code.
     *
     * Returns `null` for a code outside the 1–5 ranges rather than guessing.
     * Administrators add accounts, and an unclassifiable code has to surface as
     * a validation error at the point of creation rather than be quietly filed
     * under "asset" and misreported on every statement afterwards.
     */
    public static function typeFromCode(string $code): ?string
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return self::TYPE_BY_PREFIX[$code[0]] ?? null;
    }

    /**
     * Which side increases this account.
     *
     * Assets and expenses are debit-normal; liabilities, equity and income are
     * credit-normal. A contra account inverts its type — "1200 Allowance for
     * Credit Losses" is an asset that carries a credit balance and subtracts
     * from the assets above it.
     *
     * This is the ONLY place `normal_balance` is decided. It is never accepted
     * from a client: a caller who could send `debit` for a contra asset would
     * make Net Loans Receivable come out as gross PLUS the allowance.
     */
    public static function normalBalanceFor(string $type, bool $isContra): string
    {
        $base = ($type === 'asset' || $type === 'expense') ? 'debit' : 'credit';

        if (! $isContra) {
            return $base;
        }

        return $base === 'debit' ? 'credit' : 'debit';
    }

    /**
     * Whether a journal line may reference this account.
     *
     * Group headings are excluded because their displayed balance is the sum of
     * their subtree: posting to "1100 Loans Receivable" as well as its child
     * "1110 Current Loans Receivable" would count the same money twice.
     * Inactive accounts are excluded so a deactivated account stops accepting
     * new history while keeping the history it already has.
     */
    public static function isPostable(AccountingAccount $account): bool
    {
        return $account->is_active && ! $account->is_group;
    }

    /**
     * The account's balance in its own normal direction, in centavos.
     *
     * A positive result means the account sits where it should; a negative one
     * means it has swung the other way — an overdrawn cash account, say — which
     * is a real condition and is reported rather than clamped to zero.
     *
     * Because contra accounts are credit-normal, an allowance returns a
     * POSITIVE number here and callers subtract it from the gross figure.
     */
    public static function signedBalance(string $normalBalance, int $debit, int $credit): int
    {
        return $normalBalance === 'debit' ? $debit - $credit : $credit - $debit;
    }

    /** Which of the two primary statements an account reports on. */
    public static function belongsToStatement(string $type): string
    {
        return ($type === 'income' || $type === 'expense')
            ? 'income_statement'
            : 'balance_sheet';
    }
}
