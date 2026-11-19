<?php

namespace App\Http\Requests\LoanOrigination;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /loan-origination/applications/{application}/assessment: the
 * officer's findings and recommendations, saved as a new revision on the
 * current figures. Each is optional: left out it keeps its text, null clears
 * it.
 */
class SaveAssessmentNotesRequest extends FormRequest
{
    public const REFUSAL = 'Saving assessment notes needs the permission to assess online applications.';

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('loan_origination:assess');
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(self::REFUSAL);
    }

    public function rules(): array
    {
        return [
            'officer_findings' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'recommendations' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'officer_findings.max' => 'Keep the findings to 5,000 characters or fewer.',
            'recommendations.max' => 'Keep the recommendations to 5,000 characters or fewer.',
            'officer_findings.string' => 'Enter the findings as text.',
            'recommendations.string' => 'Enter the recommendations as text.',
        ];
    }

    /** @return array{officer_findings?: ?string, recommendations?: ?string} */
    public function notes(): array
    {
        $notes = [];

        foreach (['officer_findings', 'recommendations'] as $field) {
            if ($this->exists($field)) {
                $value = $this->validated($field);
                $notes[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        return $notes;
    }
}
