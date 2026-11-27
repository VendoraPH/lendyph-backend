<?php

namespace App\Http\Requests\Savings\AutoCredit;

use App\Http\Requests\Savings\SavingsRules;
use App\Models\SavingsAutoCreditInstruction;
use Illuminate\Validation\Rule;

/**
 * The fields of a savings auto-credit instruction (gap round S3), shared by
 * create (every field required but `active` and `remarks`) and edit (every
 * field optional; the account never changes).
 */
trait AutoCreditInstructionRules
{
    /** @return array<string, mixed> */
    protected function instructionRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'amount' => [$presence, ...SavingsRules::money('amount'), 'gt:0'],
            'schedule' => [$presence, 'string', Rule::in(SavingsAutoCreditInstruction::SCHEDULES)],
            'active' => ['sometimes', 'boolean'],
            'authorised_on' => [$presence, 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'authorised_by' => [$presence, 'string', 'max:120'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    protected function instructionMessages(): array
    {
        return [
            ...SavingsRules::messages(['amount' => 'amount']),
            'amount.required' => 'Enter the amount to credit each cycle.',
            'amount.gt' => 'The amount must be above ₱0.00.',
            'schedule.required' => 'Choose the 15th, the 30th (month end) or both.',
            'schedule.string' => 'Choose the 15th, the 30th (month end) or both.',
            'schedule.in' => 'Choose the 15th, the 30th (month end) or both.',
            'active.boolean' => 'Active is yes or no.',
            'authorised_on.required' => 'Enter the date the member authorised it.',
            'authorised_on.date_format' => 'The date it was authorised must be written as YYYY-MM-DD.',
            'authorised_on.before_or_equal' => 'The date it was authorised can\'t be after today.',
            'authorised_by.required' => 'Enter who authorised the instruction.',
            'authorised_by.string' => 'Enter who authorised the instruction.',
            'authorised_by.max' => 'Who authorised it can be at most 120 characters.',
            'remarks.string' => 'The remarks must be text.',
            'remarks.max' => 'The remarks can be at most 500 characters.',
        ];
    }
}
