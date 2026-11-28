<?php

namespace App\Http\Requests\Cic\Submissions;

use App\Services\Cic\CicAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cic/submissions/{id}/encrypt {confirm: true}: the explicit
 * confirmation after the pre-encryption review (prompt §12). Needs
 * `cic:submit` and `branches:view_all`, checked before validation.
 */
class EncryptCicSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CicAccess::class)->authorize($this->user(), CicAccess::SUBMIT);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm.required' => 'Confirm that you reviewed the file before it is encrypted.',
            'confirm.accepted' => 'Confirm that you reviewed the file before it is encrypted.',
        ];
    }
}
