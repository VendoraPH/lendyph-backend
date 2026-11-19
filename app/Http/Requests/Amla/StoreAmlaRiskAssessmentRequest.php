<?php

namespace App\Http\Requests\Amla;

use App\Models\AmlaRiskAssessment;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Validation\Rule;

/**
 * POST /amla/cdd/{members|walk-ins}/{id}/risk-assessments: assess now under
 * the current rules, optionally overriding the level with a reason. Needs
 * `amla:cdd`.
 */
class StoreAmlaRiskAssessmentRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::CDD];
    }

    protected function headOffice(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'override_level' => ['nullable', 'string', Rule::in(array_keys(AmlaRiskAssessment::LEVELS))],
            'override_reason' => ['nullable', 'string', 'max:1000', 'required_with:override_level'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'override_level.in' => 'Pick Low, Medium or High.',
            'override_reason.required_with' => 'Say why the risk level is overridden.',
        ];
    }
}
