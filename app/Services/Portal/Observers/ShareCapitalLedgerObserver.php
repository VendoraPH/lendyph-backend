<?php

namespace App\Services\Portal\Observers;

use App\Models\ShareCapitalLedger;
use App\Services\Portal\PortalNotices;
use App\Services\Savings\SavingsMath;

/**
 * Tells the member when share capital is credited to them (portal design
 * §7). Registered by convention (PortalServiceProvider).
 */
class ShareCapitalLedgerObserver
{
    public function created(ShareCapitalLedger $row): void
    {
        if (SavingsMath::centavos((string) ($row->credit ?? '0')) <= 0) {
            return;
        }

        MoneyNotices::toMember(
            $row->borrower_id === null ? null : (int) $row->borrower_id,
            PortalNotices::CATEGORY_SHARE_CAPITAL,
            'Share capital credited',
            sprintf('%s was credited to your share capital on %s.', MoneyNotices::peso($row->credit), $row->date?->format('M j, Y') ?? now()->format('M j, Y')),
            '/member/share-capital',
        );
    }
}
