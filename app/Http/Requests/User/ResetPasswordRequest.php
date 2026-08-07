<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users:reset_password');
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * A password reset is account takeover by another name: the handler also
     * revokes the target's tokens, so the rightful owner is signed out while
     * the caller knows the new password. `admin` holds `users:reset_password`
     * along with every other permission, so without this a client admin can
     * simply log in as the platform's super_admin and pick up the Gate bypass
     * and the restructure dual-control exemption — no role ever changes, and
     * there is nothing for the role guards to catch.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');
                $actor = $this->user();

                if (! $target instanceof User || ! $actor instanceof User) {
                    return;
                }

                if (! $actor->canManageAccount($target)) {
                    $validator->errors()->add(
                        'user',
                        'Only a super_admin can reset the password of a super_admin.',
                    );
                }
            },
        ];
    }
}
