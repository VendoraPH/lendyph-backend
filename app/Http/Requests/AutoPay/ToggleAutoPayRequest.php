<?php

namespace App\Http\Requests\AutoPay;

use Illuminate\Foundation\Http\FormRequest;

class ToggleAutoPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('auto_pay:toggle');
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'cbs_reference' => ['nullable', 'string', 'max:100', 'required_if:enabled,true'],
        ];
    }
}
