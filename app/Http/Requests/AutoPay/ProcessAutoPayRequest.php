<?php

namespace App\Http\Requests\AutoPay;

use App\Http\Requests\Concerns\DateInputMessages;
use Illuminate\Foundation\Http\FormRequest;

class ProcessAutoPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('auto_pay:process');
    }

    public function rules(): array
    {
        return [
            'product_ids' => ['sometimes', 'array'],
            'product_ids.*' => ['integer', 'exists:loan_products,id'],
            'date_from' => ['required', 'date', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'include_schedule_ids' => ['sometimes', 'array'],
            'include_schedule_ids.*' => ['integer', 'exists:amortization_schedules,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DateInputMessages::calendar(['date_from' => 'start date', 'date_to' => 'end date']);
    }
}
