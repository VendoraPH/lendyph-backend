<?php

namespace App\Services\Portal\Requests;

use App\Services\Portal\PortalSettings;

/**
 * When an open request lapses (portal design §6): a withdrawal with its hold
 * (`withdrawal_hold_days`), the others after `request_expiry_days`. A request
 * is still open on its `expires_on` and expires the day after.
 */
final class RequestExpiry
{
    public static function otherRequests(): string
    {
        $days = (int) (app(PortalSettings::class)->current()->request_expiry_days ?? 30);

        return now()->addDays(max(1, $days))->toDateString();
    }
}
