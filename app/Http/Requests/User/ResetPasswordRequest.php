<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users:reset_password');
    }

    /**
     * Answer an unauthorised caller with the SAME 404 a missing id produces.
     *
     * `{user}` is resolved by implicit route-model binding, which runs before
     * this request is authorised. That split the outcomes for a caller without
     * `users:reset_password` into two distinguishable answers:
     *
     *   POST /api/users/4/reset-password      -> 403 "This action is unauthorized."
     *   POST /api/users/99999/reset-password  -> 404 "No query results for model [App\Models\User] 99999"
     *
     * which is a user-existence oracle for anyone holding any valid token. The
     * ids are sequential, so walking them enumerates the organisation's entire
     * staff list — how many accounts exist and which ids are live — from an
     * endpoint the caller is not allowed to use at all.
     *
     * Throwing the binding's own exception rather than `abort(404)` is what
     * makes the two indistinguishable: the handler turns ModelNotFoundException
     * into a NotFoundHttpException carrying this exact message, so the status,
     * the body and the shape all match byte for byte. An `abort(404)` here
     * would answer `{"message": ""}` and simply move the oracle.
     *
     * A caller who DOES hold the permission still gets the honest 404 from the
     * binding — they are allowed to know an id does not exist.
     */
    protected function failedAuthorization(): never
    {
        throw (new ModelNotFoundException)->setModel(
            User::class,
            array_filter([$this->route('user')?->getKey()]),
        );
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
