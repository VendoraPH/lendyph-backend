<?php

namespace App\Http\Requests\Amla;

use App\Services\Amla\Foundation\AmlaAccess;

/**
 * GET /amla/calendar: one year's working-day calendar. Needs `amla:view`
 * only: it holds public holidays and suspensions, nothing about members.
 */
class AmlaCalendarRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::VIEW];
    }

    protected function headOffice(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['year' => ['sometimes', 'integer', 'min:2000', 'max:2100']];
    }
}
