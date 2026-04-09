<?php

namespace App\Http\Requests\ShareCapital;

use Illuminate\Foundation\Http\FormRequest;

class ShareCapitalBulkEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share-capital.create');
    }

    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.pledge_id' => ['required', 'integer', 'exists:share_capital_pledges,id'],
            'entries.*.amount' => ['required', 'numeric', 'min:0.01'],
            'entries.*.type' => ['required', 'in:credit,debit'],
            'entries.*.date' => ['required', 'date'],
            'entries.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
