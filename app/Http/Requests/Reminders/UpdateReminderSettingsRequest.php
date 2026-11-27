<?php

namespace App\Http\Requests\Reminders;

use App\Models\ReminderSetting;
use App\Services\BranchAccess;
use App\Services\Reminders\ContactHours;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /reminders/settings` (frontend `ReminderSettingsUpdate`). Every part is
 * optional; a part that is sent is checked whole. The engine's limits: Asia/
 * Manila only, contact hours within 06:00-22:00 with the start first, 1-3
 * attempts. `test_mode` is read-only and ignored. A branch outside the
 * caller's branches needs `branches:view_all` (403), and so does changing
 * any company-wide field (the controller compares them with the stored
 * row, so the whole form saved unchanged is accepted). Credentials are never
 * echoed in an error.
 */
class UpdateReminderSettingsRequest extends FormRequest
{
    public const SMS_PROVIDERS = ['semaphore', 'm360', 'twilio', 'other'];

    public const EMAIL_PROVIDERS = ['smtp', 'mailgun', 'ses', 'postmark', 'other'];

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('reminders:settings')) {
            return false;
        }

        $access = app(BranchAccess::class);

        // The id as the `integer` rule will accept it: JSON `true` reads as
        // 1, so it is checked as branch 1, never skipped.
        foreach ((array) $this->input('branches', []) as $branch) {
            $branchId = is_array($branch) ? filter_var($branch['branch_id'] ?? null, FILTER_VALIDATE_INT) : false;

            if ($branchId !== false && ! $access->canSee($user, $branchId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', Rule::in([ReminderSetting::TIMEZONE])],
            'default_send_time' => ['sometimes', 'date_format:H:i'],
            'channels' => ['sometimes', 'array'],
            'channels.sms' => ['required_with:channels', 'boolean'],
            'channels.email' => ['required_with:channels', 'boolean'],
            'contact_hours' => ['sometimes', 'array'],
            'contact_hours.start' => ['required_with:contact_hours', 'date_format:H:i'],
            'contact_hours.end' => ['required_with:contact_hours', 'date_format:H:i'],
            'retry' => ['sometimes', 'array'],
            'retry.max_attempts' => ['required_with:retry', 'integer', 'between:1,'.ReminderSetting::MAX_ATTEMPTS],
            'retry.delay_minutes' => ['required_with:retry', 'integer', 'between:1,1440'],
            'sms_provider' => ['sometimes', 'array'],
            'sms_provider.provider' => ['nullable', Rule::in(self::SMS_PROVIDERS)],
            'sms_provider.sender_id' => ['nullable', 'string', 'max:32'],
            'sms_provider.cost_per_segment' => ['nullable', 'numeric', 'between:0,1000'],
            'sms_provider.api_key' => ['nullable', 'string', 'max:2000'],
            'sms_provider.api_secret' => ['nullable', 'string', 'max:2000'],
            'email_provider' => ['sometimes', 'array'],
            'email_provider.provider' => ['nullable', Rule::in(self::EMAIL_PROVIDERS)],
            'email_provider.sender_name' => ['nullable', 'string', 'max:120'],
            'email_provider.sender_email' => ['nullable', 'email', 'max:191'],
            'email_provider.reply_to' => ['nullable', 'email', 'max:191'],
            'email_provider.api_key' => ['nullable', 'string', 'max:2000'],
            'payment_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'branches' => ['sometimes', 'array'],
            'branches.*.branch_id' => ['required', 'integer', 'distinct', app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user())],
            'branches.*.use_company_default' => ['required', 'boolean'],
            'branches.*.sender_name' => ['nullable', 'string', 'max:120'],
            'branches.*.contact_phone' => ['nullable', 'string', 'max:50'],
            'branches.*.contact_email' => ['nullable', 'email', 'max:191'],
            'branches.*.payment_instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('contact_hours') || $validator->errors()->hasAny(['contact_hours.start', 'contact_hours.end'])) {
                return;
            }

            $start = self::minutes((string) $this->input('contact_hours.start'));
            $end = self::minutes((string) $this->input('contact_hours.end'));

            if ($start < ContactHours::FLOOR) {
                $validator->errors()->add('contact_hours.start', 'Contact hours can start no earlier than 06:00.');
            }

            if ($end > ContactHours::CEILING) {
                $validator->errors()->add('contact_hours.end', 'Contact hours can end no later than 22:00.');
            } elseif ($end <= $start) {
                $validator->errors()->add('contact_hours.end', 'Contact hours must end after they start.');
            }
        }];
    }

    private static function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time) + [0, 0]);

        return $hour * 60 + $minute;
    }
}
