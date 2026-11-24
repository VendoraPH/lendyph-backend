<?php

namespace App\Http\Requests\Reminders;

use App\Http\Requests\Concerns\DateInputMessages;

/**
 * Pausing reminders for a target (frontend `PauseRequest`): a reason, and an
 * optional end in the future.
 */
class PauseReminderRequest extends ResumeReminderRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'reason' => ['required', 'string', 'max:255'],
            'until' => ['nullable', 'date', 'date_format:'.DateInputMessages::DATE_TIME_FORMATS, 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), ...DateInputMessages::dateTime(['until' => 'resume date'])];
    }
}
