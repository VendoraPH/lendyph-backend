<?php

namespace App\Http\Requests\Linda;

use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /linda/settings` (`linda:settings` and `branches:view_all`: the
 * setting is company-wide). `model` must be one of the price table's models.
 */
class UpdateLindaSettingsRequest extends FormRequest
{
    public const COMPANY_WIDE_REFUSAL = 'Linda settings apply to every branch, so changing them needs the Linda settings permission and access to all branches.';

    public function authorize(): bool
    {
        return self::allows($this->user());
    }

    public static function allows(mixed $user): bool
    {
        return $user !== null && $user->can('linda:settings') && $user->can(BranchAccess::VIEW_ALL);
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
            'model' => ['required', 'string', Rule::in(array_keys((array) config('linda.models')))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'model.in' => 'Choose one of the models Linda offers.',
        ];
    }
}
