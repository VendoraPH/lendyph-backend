<?php

namespace App\Http\Requests\Amla;

use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An AMLA request: the caller needs one of the request's `amla:*` keys, plus
 * `branches:view_all` when the request covers the whole cooperative
 * (AmlaAccess). Checked before validation, so a caller without them gets a
 * 403 in words, logged to the AMLA log, never a list of field errors.
 */
abstract class AmlaFormRequest extends FormRequest
{
    /** @return list<string> the keys, any one of which opens the request */
    abstract protected function permissions(): array;

    /** Whether the request also needs head-office access. */
    abstract protected function headOffice(): bool;

    public function authorize(): bool
    {
        $access = app(AmlaAccess::class);

        $this->headOffice()
            ? $access->authorizeHeadOffice($this->user(), $this->permissions())
            : $access->authorize($this->user(), $this->permissions());

        return true;
    }
}
