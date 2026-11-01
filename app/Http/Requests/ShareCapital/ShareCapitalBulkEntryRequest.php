<?php

namespace App\Http\Requests\ShareCapital;

use Illuminate\Foundation\Http\FormRequest;

class ShareCapitalBulkEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share_capital:create');
    }

    /**
     * `decimal:0,2` on the amount: its column is decimal(12,2) and the model
     * casts it `decimal:2`, which would round a third decimal away without a
     * word (₱1,500.555 saved as ₱1,500.56). Exact centavos are accepted; more
     * than two decimals is a 422 on the field, never a silent rounding.
     */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.pledge_id' => ['required', 'integer', 'exists:share_capital_pledges,id'],
            'entries.*.amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'entries.*.type' => ['required', 'in:credit,debit'],
            'entries.*.date' => ['required', 'date'],
            'entries.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
