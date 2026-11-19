<?php

namespace App\Http\Requests\Branch;

use App\Services\Accounting\Money;
use App\Services\BranchAccess;
use Illuminate\Validation\Rule;

/**
 * A new interbranch transfer. The source must be one of the maker's branches
 * (another reads as missing); the destination may be any other branch. Both
 * accounts are checked as money accounts by InterbranchTransfers. `amount` is
 * INTEGER CENTAVOS, like the Cash & Bank transfer: ₱1,200.00 arrives as
 * 120000.
 */
class StoreInterbranchTransferRequest extends InterbranchTransferRequest
{
    public const PERMISSION = 'interbranch_transfers:create';

    public const REFUSAL = 'Making an interbranch transfer needs the interbranch transfers create permission.';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = ['required', 'integer', 'min:1', 'max:999999999999999999'];

        return [
            'source_branch_id' => [...$id, app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user())],
            'destination_branch_id' => [...$id, 'different:source_branch_id', Rule::exists('branches', 'id')],
            'from_account_id' => [...$id, Rule::exists('accounting_accounts', 'id')],
            'to_account_id' => [...$id, Rule::exists('accounting_accounts', 'id')],
            'amount' => ['required', 'integer', 'min:1', 'max:'.Money::maxCentavos()],
            'purpose' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'destination_branch_id.different' => 'The money has to go to a different branch.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['purpose', 'reference'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
