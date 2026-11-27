<?php

namespace App\Http\Requests\Accounting;

use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Closing a calendar year (POST /accounting/year-closes). An organisation-wide
 * action, so it needs `branches:view_all` as well as `accounting:close`.
 */
class CloseYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounting:close') && $this->user()->can(BranchAccess::VIEW_ALL);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:1900,9999'],
        ];
    }
}
