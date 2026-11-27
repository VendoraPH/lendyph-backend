<?php

namespace App\Http\Requests\Savings\Transaction;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use Illuminate\Foundation\Http\FormRequest;

/** `POST /savings/transactions/{transaction}/reverse` (savings:transact), with a reason. */
class ReverseSavingsTransactionRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:transact', 'transaction', 'transaction');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this line is being reversed.',
            'reason.string' => 'The reason must be text.',
            'reason.max' => 'The reason can be at most 500 characters.',
        ];
    }
}
