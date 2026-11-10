<?php

namespace App\Http\Requests\Cda\Registers;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Services\Cda\CdaAccess;

/**
 * POST /cda/submissions/{id}/proof (`cda:submit`), multipart: the proof of a
 * filing (a receipt, an acknowledgment or a stamped copy) and optional notes.
 */
class CdaSubmissionProofRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::SUBMIT;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => CdaUploadRules::file(),
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...CdaUploadRules::messages('file'), 'notes.max' => 'The notes can be at most 2,000 characters.'];
    }
}
