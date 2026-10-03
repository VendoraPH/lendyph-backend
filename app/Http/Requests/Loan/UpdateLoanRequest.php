<?php

namespace App\Http\Requests\Loan;

use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use App\Http\Requests\Concerns\RequiresActiveAccountOfficer;
use App\Http\Requests\Concerns\ValidatesDeductionAmounts;
use App\Http\Requests\Concerns\ValidatesLoanCollaterals;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanRequest extends FormRequest
{
    use ExcludesRejectedBorrowers, RequiresActiveAccountOfficer, ValidatesDeductionAmounts, ValidatesLoanCollaterals;

    /**
     * A request that carries `collaterals`, whatever its value, is asking to
     * attach and detach collateral, so it also needs `collaterals:update`: the
     * same pair of permissions the attach and detach endpoints require.
     */
    public function authorize(): bool
    {
        if (! $this->user()->can('loans:update')) {
            return false;
        }

        return ! $this->exists('collaterals') || $this->user()->can('collaterals:update');
    }

    public function rules(): array
    {
        return [
            'co_maker_ids' => ['nullable', 'array'],
            // MEMBER ids only — all the loan form's co-maker picker sends —
            // never a co-maker record id: the two are separate sequences, so a
            // number accepted as either can name two different people. See
            // LoanService::coMakerIdsForMembers(). The same rule as the
            // principal borrower on StoreLoanRequest, because a co-maker is
            // jointly liable: a rejected registration must not become one.
            'co_maker_ids.*' => ['integer', $this->nonRejectedBorrowerRule()],
            // Sent by the edit form's account-officer picker. Without a rule the
            // value never reached validated(), so a draft's officer could be
            // changed on screen and silently not saved.
            'account_officer_id' => ['nullable', 'integer', $this->activeAccountOfficerRule()],
            'principal_amount' => ['sometimes', 'numeric', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:500'],
            // Only consumed when the principal of a RESTRUCTURE is being changed:
            // that re-runs the shortfall rules, which require a reason. There is
            // no `remarks` column on loans, so it never reaches the row itself —
            // LoanService maps it onto `restructure_remarks`.
            'remarks' => ['nullable', 'string', 'max:1000'],
            'interest_rate' => ['sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'start_date' => ['sometimes', 'date'],
            'scb_amount' => ['sometimes', 'numeric', 'min:0'],
            'policy_exception' => ['sometimes', 'boolean'],
            'policy_exception_details' => ['nullable', 'string', 'max:2000'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['required_with:deductions', 'string', 'max:255'],
            'deductions.*.amount' => $this->deductionAmountRule(),
            'deductions.*.type' => ['required_with:deductions', 'in:fixed,percentage'],
            // The loan's complete collateral list. Absent: collateral is left
            // exactly as it is. A list, `[]` included, is what the loan should
            // hold afterwards; LoanService::updateLoan() detaches the rest and
            // attaches the new ones. `null` is refused rather than read as
            // either, because the two mean opposite things.
            'collaterals' => ['sometimes', 'list'],
            'collaterals.*.collateral_id' => [...$this->collateralIdRules(), 'distinct'],
            'collaterals.*.snapshot_value' => $this->snapshotValueRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'co_maker_ids.*.exists' => 'Each co-maker must be an existing member who was not rejected.',
            'account_officer_id.exists' => $this->activeAccountOfficerMessage(),
            'collaterals.*.collateral_id.exists' => $this->notThisLoansBorrowerMessage(),
            'collaterals.*.collateral_id.distinct' => 'Each collateral can be listed only once.',
        ];
    }
}
