<?php

namespace App\Http\Requests\Collateral;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class AttachCollateralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('collaterals:update') && $this->user()->can('loans:update');
    }

    public function rules(): array
    {
        return [
            'collateral_id' => ['required', 'integer', $this->ownedByThisLoansBorrowerRule()],
            'snapshot_value' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    /**
     * `exists:collaterals,id`, narrowed to the collateral register of the
     * borrower whose loan this is.
     *
     * Unnarrowed, `exists:collaterals,id` accepted ANY resolvable collateral id,
     * so member A's land title could be posted as security for member B's loan
     * by hand-editing one number in the request body. The pickers already scope
     * their list by `borrower_id` (loans/new, loans/restructure and the borrower
     * collaterals tab all call collateralService.list({ borrower_id })), so this
     * takes nothing away from any real client — it just makes the server enforce
     * what the UI was already assuming.
     *
     * Narrowing the `exists` rule that already runs, rather than adding a
     * second lookup or a guard in the controller, is deliberate on three counts.
     * It is one query, not two. It fails on `collateral_id`, where every client
     * already reads attach errors. And it rejects before
     * CollateralController::attach() opens a transaction and takes a row lock on
     * a collateral the request was never entitled to name. This is the same
     * shape StoreCollateralRequest uses for nonRejectedBorrowerRule() — ownership
     * discipline expressed as a narrowed exists, not as a second round trip.
     *
     * The rule is "must match the loan's borrower", with NO co-maker exception,
     * because co-maker collateral is not representable in this data model:
     * `Collateral` carries a single `borrower_id`, `CoMaker` has no
     * `collaterals()` relation, and `LoanService::createLoan()` touches
     * collateral zero times. A "borrower or co-maker" rule would also buy
     * nothing even if it were written — `co_makers.borrower_id` is a foreign key
     * to `borrowers` that this codebase populates inconsistently, so it cannot
     * be trusted to identify a distinct third party.
     *
     * Fails CLOSED if the route model is somehow not a Loan: matching no row is
     * the safe answer, not matching every row.
     */
    protected function ownedByThisLoansBorrowerRule(): Exists
    {
        $loan = $this->route('loan');

        return Rule::exists('collaterals', 'id')
            ->where('borrower_id', $loan instanceof Loan ? $loan->borrower_id : 0);
    }

    /**
     * One message for both failure modes of the narrowed rule — a collateral
     * belonging to someone else, and an id that resolves to nothing — because
     * it is true of both and because telling them apart would turn this
     * endpoint into an enumeration oracle over other members' registers.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collateral_id.exists' => 'This collateral is not registered to this loan\'s borrower.',
        ];
    }
}
