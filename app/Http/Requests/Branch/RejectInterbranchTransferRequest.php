<?php

namespace App\Http\Requests\Branch;

class RejectInterbranchTransferRequest extends InterbranchTransferRequest
{
    public const PERMISSION = 'interbranch_transfers:approve';

    public const REFUSAL = 'Rejecting an interbranch transfer needs the interbranch transfers approve permission.';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:500']];
    }
}
