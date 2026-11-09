<?php

namespace App\Http\Requests\ShareCapital;

use App\Http\Requests\Concerns\ReferenceNumberMessages;
use App\Services\ShareCapitalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShareCapitalManualEntryRequest extends FormRequest
{
    use ReferenceNumberMessages;

    public function authorize(): bool
    {
        return $this->user()->can('share_capital:create');
    }

    /**
     * `decimal:0,2` on the amount: its column is decimal(12,2) and the model
     * casts it `decimal:2`, which would round a third decimal away without a
     * word (₱1,500.555 saved as ₱1,500.56). Exact centavos are accepted; more
     * than two decimals is a 422 on the field, never a silent rounding.
     *
     * `method` is how the money moved, and its journal posts to that
     * method's account; `reference_number` is required unless cash.
     *
     * The date can't be after today, as Record Payment's.
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'type' => ['required', 'in:credit,debit'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:255'],
            'method' => ['sometimes', Rule::in(ShareCapitalService::ENTRY_METHODS)],
            'reference_number' => ['nullable', 'string', 'max:100', 'required_unless:method,cash'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->referenceNumberMessages();
    }

    /**
     * An entry sent without a method was paid in cash, as Record Payment
     * assumes (StoreRepaymentRequest).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'cash']);
        }
    }
}
