<?php

namespace App\Http\Requests\Amla;

use App\Models\AmlaIdVerification;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /amla/cdd/{members|walk-ins}/{id}/id-verifications: a person's record
 * of checking an ID. Needs `amla:cdd`. A member's names one of their own
 * `valid_id` documents (`document_id`); a walk-in's reads the ID on its
 * record. There is no outside-provider method: v1 has none, and nothing may
 * claim one checked an ID (task §10).
 */
class StoreAmlaIdVerificationRequest extends AmlaFormRequest
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
            'document_id' => ['nullable', 'integer', 'min:1'],
            'method' => ['required', 'string', Rule::in(array_keys(AmlaIdVerification::METHODS))],
            'result' => ['required', 'string', Rule::in(array_keys(AmlaIdVerification::RESULTS))],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('method') === 'copy_only' && $this->input('result') === 'verified') {
                $validator->errors()->add('result', 'A photocopy or scan alone doesn\'t verify an ID. See the original or a certified true copy.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'method.in' => 'Pick how the ID was checked: the original seen in person, a certified true copy, or a photocopy or scan only.',
            'result.in' => 'Pick whether the ID was verified or could not be verified.',
            'expires_on.date_format' => 'Enter the ID\'s expiry as a real date, YYYY-MM-DD.',
        ];
    }
}
