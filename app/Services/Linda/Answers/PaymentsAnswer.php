<?php

namespace App\Services\Linda\Answers;

use App\Models\Repayment;
use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPayments;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;
use Illuminate\Support\Collection;

/**
 * "List this month's payments": Payment History's figures for the period
 * (LindaPayments, the GET /api/repayments query): how many posted payments
 * and their Total Collected (`amount_paid`), in the user's branches or the
 * one named, plus the latest `linda.list_max` of them, each with its
 * receipt, member (name and code), amount, date and method, linking to its
 * payment page. This month by default.
 *
 * It mirrors Payment History, so it needs `payments:view`. The Repayments
 * List report link, on the same period and branch, needs `reports:view`.
 */
final class PaymentsAnswer extends Answer
{
    public function __construct(private readonly LindaPayments $payments) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::CollectionDetails;
    }

    public function allows(User $user): bool
    {
        return $user->can('payments:view');
    }

    protected function subject(): string
    {
        return 'payment details';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');
        $max = max(1, (int) config('linda.list_max', 10));
        $figures = $this->payments->summary($scope->branchIds, $scope->branchId, $period, null, $max);
        $count = $figures['count'];

        $answer = $count === 0
            ? 'No payments were recorded '.W::during($period).' '.W::forScope($scope).'.'
            : self::sentence(W::during($period)).', '.W::count($count, 'payment').' '.($count === 1 ? 'was' : 'were')
                .' recorded '.W::forScope($scope).', '.W::money($figures['total']).' in all. '
                .($count > $max ? "The latest {$max} are listed below." : ($count === 1 ? 'It is listed below.' : 'They are listed below, newest first.'));

        $blocks = [LindaReply::stats([
            LindaReply::number('Payments', $count),
            LindaReply::money('Total Collected', $figures['total']),
        ], self::sentence(W::during($period)))];

        if ($count > 0) {
            $blocks[] = LindaReply::list('Latest payments', self::items($figures['latest']), true);
        }

        return $this->reply(
            $answer,
            $blocks,
            LindaLinks::forPeriod($user, $period, $scope->branchId, 'repayments_list'),
            array_values(array_unique($figures['latest']->map(fn (Repayment $payment) => (int) $payment->loan?->borrower_id)->filter()->all())),
            array_values(array_unique($figures['latest']->map(fn (Repayment $payment) => (int) $payment->loan_id)->all())),
        );
    }

    /**
     * Payments as list rows: the receipt, the member by name and code, the
     * amount, date and method, linking to the payment page.
     *
     * @param  Collection<int, Repayment>  $payments
     * @return list<array<string, mixed>>
     */
    public static function items(Collection $payments): array
    {
        return $payments->map(fn (Repayment $payment): array => [
            'title' => (string) $payment->receipt_number,
            'subtitle' => self::memberOf($payment),
            'fields' => [
                LindaReply::money('Amount', (string) $payment->amount_paid),
                LindaReply::date('Date', $payment->payment_date->toDateString()),
                LindaReply::text('Method', W::paymentMethod($payment->method)),
            ],
            'url' => '/payments/'.$payment->id,
        ])->values()->all();
    }

    private static function memberOf(Repayment $payment): string
    {
        $member = $payment->loan?->borrower;

        return $member === null ? '—' : $member->full_name.' ('.$member->borrower_code.')';
    }
}
