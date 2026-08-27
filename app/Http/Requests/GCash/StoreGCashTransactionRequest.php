<?php

namespace App\Http\Requests\GCash;

use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGCashTransactionRequest extends FormRequest
{
    use ExcludesRejectedBorrowers;

    public function authorize(): bool
    {
        return $this->user()->can('gcash:transact');
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['required', 'integer', $this->nonRejectedBorrowerRule()],
            'type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'is_pending' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
