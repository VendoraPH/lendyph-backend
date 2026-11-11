<?php

namespace App\Services\Portal;

use App\Models\PortalUser;

/**
 * A member's portal access ended because their membership did (withdrawn or
 * deceased). Dispatched after the portal user is disabled; the request desk
 * listens to cancel the member's open requests and release their holds.
 */
final readonly class MemberPortalAccessEnded
{
    public function __construct(
        public PortalUser $portalUser,
        public string $reason,
    ) {}
}
