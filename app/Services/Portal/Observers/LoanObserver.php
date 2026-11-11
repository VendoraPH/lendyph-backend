<?php

namespace App\Services\Portal\Observers;

use App\Models\Loan;
use App\Services\Portal\PortalNotices;

/**
 * Tells the member when their loan is released (portal design §7): the move
 * from approved to released only, so a loan going back to released after a
 * voided payment says nothing. Registered by convention
 * (PortalServiceProvider); no lending file is edited.
 */
class LoanObserver
{
    public function updated(Loan $loan): void
    {
        if (! $loan->wasChanged('status') || $loan->status !== 'released' || $loan->getOriginal('status') !== 'approved') {
            return;
        }

        MoneyNotices::toMember(
            $loan->borrower_id === null ? null : (int) $loan->borrower_id,
            PortalNotices::CATEGORY_LOANS,
            'Loan released',
            sprintf('Your loan %s of %s was released.', $loan->loan_account_number ?? '', MoneyNotices::peso($loan->principal_amount)),
            '/member/loans/'.$loan->id,
        );
    }
}
