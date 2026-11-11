<?php

namespace App\Services\Portal\Observers;

use App\Models\Loan;
use App\Models\Repayment;
use App\Services\Portal\PortalNotices;

/**
 * Tells the member when a loan payment is posted or voided (portal design
 * §7). A preview's rolled-back payment (receipt `PREVIEW-…`) says nothing.
 * Registered by convention (PortalServiceProvider); no lending file is
 * edited.
 */
class RepaymentObserver
{
    public function created(Repayment $repayment): void
    {
        if ($repayment->status === 'voided' || str_starts_with((string) $repayment->receipt_number, 'PREVIEW-')) {
            return;
        }

        $this->tell($repayment, 'Payment received', '%s was received for loan %s (receipt %s).');
    }

    public function updated(Repayment $repayment): void
    {
        if (! $repayment->wasChanged('status') || $repayment->status !== 'voided') {
            return;
        }

        $this->tell($repayment, 'Payment voided', 'The payment of %s on loan %s (receipt %s) was voided. Contact your branch if you have questions.');
    }

    private function tell(Repayment $repayment, string $title, string $body): void
    {
        $loan = Loan::query()->find($repayment->loan_id, ['id', 'borrower_id', 'loan_account_number']);

        if ($loan === null) {
            return;
        }

        MoneyNotices::toMember(
            $loan->borrower_id === null ? null : (int) $loan->borrower_id,
            PortalNotices::CATEGORY_LOANS,
            $title,
            sprintf($body, MoneyNotices::peso($repayment->amount_paid), $loan->loan_account_number ?? '', $repayment->receipt_number),
            '/member/loans/'.$loan->id,
        );
    }
}
