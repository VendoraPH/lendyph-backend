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
 * is financing here and revenue somewhere else. That is the whole reason the
 * cash flow statement is the one statement the frontend cannot regroup out of
 * the trial balance — see the docblock on `CashFlowReport` in
 * `src/app/(app)/accounting/statements/_components/cash-flow-report.tsx`, which
 * says the same thing from the other side.
 *
 * ## The default is one sentence
 *
 * Income and expense are what the business DOES; everything else it owns is
 * investing; everything it owes or was given is financing; and cash itself is
 * not an activity at all, because cash is the thing the statement explains.
 *
 * Stated as a rule rather than a table of sixty accounts on purpose. A reviewer
 * can check a rule. Nobody checks a table.
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
            // What it owns. For a lender the largest of these is the loan
            // portfolio, and putting it here is the one classification most
            // worth reviewing: releasing and collecting loans is this
            // organisation's core operation, and an accountant may well move it
            // to operating. The report makes that choice visible either way.
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
