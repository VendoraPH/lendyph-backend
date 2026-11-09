<?php

namespace App\Http\Requests\ShareCapital;

use App\Services\ShareCapitalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessAutoCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('auto_credit:process');
    }

    /**
     * The settlement account the run's credits came through, stored on each
     * row and posted to: `bank_transfer` unless picked, as auto-pay settles
     * through the bank. `reference_number` is optional, whatever the method.
     */
    public function rules(): array
    {
        return [
            'method' => ['sometimes', Rule::in(ShareCapitalService::AUTO_CREDIT_METHODS)],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'bank_transfer']);
        }
    }
}
