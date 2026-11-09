<?php

namespace App\Services\Accounting;

/**
 * Which activity a movement through an account belongs to.
 *
 * ## Why this cannot be derived from a balance
 *
 * Operating, investing or financing is a judgement about what the money was
 * FOR, and the ledger does not record it. Releasing a loan is investing for
 * almost any business and is the core operation of a lender; a member's deposit
 * is financing here and revenue somewhere else. So the classification lives
 * on the account, where an accountant can change it.
 *
 * ## The default is one sentence, with one seeded exception
 *
 * Income and expense are what the business DOES; everything else it owns is
 * investing; everything it owes or was given is financing; and cash itself is
 * not an activity at all, because cash is the thing the statement explains.
 *
 * The exception is the loan book (Phase 2, decision 9): releasing and
 * collecting loans is this organisation's operation, so the seeded loan
 * accounts (ChartOfAccountsSeeder::OPERATING_LOAN_ACCOUNT_CODES) read
 * operating, and CashFlowCategoryBackfill moves them on charts seeded before.
 * An account a user adds still gets the rule below.
 *
 * ## The defaults are meant to be argued with
 *
 * Every one of them is editable through `PUT /accounting/accounts/{id}`, and
 * the statement prints ONE LINE PER ACCOUNT under the heading it actually used.
 * So a classification anyone disagrees with — "why is Loans Receivable under
 * investing?" — is visible on the face of the report, next to its code and its
 * amount, rather than folded into a subtotal where a wrong answer looks exactly
 * like a right one.
 */
class CashFlowCategories
{
    /**
     * The three activity sections, plus `cash`.
     *
     * `cash` is not a fourth activity. It marks the accounts the statement is
     * ABOUT: their movements are the net change being explained, not a source
     * or a use of it. Without it, a sweep from GCash to the bank would appear
     * as a flow in one section and an equal, opposite flow in another, and both
     * would be wrong.
     */
    public const ALL = ['operating', 'investing', 'financing', 'cash'];

    /** The three that appear as sections on the statement, in reading order. */
    public const ACTIVITIES = ['operating', 'investing', 'financing'];

    public const SECTION_LABELS = [
        'operating' => 'Operating activities',
        'investing' => 'Investing activities',
        'financing' => 'Financing activities',
    ];

    /**
     * The classification an account gets when nobody has chosen one.
     *
     * `$cashKind` wins over `$type` because it is the more specific fact: an
     * account carrying one IS money, whatever else it is.
     */
    public static function defaultFor(string $type, ?string $cashKind): string
    {
        if ($cashKind !== null) {
            return 'cash';
        }

        return match ($type) {
            // What the business does — the revenue it earns and the costs of
            // earning it.
            'income', 'expense' => 'operating',
            // What it owns. The seeded loan accounts are the exception and
            // read operating (see the class docblock).
            'asset' => 'investing',
            // What it owes and what it was given — members' deposits, share
            // capital, borrowings.
            'liability', 'equity' => 'financing',
            // Unreachable: `type` is an enum of exactly those five. Operating
            // rather than a throw, because a statement that omitted an account
            // entirely would be silently wrong, and one that filed it under the
            // commonest heading is visibly wrong.
            default => 'operating',
        };
    }

    public static function isActivity(?string $category): bool
    {
        return in_array($category, self::ACTIVITIES, true);
    }
}
