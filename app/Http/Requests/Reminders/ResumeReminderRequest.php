<?php

namespace App\Http\Requests\Reminders;

use App\Models\ReminderPause;
use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resuming reminders for a target. All reminders (`global`) needs
 * `branches:view_all`; another branch's member or loan fails like a missing
 * one.
 */
class ResumeReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('reminders:pause')) {
            return false;
        }

        return $this->input('scope') !== 'global' || $user->can(BranchAccess::VIEW_ALL);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $access = app(BranchAccess::class);

        return [
            'scope' => ['required', Rule::in(ReminderPause::SCOPES)],
            'borrower_id' => ['nullable', 'required_if:scope,borrower', 'integer', $access->constrainExists(Rule::exists('borrowers', 'id'), $this->user())],
            'loan_id' => ['nullable', 'required_if:scope,loan', 'integer', $access->constrainExists(Rule::exists('loans', 'id'), $this->user())],
        ];
    }
}
