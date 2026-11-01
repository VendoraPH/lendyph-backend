<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /loans/{loan}/extension-preview: authorised exactly as the extension it
 * previews (ExtendLoanRequest), and `interest_option` is required for the
 * same reason, since 'pay' and 'defer' quote different figures.
 */
class ExtensionPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:extend');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'interest_option' => ['required', 'in:pay,defer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'interest_option.required' => 'Choose whether to pay or defer the outstanding interest.',
        ];
    }
}
