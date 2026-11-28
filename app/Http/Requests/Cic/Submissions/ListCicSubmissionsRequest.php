<?php

namespace App\Http\Requests\Cic\Submissions;

use App\Models\CicSubmission;
use App\Services\Cic\CicAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /cic/submissions ?status=&reference_date=&submission_type=&page=&per_page=.
 * Needs `cic:view` and `branches:view_all`, checked before validation.
 */
class ListCicSubmissionsRequest extends FormRequest
{
    public const STATUSES = [
        CicSubmission::STATUS_GENERATED, CicSubmission::STATUS_ENCRYPTED, CicSubmission::STATUS_SUBMITTED,
        CicSubmission::STATUS_ACCEPTED, CicSubmission::STATUS_REJECTED,
    ];

    public function authorize(): bool
    {
        app(CicAccess::class)->authorize($this->user(), CicAccess::VIEW);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'reference_date' => ['sometimes', 'date_format:Y-m-d'],
            'submission_type' => ['sometimes', 'integer', 'in:0,1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in' => 'The status is generated, encrypted, submitted, accepted or rejected.',
            'status.string' => 'The status is generated, encrypted, submitted, accepted or rejected.',
            'reference_date.date_format' => 'The reference date is a date (YYYY-MM-DD).',
            'submission_type.integer' => 'The submission type is 0 (standard reporting) or 1 (correction).',
            'submission_type.in' => 'The submission type is 0 (standard reporting) or 1 (correction).',
            'page.integer' => 'The page is a whole number from 1.',
            'page.min' => 'The page is a whole number from 1.',
            'per_page.integer' => 'Rows per page is a whole number from 1 to 100.',
            'per_page.min' => 'Rows per page is a whole number from 1 to 100.',
            'per_page.max' => 'Rows per page is a whole number from 1 to 100.',
        ];
    }
}
