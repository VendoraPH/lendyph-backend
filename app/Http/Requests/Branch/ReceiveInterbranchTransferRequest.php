<?php

namespace App\Http\Requests\Branch;

class ReceiveInterbranchTransferRequest extends InterbranchTransferRequest
{
    public const PERMISSION = 'interbranch_transfers:receive';

    public const REFUSAL = 'Receiving an interbranch transfer needs the interbranch transfers receive permission.';
}
