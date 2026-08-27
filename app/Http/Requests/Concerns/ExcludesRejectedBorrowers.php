<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The `borrower_id` constraint for endpoints that must refuse a rejected
 * applicant: loans, restructures, collaterals and GCash transactions.
 */
trait ExcludesRejectedBorrowers
{
    /**
     * `exists:borrowers,id`, narrowed to borrowers who were not rejected.
     *
     * Rejecting a registration used to hard-delete the applicant, so a rejected
     * borrower_id simply did not resolve. This release keeps the row instead
     * (`status = 'rejected'`, preserving the audit trail), which means a real,
     * resolvable borrower id can now point at somebody the cooperative turned
     * away. The frontend passes `members_only=1` to its borrower pickers, but
     * that is a UI filter — a hand-made request reaches this rule regardless,
     * and `exists:borrowers,id` on its own accepts it.
     *
     * Narrowing the existing `exists` rule rather than adding a second lookup
     * keeps it to one query and keeps the failure on `borrower_id`, where every
     * client already reads it.
     *
     * Deliberately NOT Borrower::NON_MEMBER_STATUSES, and deliberately NOT
     * scopeMembers(). Those also cover `pending`, which is correct on the Share
     * Capital paths and wrong here: "pending plus a loan" is a real state in
     * this data model — PruneAbandonedRegistrations says so in as many words,
     * and the portfolio database holds ten loans across five pending borrowers,
     * a third of its loan book. Gating `pending` would break a workflow in
     * daily use. Gating `rejected` breaks nothing: no rejected borrower holds a
     * loan, a collateral or a GCash transaction on any deployment.
     *
     * StoreShareCapitalLedgerRequest gates BOTH statuses and is right to —
     * production holds no ledger entry for any pending or rejected borrower, so
     * the wider rule costs nothing there. The asymmetry between these two rules
     * is measured, not accidental; that request's PHPDoc carries the figures.
     * Please do not unify them.
     */
    protected function nonRejectedBorrowerRule(): Exists
    {
        return Rule::exists('borrowers', 'id')->whereNot('status', 'rejected');
    }
}
