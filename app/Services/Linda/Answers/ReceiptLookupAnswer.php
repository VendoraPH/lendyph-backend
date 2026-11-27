<?php

namespace App\Services\Linda\Answers;

use App\Http\Resources\RepaymentResource;
use App\Models\Borrower;
use App\Models\Repayment;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\ContactVisibility;
use App\Services\Linda\LindaFinder;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaPayments;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording as W;
use Illuminate\Http\Request;

/**
 * "Find receipt RCP-000123": one payment, with the payment page's own figures
 * (RepaymentResource, what GET /repayments/{id} returns): its date, member,
 * loan, amount, method, status (voided, with the reason) and how it was
 * applied: principal, interest, penalty, insurance premium, build-up and
 * overpayment.
 *
 * Asked about a member instead ("bayad ni Juan"), it lists that member's
 * latest payments, with Payment History's count and Total Collected for
 * them (its `borrower_id` filter), in the user's branches.
 *
 * It mirrors the payment pages, so it needs `payments:view`; finding the
 * member by name or code also needs `borrowers:view`.
 */
final class ReceiptLookupAnswer extends LookupAnswer
{
    public function __construct(
        LindaFinder $finder,
        ContactVisibility $visibility,
        private readonly LindaPayments $payments,
    ) {
        parent::__construct($finder, $visibility);
    }

    public function intent(): LindaIntent
    {
        return LindaIntent::TransactionLookup;
    }

    public function allows(User $user): bool
    {
        return $user->can('payments:view');
    }

    protected function subject(): string
    {
        return 'payment details';
    }

    public function find(User $user, LindaLookupRequest $request): LindaReply
    {
        $code = mb_strtoupper(trim((string) $request->code));

        if ($code !== '' && str_starts_with($code, Repayment::RECEIPT_PREFIX.'-')) {
            return $this->receipt($user, $code);
        }

        // Searching members by name or code reads the member list, so it
        // needs `borrowers:view` as well, like the member pages.
        if (! $user->can('borrowers:view')) {
            return $this->reply(W::denied('member details'), 'denied');
        }

        $member = $this->member($user, $request);

        return $member instanceof Borrower ? $this->paymentsOf($user, $member) : $member;
    }

    private function receipt(User $user, string $number): LindaReply
    {
        $receipt = $this->finder->receiptByNumber($user, $number);

        if ($receipt === null) {
            return $this->reply(W::noReceiptNumbered($number), 'not_found');
        }

        // Exactly what the payment page loads (RepaymentController::show()).
        $receipt->load('loan.borrower', 'loan.loanProduct', 'loan.amortizationSchedules', 'receivedByUser', 'voidedByUser', 'shareCapitalLedgerEntries');
        $shown = (new RepaymentResource($receipt))->toArray(Request::create('/'));
        $voided = $receipt->status === 'voided';
        $loanNumber = (string) $receipt->loan?->displayNumber();

        $applied = [
            LindaReply::money('Principal', $shown['principal_applied']),
            LindaReply::money('Interest', $shown['interest_applied']),
            LindaReply::money('Penalty', $shown['penalty_applied']),
            LindaReply::money('Insurance Premium', $shown['insurance_applied']),
        ];

        // The stored overpayment split into build-up and what stayed as
        // overpayment, when the stored rows can tell (RepaymentResource).
        if ($shown['build_up_credited'] !== null && $shown['overpayment_kept'] !== null) {
            $applied[] = LindaReply::money('Build-Up', $shown['build_up_credited']);
            $applied[] = LindaReply::money('Overpayment', $shown['overpayment_kept']);
        } else {
            $applied[] = LindaReply::money('Build-Up or Overpayment', $shown['overpayment']);
        }

        $answer = 'Receipt '.$receipt->receipt_number.' is '.W::money($shown['amount_paid']).' '.self::paidBy($shown['method'])
            .' on '.W::date((string) $shown['payment_date']).' by '.self::memberLabel($receipt->loan?->borrower)
            .($loanNumber === '' ? '' : ' on loan '.$loanNumber).'.'
            .($voided
                ? ' This receipt was voided'.(filled($receipt->void_reason) ? ', with the reason "'.W::echoed((string) $receipt->void_reason).'"' : '').', so it counts in no total.'
                : '');

        return $this->reply(
            $answer,
            'answered',
            [
                LindaReply::stats([
                    LindaReply::money('Amount', $shown['amount_paid']),
                    LindaReply::date('Date', (string) $shown['payment_date']),
                    LindaReply::text('Method', W::paymentMethod($shown['method'])),
                    LindaReply::text('Status', $voided ? 'Voided' : 'Posted'),
                ], 'Receipt '.$receipt->receipt_number),
                LindaReply::stats($applied, 'How it was applied'),
            ],
            [['label' => 'Open Payment', 'url' => '/payments/'.$receipt->id]],
            array_filter([(int) $receipt->loan?->borrower_id]),
            [(int) $receipt->loan_id],
        );
    }

    /**
     * How a payment was made, mid-sentence: "paid in cash", "paid by bank
     * transfer", "paid by GCash", "paid by auto-pay". The method's words in
     * lower case, keeping proper names (GCash, Maya) as they are.
     */
    private static function paidBy(?string $method): string
    {
        $words = W::paymentMethod($method);

        return match ($method) {
            'cash' => 'paid in cash',
            'online' => 'paid online',
            null, '' => 'paid (method not recorded)',
            'gcash', 'maya' => 'paid by '.$words,
            default => 'paid by '.mb_strtolower($words),
        };
    }

    private function paymentsOf(User $user, Borrower $member): LindaReply
    {
        $figures = $this->payments->summary(
            app(BranchAccess::class)->for($user),
            null,
            null,
            $member->id,
            self::listMax(),
        );

        $who = self::memberLabel($member);

        if ($figures['count'] === 0) {
            return $this->reply($who.' has no posted payments in your branches.', 'answered', [], [], [$member->id]);
        }

        $answer = $who.' has '.W::count($figures['count'], 'posted payment').' in your branches, '.W::money($figures['total']).' in all. '
            .($figures['count'] > self::listMax() ? 'The latest '.self::listMax().' are listed below.' : 'They are listed below, newest first.');

        return $this->reply(
            $answer,
            'answered',
            [
                LindaReply::stats([
                    LindaReply::number('Payments', $figures['count']),
                    LindaReply::money('Total Collected', $figures['total']),
                ]),
                LindaReply::list('Latest payments', PaymentsAnswer::items($figures['latest']), true),
            ],
            [],
            [$member->id],
            $figures['latest']->pluck('loan_id')->all(),
        );
    }
}
