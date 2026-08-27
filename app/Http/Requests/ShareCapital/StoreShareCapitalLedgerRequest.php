<?php

namespace App\Http\Requests\ShareCapital;

use App\Models\Borrower;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreShareCapitalLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share_capital:create');
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['required', 'integer', $this->memberBorrowerRule()],
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    /**
     * `exists:borrowers,id`, narrowed to actual members.
     *
     * This endpoint writes the same ShareCapitalLedger row that
     * ShareCapitalPledgeController::manualEntry() and bulkEntry() write, but
     * reaches it by `borrower_id` instead of a pledge id. Those two refuse a
     * borrower in Borrower::NON_MEMBER_STATUSES; without the same gate here the
     * guard is only partly real, because the identical credit can be posted
     * through this route instead.
     *
     * Gates BOTH `pending` and `rejected` — deliberately wider than
     * ExcludesRejectedBorrowers, which the loan, collateral and GCash requests
     * use to gate `rejected` only. The two rules differ on purpose and the
     * difference is driven by production data, not taste:
     *
     *  - Loans: five `pending` borrowers hold ten live loans on the portfolio
     *    deployment — a third of its loan book. "Pending plus a loan" is a real
     *    state (PruneAbandonedRegistrations says so outright), so gating
     *    `pending` there would break a workflow in daily use.
     *  - Share capital ledger: across all three production databases, NOT ONE
     *    `pending` or `rejected` borrower holds a ledger entry. binhs-coop and
     *    lendyph.com hold zero entries at all; the portfolio box holds ten
     *    across three borrowers, every one of them `active`. The
     *    `whereDoesntHave('shareCapitalLedger')` guard in
     *    PruneAbandonedRegistrations is defensive, not evidence of a live
     *    workflow.
     *
     * So this one costs nothing and closes the last bypass, while the narrow
     * rule elsewhere is the only one that is safe to ship. Please do not
     * "unify" them.
     *
     * The list itself comes from the constant — there is one definition of
     * "member" and neither this rule nor ShareCapitalPledgeController's
     * assertBorrowerIsMember() gets its own copy.
     */
    private function memberBorrowerRule(): Exists
    {
        return Rule::exists('borrowers', 'id')
            ->whereNotIn('status', Borrower::NON_MEMBER_STATUSES);
    }
}
