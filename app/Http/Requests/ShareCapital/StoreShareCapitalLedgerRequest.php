<?php

namespace App\Http\Requests\ShareCapital;

use Illuminate\Foundation\Http\FormRequest;

class StoreShareCapitalLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share_capital:create');
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['required', 'integer', 'exists:borrowers,id'],
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
