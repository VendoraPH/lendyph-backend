<?php

namespace App\Http\Requests\ShareCapital;

use Illuminate\Foundation\Http\FormRequest;

class ShareCapitalManualEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share_capital:create');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'type' => ['required', 'in:credit,debit'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
