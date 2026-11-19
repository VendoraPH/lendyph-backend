<?php

namespace App\Http\Requests\Amla;

use App\Models\AmlaReview;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Validation\Rule;

/**
 * POST /amla/cdd/{members|walk-ins}/{id}/reviews: a periodic, enhanced due
 * diligence or change-of-circumstance review. Needs `amla:cdd`. An EDD review
 * needs notes saying what was checked.
 */
class StoreAmlaReviewRequest extends AmlaFormRequest
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
            'kind' => ['required', 'string', Rule::in(array_keys(AmlaReview::KINDS))],
            'outcome' => ['required', 'string', Rule::in(array_keys(AmlaReview::OUTCOMES))],
            'notes' => ['nullable', 'string', 'max:2000', 'required_if:kind,edd'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.in' => 'Pick a periodic review, enhanced due diligence, or a change in circumstances.',
            'outcome.in' => 'Pick no change, profile updated, or escalated for investigation.',
            'notes.required_if' => 'Say what enhanced due diligence checked.',
        ];
    }
}
