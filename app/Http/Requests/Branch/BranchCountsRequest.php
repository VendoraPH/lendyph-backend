<?php

namespace App\Http\Requests\Branch;

/** The §15 branch counts, part of the executive dashboard: `dashboard:analytics`. */
class BranchCountsRequest extends InterbranchTransferRequest
{
    public const PERMISSION = 'dashboard:analytics';

    public const REFUSAL = 'The branch counts are part of the executive dashboard, which needs the dashboard analytics permission.';
}
