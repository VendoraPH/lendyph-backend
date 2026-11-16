<?php

namespace App\Services\Linda\Answers;

use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use App\Services\ContactVisibility;
use App\Services\Linda\LindaFinder;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording as W;
use App\Services\RepaymentService;

/**
 * "What's the status of LN-000042?": one loan, by its loan account number
 * (LN-) or application number (LA-), with the loan page's own figures
 * (RepaymentService::getLoanSummary()): Outstanding, Principal Balance, Past
 * Due (after grace), the next due date and amount, plus its status in words
 * (Loan::displayStatus()), product, principal and its latest posted payment.
 * A loan that was never released shows its status and amount and says it has
 * no balance yet. The member shows by name and code only.
 *
 * It mirrors the loan page, so it needs `loans:view`.
 */
final class LoanLookupAnswer extends LookupAnswer
{
    public function __construct(
        LindaFinder $finder,
        ContactVisibility $visibility,
        private readonly RepaymentService $repayments,
    ) {
        parent::__construct($finder, $visibility);
    }

    public function intent(): LindaIntent
    {
        return LindaIntent::LoanDetails;
    }

    public function allows(User $user): bool
    {
        return $user->can('loans:view');
    }

    protected function subject(): string
    {
        return 'loan details';
    }

    public function find(User $user, LindaLookupRequest $request): LindaReply
    {
        $number = mb_strtoupper(trim((string) $request->code));
        $loan = $this->finder->loanByNumber($user, $number);

        if ($loan === null) {
            return $this->reply(W::noLoanNumbered($number), 'not_found');
        }

        $loan->loadMissing('borrower', 'loanProduct:id,name');
        $shown = (string) ($loan->loan_account_number ?? $loan->application_number);
        $status = W::loanStatus($loan->displayStatus());
        $product = (string) ($loan->loanProduct?->name ?? '');
        $intro = $shown.' for '.self::memberLabel($loan->borrower).' is '
            .($product === '' ? 'a loan' : 'a '.$product.' loan').' of '.W::money((string) $loan->principal_amount).', now '.$status.'.';

        $stats = [
            LindaReply::text('Status', $status),
            LindaReply::text('Product', $product === '' ? '—' : $product),
            LindaReply::money('Principal', (string) $loan->principal_amount),
        ];

        if (! in_array($loan->status, Loan::EVER_RELEASED_STATUSES, true)) {
            return $this->reply(
                $intro.' It hasn\'t been released, so it has no balance yet.',
                'answered',
                [LindaReply::stats($stats)],
                [['label' => 'Open Loan', 'url' => '/loans/'.$loan->id]],
                [(int) $loan->borrower_id],
                [$loan->id],
            );
        }

        $summary = $this->repayments->getLoanSummary($loan);
        $last = Repayment::query()
            ->where('loan_id', $loan->id)
            ->where('status', 'posted')
            ->latest('payment_date')
            ->orderByDesc('id')
            ->first(['id', 'payment_date', 'amount_paid']);

        $stats[] = LindaReply::money('Outstanding', $summary['total_outstanding']);
        $stats[] = LindaReply::money('Principal Balance', $summary['outstanding_principal']);
        $stats[] = LindaReply::money('Past Due', $summary['overdue_amount']);

        if ($summary['next_due_date'] !== null) {
            $stats[] = LindaReply::date('Next Due Date', $summary['next_due_date']);
            $stats[] = LindaReply::money('Next Due Amount', $summary['next_due_amount']);
        }

        if ($last !== null) {
            $stats[] = LindaReply::money('Last Payment', (string) $last->amount_paid);
            $stats[] = LindaReply::date('Last Payment Date', $last->payment_date->toDateString());
        }

        $answer = $intro.' '.W::asOfToday().', Outstanding is '.W::money($summary['total_outstanding'])
            .' (Principal Balance '.W::money($summary['outstanding_principal']).')'
            .((float) $summary['overdue_amount'] > 0
                ? ', and '.W::money($summary['overdue_amount']).' is past due.'
                : ', and nothing is past due.')
            .($summary['next_due_date'] === null
                ? ''
                : ' The next payment, '.W::money($summary['next_due_amount']).', is due on '.W::date($summary['next_due_date']).'.')
            .($last === null
                ? ' No payment has been made yet.'
                : ' The last payment was '.W::money((string) $last->amount_paid).' on '.W::date($last->payment_date->toDateString()).'.');

        return $this->reply(
            $answer,
            'answered',
            [LindaReply::stats($stats, W::asOfToday())],
            [['label' => 'Open Loan', 'url' => '/loans/'.$loan->id]],
            [(int) $loan->borrower_id],
            [$loan->id],
        );
    }
}
