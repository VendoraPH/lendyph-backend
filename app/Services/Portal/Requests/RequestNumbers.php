<?php

namespace App\Services\Portal\Requests;

use App\Models\PortalRequest;
use Illuminate\Support\Facades\DB;

/**
 * A portal request's reference, `REQ-YYYYMMDD-NNNNNN` (Manila day), the
 * highest that day plus one, read plainly: two submits can read the same
 * number, and the unique index refuses the second, which
 * GeneratedNumberWrite retries (PORTAL_REQUEST_REFERENCE_INDEX).
 */
final class RequestNumbers
{
    public function next(): string
    {
        $prefix = PortalRequest::REFERENCE_PREFIX.'-'.now()->format('Ymd').'-';
        $last = DB::table('portal_requests')->where('reference', 'like', $prefix.'%')->max('reference');
        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
