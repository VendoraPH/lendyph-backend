<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ResetPasswordRequest extends FormRequest
{
    /**
     * A caller without `users:reset_password` is answered with the SAME 404 a
     * missing id produces, rather than a 403 that confirms the account exists.
     * The rationale — and why `abort(404)` will not do — lives on the trait.
     */
    use MasksUserExistence;

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
     *
     * Refused as the binding's own 404, not a 422. The message this used to
     * carry — "only a super_admin can reset the password of a super_admin" —
     * identified the platform account outright, which is a better answer than
     * the enumeration this whole family exists to prevent: one request per id
     * and the reply says not just "this id is live" but "this id is the one
     * worth attacking". Masking it here and nowhere else would only move that
     * to the next URL, so `UpdateUserRequest` and `DeactivateUserRequest` do
     * the same. See `MasksUserExistence::denyAsMissingUser()`.
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
                    $this->denyAsMissingUser();
                }
            },
        ];
    }
}
