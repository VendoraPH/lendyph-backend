<?php

namespace App\Http\Requests\ShareCapital;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShareCapitalPledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share_capital:update');
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
            'amount' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'schedule' => ['sometimes', 'in:15,30,15/30'],
        ];
    }
}
