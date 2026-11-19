<?php

namespace App\Services\Amla\Screening;

use App\Models\AmlaOrder;
use App\Models\AmlaOrderHold;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Savings\Api\HoldDesk;
use App\Services\Savings\SavingsMath;

/**
 * Keeps money that arrives for a member under a legal order held (AMLA-B5
 * re-review: frozen funds stay frozen, and the member's own instruction still
 * runs). A time deposit's payout into savings is credited, then held once for
 * each order in force on the member, in the same transaction, linked like each
 * order's own holds, so only that order's release lifts its hold (each legal
 * order freezes independently).
 *
 * Lock order: the savings account (already locked by the settlement), then
 * the order row. An order's release locks its accounts before the order, so
 * the two never wait on each other the other way round; a credit that waits
 * for a release finds the order lifted and holds nothing.
 */
final class OrderCredits
{
    public function __construct(
        private readonly HoldDesk $holds,
        private readonly AmlaLog $log,
    ) {}

    /**
     * Holds the credited amount once for each order in force on the member,
     * oldest first: each order freezes independently, so lifting one leaves
     * the others holding. Answers the links placed (none without an order).
     *
     * @return list<AmlaOrderHold>
     */
    public function holdCredit(int $borrowerId, int $accountId, int $centavos, ?int $userId): array
    {
        if ($centavos <= 0) {
            return [];
        }

        $orders = AmlaOrder::query()->where('borrower_id', $borrowerId)->where('status', AmlaOrder::ACTIVE)->orderBy('id')->lockForUpdate()->get();
        $links = [];

        foreach ($orders as $order) {
            $hold = $this->holds->holdCreditForOrder($accountId, $centavos, $userId);

            $links[] = AmlaOrderHold::query()->create([
                'order_id' => $order->id,
                'savings_hold_id' => $hold->id,
                'account_id' => $accountId,
                'amount' => SavingsMath::pesos($centavos),
            ]);

            $this->log->record('order_credit_held', null, 'order', $order->id, ['order_id' => $order->id, 'savings_hold_id' => $hold->id]);
        }

        return $links;
    }
}
