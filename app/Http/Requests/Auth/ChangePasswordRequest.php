<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],

            /*
             * `different:current_password` is load-bearing, not tidiness.
             *
             * This endpoint is the ONLY way out of `must_change_password`, and
             * the reason accounts are put into that state is that their bcrypt
             * hash leaked out of `audit_logs` and has to be assumed cracked.
             * Without this rule a user clears the flag by typing the exposed
             * password into both fields: the hash is recomputed, the flag is
             * cleared, a `password_changed` row is written, and the credential
             * an attacker already holds is still live. Across ten deployments
             * `users:require-password-change` would report a completed rotation
             * while rotating nothing — the worst possible outcome, because it
             * also retires the suspicion that would have caught it.
             *
             * Deliberately NOT accompanied by `uncompromised()` here. That rule
             * calls the Have I Been Pwned range API on every password change,
             * which puts an outbound network dependency in front of the one
             * endpoint a locked-out account can still reach; if it is slow or
             * unreachable the whole fleet is stuck mid-remediation. Raising the
             * length floor and adding a breach check is a policy decision worth
             * having separately, on its own merits, not something to smuggle in
             * on the back of an incident.
             */
            'new_password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(8)],
        ];
    }
}
