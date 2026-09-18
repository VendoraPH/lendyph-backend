<?php

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Moving money between the organisation's own accounts.
 *
 * The payload is what `TransferDialog` in
 * `src/app/(app)/accounting/_components/transfer-dialog.tsx` sends: `date`,
 * `from_account_id`, `to_account_id`, `amount`, an optional `charge`, and
 * `description`.
 *
 * `charge_account_id` is an ADDITION to that payload, not a requirement. The
 * dialog does not send one, so a charge on a default chart falls back to the
 * chart's own Bank Charges / GCash Charges account — and if that account has
 * been removed, the request is refused with this field named, rather than the
 * charge being quietly dropped from the entry.
 */
class StoreFundTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cash_accounts:transfer');
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            // Both checked as MONEY accounts in the recorder — postable, active
            // and carrying a `cash_kind`. `different` is here as well as there
            // so the commonest mistake is caught by the field rather than by a
            // service message.
            'from_account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'to_account_id' => [
                'required', 'integer', 'different:from_account_id',
                'exists:accounting_accounts,id',
            ],
            /*
             * INTEGER CENTAVOS. ₱10,000.00 arrives as 1000000.
             *
             * `integer` refuses a client that skipped `toCentavos` rather than
             * flooring it — which on a transfer would move a hundredth of the
             * intended amount between two real accounts and leave both
             * balances wrong in opposite directions.
             */
            'amount' => ['required', 'integer', 'min:1', 'max:'.Money::maxCentavos()],
            // Optional, and 0 is a legitimate "no charge" that the dialog sends
            // as `undefined`. `min:0` rather than `min:1` so an explicit zero is
            // not a validation error.
            'charge' => ['nullable', 'integer', 'min:0', 'max:'.Money::maxCentavos()],
            'charge_account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            'description' => ['required', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'reference' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('description')) {
            $this->merge(['description' => trim((string) $this->input('description'))]);
        }
    }
}
