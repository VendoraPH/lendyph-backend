<?php

namespace App\Http\Requests\Branch;

class ApproveInterbranchTransferRequest extends InterbranchTransferRequest
{
    public const PERMISSION = 'interbranch_transfers:approve';

    public const REFUSAL = 'Approving an interbranch transfer needs the interbranch transfers approve permission.';
}
