<?php

namespace App\Services\Portal;

use App\Models\PortalTermsVersion;
use App\Models\PortalUser;

/**
 * Which terms a member must accept (portal design §1). The current version is
 * the latest published one. A member must accept again when a version
 * published with `requires_reacceptance` came out after they last accepted,
 * even if a later version without the flag followed it.
 */
final class PortalTerms
{
    public function current(): ?PortalTermsVersion
    {
        return PortalTermsVersion::current();
    }

    public function requiredFor(PortalUser $portalUser): bool
    {
        if ($this->current() === null) {
            return false;
        }

        if ($portalUser->terms_accepted_at === null) {
            return true;
        }

        return PortalTermsVersion::query()->published()
            ->where('requires_reacceptance', true)
            ->where('published_at', '>', $portalUser->terms_accepted_at)
            ->exists();
    }
}
