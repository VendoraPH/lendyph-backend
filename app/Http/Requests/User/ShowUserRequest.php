<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorisation for `GET /api/users/{user}`.
 *
 * The check used to sit in `UserController::show()` as `$this->authorize()`,
 * which cannot mask the target's existence: by the time the controller runs,
 * route-model binding has already answered 404 for an id that is not there,
 * so a 403 from inside the action says "this one IS there". Moving it into a
 * FormRequest puts the decision back in front of that fork, where
 * `failedAuthorization()` can answer with the binding's own 404 instead.
 */
class ShowUserRequest extends FormRequest
{
    use MasksUserExistence;

    public function authorize(): bool
    {
        return $this->user()->can('users:view');
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
