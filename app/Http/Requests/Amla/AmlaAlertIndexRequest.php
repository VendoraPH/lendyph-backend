<?php

namespace App\Http\Requests\Amla;

use App\Models\AmlaAlert;
use App\Services\Amla\Detection\DetectionRules;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Validation\Rule;

/** Filters on the AMLA alerts list: `amla:alerts`, branch-scoped (AMLA-B3). */
class AmlaAlertIndexRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::ALERTS];
    }

    protected function headOffice(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(array_keys(AmlaAlert::STATUS_WORDS))],
            'rule' => ['sometimes', 'string', Rule::in(array_keys(app(DetectionRules::class)->all()))],
            'priority' => ['sometimes', 'string', Rule::in(DetectionRules::PRIORITIES)],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['sometimes', 'nullable', 'string', 'max:40'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'rule.in' => 'Pick a rule from A to L.',
            'priority.in' => 'Pick low, medium, high or critical.',
            'to.after_or_equal' => 'The end date can\'t be before the start date.',
        ];
    }
}
