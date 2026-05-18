<?php

namespace App\Http\Requests\GCash;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGCashTiersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gcash:settings');
    }

    public function rules(): array
    {
        return [
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.min_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tiers.*.max_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tiers.*.cash_in_rate' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'tiers.*.cash_out_rate' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'tiers.*.display_order' => ['required', 'integer', 'min:1'],
        ];
    }
}
