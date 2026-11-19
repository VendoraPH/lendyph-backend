<?php

namespace App\Http\Requests\Branch;

/** Reversing a transfer that has posted: a checker's step, with a reason. */
class ReverseInterbranchTransferRequest extends InterbranchTransferRequest
{
    public const PERMISSION = 'interbranch_transfers:approve';

    public const REFUSAL = 'Reversing an interbranch transfer needs the interbranch transfers approve permission.';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:300']];
    }
}
