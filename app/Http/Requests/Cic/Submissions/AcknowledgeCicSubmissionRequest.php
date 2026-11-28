<?php

namespace App\Http\Requests\Cic\Submissions;

use App\Models\CicSubmission;
use App\Services\Cic\CicAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cic/submissions/{id}/acknowledgement {status: accepted|rejected,
 * notes}: CIC's answer to a submitted file. Needs `cic:review` and
 * `branches:view_all`, checked before validation. A rejection needs notes
 * (CIC's reason); an acceptance may leave them out.
 */
class AcknowledgeCicSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CicAccess::class)->authorize($this->user(), CicAccess::REVIEW);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.CicSubmission::STATUS_ACCEPTED.','.CicSubmission::STATUS_REJECTED],
            'notes' => ['required_if:status,'.CicSubmission::STATUS_REJECTED, 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.required' => 'Choose whether CIC accepted or rejected the file.',
            'status.string' => 'Choose whether CIC accepted or rejected the file.',
            'status.in' => 'CIC\'s answer is accepted or rejected.',
            'notes.required_if' => 'Give CIC\'s reason for rejecting the file.',
            'notes.string' => 'The notes are text.',
            'notes.max' => 'The notes take up to 2,000 characters.',
        ];
    }
}
