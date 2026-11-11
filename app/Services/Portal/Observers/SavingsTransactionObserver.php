<?php

namespace App\Services\Portal\Observers;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Portal\PortalNotices;

/**
 * Tells the member when a deposit, withdrawal or interest credit posts on
 * their savings (portal design §7). Registered by convention
 * (PortalServiceProvider); no savings file is edited.
 */
class SavingsTransactionObserver
{
    private const TITLES = [
        'deposit' => ['Deposit posted', '%s was deposited to %s on %s.'],
        'withdrawal' => ['Withdrawal posted', '%s was withdrawn from %s on %s.'],
        'interest' => ['Interest credited', '%s interest was credited to %s on %s.'],
    ];

    public function created(SavingsTransaction $row): void
    {
        if (! isset(self::TITLES[$row->type])) {
            return;
        }

        $account = SavingsAccount::query()->find($row->account_id, ['id', 'borrower_id', 'account_number']);

        if ($account === null) {
            return;
        }

        [$title, $body] = self::TITLES[$row->type];
        $amount = $row->type === 'withdrawal' ? $row->debit : $row->credit;

        MoneyNotices::toMember(
            (int) $account->borrower_id,
            PortalNotices::CATEGORY_SAVINGS,
            $title,
            sprintf($body, MoneyNotices::peso($amount), $account->account_number, $row->date?->format('M j, Y') ?? now()->format('M j, Y')),
            '/member/savings/'.$account->id,
        );
    }
}
