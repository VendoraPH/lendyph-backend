<?php

namespace App\Http\Requests\Amla;

use App\Services\Amla\Foundation\AmlaAccess;

/**
 * Approve or withdraw an AMLA change request, with an optional note. Needs
 * `amla:settings` and head-office access; who may act (never the maker for
 * an approval, only the maker for a withdrawal) is checked by ChangeRequests.
 */
class DecideAmlaChangeRequestRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::SETTINGS];
    }

    protected function headOffice(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:1000']];
    }
}
