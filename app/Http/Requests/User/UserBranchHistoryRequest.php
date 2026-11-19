<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /users/{user}/branch-history (Phase 6 MB-BE6). Needs `users:view` and
 * the caller's reach (UserReach): a user outside it answers the same 404 as
 * one that doesn't exist, like the rest of user management.
 */
class UserBranchHistoryRequest extends FormRequest
{
    use MasksUserExistence;

    public function authorize(): bool
    {
        return $this->user()->can('users:view');
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');
                $actor = $this->user();

                if ($target instanceof User && $actor instanceof User && ! $this->callerReachesTarget($actor, $target)) {
                    $this->denyAsMissingUser();
                }
            },
        ];
    }
}
