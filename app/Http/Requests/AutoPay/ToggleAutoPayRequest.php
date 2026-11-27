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

    /**
     * Refusals in words, never Laravel's defaults ("The cbs reference field
     * is required when enabled is true.").
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enabled.required' => 'Choose whether Auto-Pay is on or off.',
            'enabled.boolean' => 'Choose whether Auto-Pay is on or off.',
            'cbs_reference.required_if' => 'Enter the CBS reference to turn Auto-Pay on.',
            'cbs_reference.string' => 'The CBS reference must be text.',
            'cbs_reference.max' => 'The CBS reference can be at most 100 characters.',
        ];
    }
}
