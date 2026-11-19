<?php

namespace App\Http\Requests\Amla;

use App\Services\Amla\Foundation\AmlaAccess;

/**
 * POST /amla/orders/{id}/release: lift an order and every hold it placed,
 * with the reason and the lifting order's reference. Needs `amla:sanctions`
 * and head-office access.
 */
class ReleaseAmlaOrderRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::SANCTIONS];
    }

    protected function headOffice(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'release_reference' => ['required', 'string', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the order is released.',
            'release_reference.required' => 'Give the reference of the order or resolution that lifts it.',
        ];
    }
}
