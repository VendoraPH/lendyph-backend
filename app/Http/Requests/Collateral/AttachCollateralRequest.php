<?php

namespace App\Http\Requests\Collateral;

use App\Http\Requests\Concerns\ValidatesLoanCollaterals;
use Illuminate\Foundation\Http\FormRequest;

class AttachCollateralRequest extends FormRequest
{
    use ValidatesLoanCollaterals;

    public function authorize(): bool
    {
        return $this->user()->can('collaterals:update') && $this->user()->can('loans:update');
    }

    /**
     * The collateral must be on this loan's borrower's register; see
     * ValidatesLoanCollaterals::ownedByThisLoansBorrowerRule().
     */
    public function rules(): array
    {
        return [
            'collateral_id' => $this->collateralIdRules(),
            'snapshot_value' => $this->snapshotValueRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collateral_id.exists' => $this->notThisLoansBorrowerMessage(),
        ];
    }
}
