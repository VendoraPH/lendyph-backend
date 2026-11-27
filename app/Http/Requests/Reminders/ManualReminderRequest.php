<?php

namespace App\Http\Requests\Reminders;

use App\Models\ReminderTemplate;
use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual reminder (frontend `ManualReminderRequest`). Another branch's
 * loan fails like a missing one.
 */
class ManualReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('reminders:send');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'loan_id' => ['required', 'integer', app(BranchAccess::class)->constrainExists(Rule::exists('loans', 'id'), $this->user())],
            'channel' => ['required', Rule::in(['sms', 'email', 'both'])],
            'template_type' => ['required', Rule::in(ReminderTemplate::TYPES)],
        ];
    }
}
