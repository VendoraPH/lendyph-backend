<?php

namespace App\Http\Requests\PenaltyConfig;

use App\Models\PenaltyConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPenaltyConfigsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('penalties:view');
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(PenaltyConfig::STATUSES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'per_page.max' => 'At most 100 configurations can be listed per page.',
            'status.in' => 'The status must be active or inactive.',
        ];
    }
}
