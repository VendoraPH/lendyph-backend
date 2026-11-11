<?php

namespace App\Listeners\Portal;

use App\Services\Portal\MemberPortalAccessEnded;
use App\Services\Portal\Requests\RequestSweep;

/**
 * When a member's portal access ends with their membership (withdrawn or
 * deceased), their open requests are cancelled and the holds those requests
 * placed are released, as the system (portal design §1). Found by Laravel's
 * listener discovery (app/Listeners), so no provider is edited.
 */
class EndPortalRequestsWhenAccessEnds
{
    public function __construct(private readonly RequestSweep $sweep) {}

    public function handle(MemberPortalAccessEnded $event): void
    {
        $this->sweep->endFor($event->portalUser, $event->reason);
    }
}
