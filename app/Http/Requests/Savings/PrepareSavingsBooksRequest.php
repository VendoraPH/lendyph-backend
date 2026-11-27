<?php

namespace App\Http\Requests\Savings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/settings/prepare-books` (savings:settings and
 * branches:view_all): set up savings in the books. The chart of accounts is
 * company-wide, so this is a company-wide write. It takes no fields.
 */
class PrepareSavingsBooksRequest extends FormRequest
{
    use AuthorizesCompanyWideSavings;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
