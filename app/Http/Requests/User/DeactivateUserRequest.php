<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorisation for `PATCH /api/users/{user}/deactivate`.
 *
 * Moved out of `UserController::deactivate()` for the reason given on
 * ShowUserRequest: `$this->authorize()` runs after route-model binding has
 * already distinguished a live id from a dead one.
 *
 * The super_admin guard stays in the controller. It is not an authorisation
 * decision but a 422 about the TARGET, and it is only reachable by a caller
 * who already holds `users:delete` — so it tells that caller nothing they
 * could not learn from the 200 they are entitled to.
 */
class DeactivateUserRequest extends FormRequest
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
