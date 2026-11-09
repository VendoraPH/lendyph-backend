<?php

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\Money;
use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /accounting/allowance/postings: `as_of` (today or earlier, in
 * Manila) and `expected_adjustment`, the preview's `totals.adjustment` in
 * signed centavos. Needs `accounting:allowance` and `branches:view_all`.
 */
class PostAllowanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('accounting:allowance') && $user->can(BranchAccess::VIEW_ALL);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ceiling = Money::maxCentavos();

        return [
            'as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'expected_adjustment' => ['required', 'integer', 'min:-'.$ceiling, 'max:'.$ceiling],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'as_of.before_or_equal' => 'The allowance can\'t be posted for a date in the future.',
        ];
    }
}
