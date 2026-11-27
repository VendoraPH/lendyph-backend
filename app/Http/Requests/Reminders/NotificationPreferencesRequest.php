<?php

namespace App\Http\Requests\Reminders;

use App\Models\BorrowerNotificationPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member's reminder switches: the four fields the card edits, nothing else
 * (verified and invalid contact values are the engine's and staff's own).
 * Needs `reminders:view` and `borrowers:update`, the card's own gate.
 */
class NotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('reminders:view') && $user->can('borrowers:update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sms_enabled' => ['sometimes', 'boolean'],
            'email_enabled' => ['sometimes', 'boolean'],
            'preferred_channel' => ['sometimes', Rule::in(BorrowerNotificationPreference::CHANNELS)],
            'language' => ['sometimes', 'string', 'alpha_dash', 'max:8'],
        ];
    }
}
