<?php

namespace App\Http\Requests\Accounting;

use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /accounting/opening-balances/finalise: posts the draft as it stood
 * at `edit_version`. Needs `accounting:settings` and `journals:post`, and
 * `branches:view_all` like every opening balances route.
 */
class FinaliseOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('accounting:settings') && $user->can('journals:post') && $user->can(BranchAccess::VIEW_ALL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'edit_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
