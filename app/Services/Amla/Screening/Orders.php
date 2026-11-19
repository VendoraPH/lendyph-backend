<?php

namespace App\Services\Amla\Screening;

use App\Models\AmlaOrder;
use App\Models\AmlaOrderHold;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Savings\Api\HoldDesk;
use App\Services\Savings\SavingsMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The freeze-order register (AMLA design §3.5, decision 5; task §11: "record
 * official orders, responsible officers, required actions, and relevant
 * reporting deadlines"). A person with `amla:sanctions` records an order and
 * places savings holds for it through the savings code (HoldDesk), as legal
 * holds with no end date; the link stays on the AMLA side. Only the order's
 * release lifts them. Nothing is placed or released by itself.
 *
 * Accounts are locked in id order, as every savings write does, and the
 * whole order is one transaction: a hold that doesn't fit refuses the order.
 */
final class Orders
{
    public function __construct(
        private readonly HoldDesk $holds,
        private readonly AmlaLog $log,
    ) {}

    /**
     * @param  array{reference: string, authority: string, received_on: string, borrower_id: int, required_action: string, details?: string|null, responsible_officer_id?: int|null, return_due_on?: string|null, holds: list<array{account_id: int, amount: string}>}  $input
     *
     * @throws ValidationException 422 in words
     */
    public function place(User $user, array $input): AmlaOrder
    {
        $memberId = (int) $input['borrower_id'];

        foreach ($input['holds'] as $index => $hold) {
            $owner = SavingsAccount::query()->whereKey((int) $hold['account_id'])->value('borrower_id');

            if ($owner === null || (int) $owner !== $memberId) {
                throw ValidationException::withMessages(["holds.{$index}.account_id" => 'Pick one of the order\'s member\'s own savings accounts.']);
            }
        }

        $holds = collect($input['holds'])->sortBy(fn (array $hold) => (int) $hold['account_id'])->values();

        return DB::transaction(function () use ($user, $input, $memberId, $holds) {
            $order = AmlaOrder::query()->create([
                'reference' => trim($input['reference']),
                'authority' => $input['authority'],
                'received_on' => $input['received_on'],
                'borrower_id' => $memberId,
                'required_action' => trim($input['required_action']),
                'details' => filled($input['details'] ?? null) ? trim((string) $input['details']) : null,
                'responsible_officer_id' => $input['responsible_officer_id'] ?? null,
                'return_due_on' => $input['return_due_on'] ?? null,
                'status' => AmlaOrder::ACTIVE,
                'placed_by' => $user->id,
            ]);

            foreach ($holds as $hold) {
                $centavos = SavingsMath::centavos((string) $hold['amount']);
                $placed = $this->holds->placeForOrder((int) $hold['account_id'], $centavos, $user);

                AmlaOrderHold::query()->create([
                    'order_id' => $order->id,
                    'savings_hold_id' => $placed->id,
                    'account_id' => $placed->account_id,
                    'amount' => $placed->amount,
                ]);
            }

            $this->log->record('order_placed', $user, 'order', $order->id, ['order_id' => $order->id]);

            return $order;
        });
    }

    /**
     * Lifts every hold the order placed, then records the release, once.
     *
     * @param  array{reason: string, release_reference: string}  $input
     */
    public function release(User $user, int $orderId, array $input): AmlaOrder
    {
        return DB::transaction(function () use ($user, $orderId, $input) {
            $order = AmlaOrder::query()->lockForUpdate()->findOrFail($orderId);

            abort_unless($order->status === AmlaOrder::ACTIVE, 409, 'This order is already released.');

            $links = AmlaOrderHold::query()->where('order_id', $order->id)->orderBy('account_id')->orderBy('id')->get();

            foreach ($links as $link) {
                $hold = SavingsHold::query()->find($link->savings_hold_id);

                if ($hold !== null && $hold->status === SavingsHold::STATUS_ACTIVE) {
                    $this->holds->releaseForOrder($hold, $user);
                }
            }

            $order->forceFill([
                'status' => AmlaOrder::RELEASED,
                'released_by' => $user->id,
                'released_at' => now(),
                'release_reason' => trim($input['reason']),
                'release_reference' => trim($input['release_reference']),
            ])->save();

            $this->log->record('order_released', $user, 'order', $order->id, ['order_id' => $order->id]);

            return $order;
        });
    }
}
