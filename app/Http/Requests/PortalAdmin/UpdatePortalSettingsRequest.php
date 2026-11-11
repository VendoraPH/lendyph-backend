<?php

namespace App\Http\Requests\PortalAdmin;

use App\Models\PortalSetting;
use App\Services\BranchAccess;
use App\Services\Portal\Auth\PasswordRules;
use App\Services\Portal\PortalSettings;
use App\Services\Portal\PortalTerms;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /portal-admin/settings` (member_portal:settings and branches:view_all:
 * the settings are company-wide). Every field is optional and kept within its
 * range. OTP can't be switched on while SMS reaches nobody, and the portal
 * can't be switched on before terms are published.
 */
class UpdatePortalSettingsRequest extends FormRequest
{
    public const COMPANY_WIDE_REFUSAL = 'Member Portal settings apply to every branch, so changing them needs the portal settings permission and access to all branches.';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('member_portal:settings') && $user->can(BranchAccess::VIEW_ALL);
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(self::COMPANY_WIDE_REFUSAL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'portal_name' => ['sometimes', 'required', 'string', 'max:100'],
            'welcome_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'support_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'session_minutes' => ['sometimes', 'required', 'integer', 'between:15,240'],
            'idle_minutes' => ['sometimes', 'required', 'integer', 'between:5,60'],
            'max_login_attempts' => ['sometimes', 'required', 'integer', 'between:3,10'],
            'lockout_minutes' => ['sometimes', 'required', 'integer', 'between:5,1440'],
            'hard_lock_after' => ['sometimes', 'required', 'integer', 'between:2,10'],
            'password_min_length' => ['sometimes', 'required', 'integer', 'between:'.PasswordRules::ABSOLUTE_MIN.','.PortalSettings::PASSWORD_MAX_LENGTH],
            'activation_code_hours' => ['sometimes', 'required', 'integer', 'between:24,168'],
            'reset_code_hours' => ['sometimes', 'required', 'integer', 'between:1,72'],
            'otp_required' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'required', 'array'],
            'features.*' => ['boolean'],
            'dashboard_cards' => ['sometimes', 'array'],
            'dashboard_cards.*' => ['string', 'distinct', Rule::in(PortalSetting::DASHBOARD_CARDS)],
            'withdrawal_methods' => ['sometimes', 'required', 'array', 'min:1'],
            'withdrawal_methods.*' => ['string', 'distinct', Rule::in(PortalSetting::WITHDRAWAL_METHODS)],
            'withdrawal_hold_days' => ['sometimes', 'required', 'integer', 'between:1,30'],
            'request_expiry_days' => ['sometimes', 'required', 'integer', 'between:1,90'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $settings = app(PortalSettings::class);
            $stored = $settings->current();

            if ($this->boolean('otp_required') && ! $stored->otp_required && ($reason = $settings->otpBlockedReason()) !== null) {
                $validator->errors()->add('otp_required', $reason);
            }

            if ($this->boolean('enabled') && ! $stored->enabled && app(PortalTerms::class)->current() === null) {
                $validator->errors()->add('enabled', 'Publish the portal terms before turning the portal on.');
            }

            $unknown = array_diff(array_keys((array) $this->input('features', [])), PortalSetting::FEATURES);

            if ($this->has('features') && $unknown !== []) {
                $validator->errors()->add('features', 'Unknown portal features: '.implode(', ', $unknown).'.');
            }

            if (! $validator->errors()->hasAny(['session_minutes', 'idle_minutes'])) {
                $session = (int) $this->input('session_minutes', $stored->session_minutes);
                $idle = (int) $this->input('idle_minutes', $stored->idle_minutes);

                if ($idle > $session) {
                    $validator->errors()->add('idle_minutes', 'The idle timeout can\'t be longer than the session ('.$session.' minutes).');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'session_minutes.between' => 'A session lasts between 15 and 240 minutes.',
            'idle_minutes.between' => 'The idle timeout is between 5 and 60 minutes.',
            'activation_code_hours.between' => 'An activation code lasts between 24 and 168 hours.',
            'reset_code_hours.between' => 'A reset code lasts between 1 and 72 hours.',
            'withdrawal_methods.min' => 'Keep at least one payout method.',
        ];
    }
}
