<?php

namespace App\Http\Requests\Savings\Transaction;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use App\Models\SavingsTransaction;
use App\Services\Savings\PayoutReferences;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/transactions/{transaction}/reference` (finish run round 6):
 * the reference of a withdrawal or closing payout sent before it existed.
 *
 * In the API's order: 403 without either payout permission, 404 for a row
 * outside the caller's branches, 403 when the row is a payout the caller's
 * permissions don't post (a withdrawal needs savings:transact, a closing
 * payout savings:close), then the body (422). Any other row passes here and is
 * refused in words by PayoutReferences.
 */
class RecordPayoutReferenceRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! ($user->can('savings:transact') || $user->can('savings:close'))) {
            return false;
        }

        $this->authorizeRecord(null, 'transaction', 'transaction');
        /** @var SavingsTransaction $row */
        $row = $this->record();
        $permission = PayoutReferences::permissionFor($row);

        return $permission === null || $user->can($permission);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reference' => ['required', 'string', 'max:'.PayoutReferences::TRANSACTION_MAX]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference.required' => 'Enter the reference of this payout.',
            'reference.string' => 'The reference must be text.',
            'reference.max' => 'The reference can be at most '.PayoutReferences::TRANSACTION_MAX.' characters.',
        ];
    }
}
