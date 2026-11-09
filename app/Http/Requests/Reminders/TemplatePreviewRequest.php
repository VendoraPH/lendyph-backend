<?php

namespace App\Http\Requests\Reminders;

use App\Models\ReminderRule;
use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A template preview: the text to render and, optionally, the loan to render
 * it against (another branch's loan fails like a missing one).
 */
class TemplatePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('reminders:settings');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(ReminderRule::CHANNELS)],
            'subject' => ['nullable', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:5000'],
            'loan_id' => ['nullable', 'integer', app(BranchAccess::class)->constrainExists(Rule::exists('loans', 'id'), $this->user())],
        ];
    }
}
