<?php

namespace App\Http\Requests\Savings\AutoCredit;

use App\Models\SavingsAutoCreditInstruction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `GET /savings/auto-credit/instructions` (savings:view): filters and paging. */
class ListAutoCreditInstructionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'account_id' => ['nullable', 'integer', 'min:1'],
            'borrower_id' => ['nullable', 'integer', 'min:1'],
            'schedule' => ['nullable', Rule::in(SavingsAutoCreditInstruction::SCHEDULES)],
            'active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'account_id.integer' => 'Choose the savings account from the list.',
            'account_id.min' => 'Choose the savings account from the list.',
            'borrower_id.integer' => 'Choose the member from the list.',
            'borrower_id.min' => 'Choose the member from the list.',
            'schedule.in' => 'Choose the 15th, the 30th (month end) or both.',
            'active.boolean' => 'Active is yes or no.',
            'page.integer' => 'The page must be a whole number from 1.',
            'page.min' => 'The page must be a whole number from 1.',
            'per_page.integer' => 'Rows per page must be a whole number from 1.',
            'per_page.min' => 'Rows per page must be a whole number from 1.',
        ];
    }
}
