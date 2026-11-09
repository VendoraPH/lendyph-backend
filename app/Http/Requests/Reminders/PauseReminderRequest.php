<?php

namespace App\Http\Requests\Reminders;

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
            'until' => ['nullable', 'date', 'after:now'],
        ];
    }
}
