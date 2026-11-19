<?php

namespace App\Http\Requests\Amla;

use App\Services\Amla\Foundation\AmlaAccess;

/**
 * Approve or withdraw an order's release, with an optional note. Needs
 * `amla:sanctions` and head-office access; who may act (never the asker for
 * an approval, only the asker for a withdrawal) is checked by Orders.
 */
class DecideAmlaOrderReleaseRequest extends AmlaFormRequest
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
        return ['note' => ['nullable', 'string', 'max:1000']];
    }
}
