<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorisation for `PATCH /api/users/{user}/reactivate`.
 *
 * Moved out of `UserController::reactivate()` for the reason given on
 * ShowUserRequest: `$this->authorize()` runs after route-model binding has
 * already distinguished a live id from a dead one.
 *
 * Same permission as deactivation, `users:delete`. Unlike deactivation there
 * is no super_admin guard on the target — restoring access takes nothing away
 * from the account being acted on — and this change does not add one.
 */
class ReactivateUserRequest extends FormRequest
{
    use MasksUserExistence;

    public function authorize(): bool
    {
        return $this->user()->can('users:delete');
    }

    /**
     * Nothing to validate — this request exists for its authorisation.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
