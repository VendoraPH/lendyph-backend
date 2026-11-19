<?php

namespace App\Http\Requests\Branch;

class CancelInterbranchTransferRequest extends InterbranchTransferRequest
{
    public const PERMISSION = 'interbranch_transfers:create';

    public const REFUSAL = 'Cancelling an interbranch transfer needs the interbranch transfers create permission.';
}
