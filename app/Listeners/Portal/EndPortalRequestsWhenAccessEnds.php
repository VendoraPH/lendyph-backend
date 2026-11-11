<?php

namespace App\Listeners\Portal;

use App\Services\Portal\MemberPortalAccessEnded;
use App\Services\Portal\Requests\RequestSweep;
use Illuminate\Support\Facades\DB;

/**
 * When a member's portal access ends with their membership (withdrawn or
 * deceased), their open requests are cancelled and the holds those requests
 * placed are released, as the system (portal design §1). Found by Laravel's
 * listener discovery (app/Listeners), so no provider is edited.
 */
class EndPortalRequestsWhenAccessEnds
{
    public function __construct(private readonly RequestSweep $sweep) {}

    /**
     * Run once the membership change commits: it holds the member and
     * portal account rows, and ending the requests takes the savings account
     * and request locks, which a payout takes before the member and the
     * portal account. The membership has ended by then, so a failure is
     * reported and logged (endForReported), never answered as a 500, and the
     * nightly portal:requests-sweep ends the requests instead.
     */
    public function handle(MemberPortalAccessEnded $event): void
    {
        $portalUser = $event->portalUser;
        $reason = $event->reason;

        DB::afterCommit(fn () => $this->sweep->endForReported($portalUser, $reason));
    }
}
