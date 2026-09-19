<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Authorisation for `PATCH /api/users/{user}/deactivate`.
 *
 * Moved out of `UserController::deactivate()` for the reason given on
 * ShowUserRequest: `$this->authorize()` runs after route-model binding has
 * already distinguished a live id from a dead one.
 *
 * The super_admin guard used to stay in the controller, on the reasoning that
 * a 422 about the TARGET "tells a caller who already holds `users:delete`
 * nothing they could not learn from the 200 they are entitled to". That was
 * wrong in one specific way: the 200 here is a bare message, so it reveals
 * that the id is live and nothing else, while the 422 additionally named the
 * account as the platform's super_admin. Identifying that account is the
 * single most useful thing this route could leak.
 *
 * It now lives in `after()` alongside the identical guards on update and
 * reset-password, and answers the same 404 they do. Keeping it in the
 * controller would have left the oracle intact one URL over, which is the
 * failure mode `MasksUserExistence` is written against.
 */
class DeactivateUserRequest extends FormRequest
{
    use MasksUserExistence;

    public function authorize(): bool
    {
        return $this->user()->can('users:delete');
    }

    /**
     * Nothing to validate — this request exists for its authorisation and the
     * hook below.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Deactivation revokes the target's tokens on their next request, so a
     * client admin could otherwise lock the platform team out of their own
     * deployment. Same boundary as editing or resetting that account, and now
     * the same 404.
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
