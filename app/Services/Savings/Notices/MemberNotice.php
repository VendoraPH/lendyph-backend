<?php

namespace App\Services\Savings\Notices;

use App\Services\Portal\Observers\MoneyNotices;
use App\Services\Portal\PortalNotices;
use App\Services\Portal\PortalSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A savings notice to the member, in the Member Portal only (in-app, never
 * SMS or email): only while the portal is switched on with its savings or
 * time deposits page, and then only to a member whose portal account is
 * active (MoneyNotices, written after commit). Never fails the caller.
 */
class MemberNotice
{
    public function __construct(private readonly PortalSettings $portal) {}

    /** @param  string  $feature  the portal page it belongs to: `savings` or `time_deposits` */
    public function send(?int $borrowerId, string $feature, string $title, string $body, string $link): void
    {
        if ($borrowerId === null) {
            return;
        }

        try {
            if (! $this->portal->enabled() || ! $this->portal->featureOn($feature)) {
                return;
            }
        } catch (Throwable $e) {
            Log::error('A savings member notice could not check the portal switch; nothing was sent to the member.', ['exception' => $e]);

            return;
        }

        MoneyNotices::toMember($borrowerId, PortalNotices::CATEGORY_SAVINGS, $title, $body, $link);
    }
}
