<?php

namespace App\Http\Requests\PenaltyConfig;

use App\Models\PenaltyConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /penalty-configs/{id}/status. Activating checks the products under a
 * lock in PenaltyConfigService (at least one, none in another active
 * configuration).
 */
class UpdatePenaltyConfigStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('penalties:update');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(PenaltyConfig::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Choose active or inactive.',
            'status.in' => 'The status must be active or inactive.',
        ];
    }
}
