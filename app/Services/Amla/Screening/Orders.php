<?php

namespace App\Services\Amla\Screening;

use App\Models\AmlaOrder;
use App\Models\AmlaOrderHold;
use App\Models\AmlaOrderReleaseRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Savings\Api\HoldDesk;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The freeze-order register (AMLA design §3.5, decision 5; task §11: "record
 * official orders, responsible officers, required actions, and relevant
 * reporting deadlines"). A person with `amla:sanctions` records an order and
 * places savings holds for it through the savings code (HoldDesk), as legal
 * holds with no end date; the link stays on the AMLA side. Placing is one
 * person's act, since an order takes effect at once. Lifting it takes two: one
 * holder asks, a different one approves (AmlaOrderReleaseRequest), and only
 * that approval releases the holds. Nothing is placed or released by itself.
 *
 * Accounts are locked in id order before the order row, as every savings
 * write and a payout into savings do, and the whole order is one
 * transaction: a hold that doesn't fit refuses the order. Each legal order
 * freezes independently: its holds stack on every other hold, other
 * orders' included.
 */
final class Orders
{
    public const ASKER_CANNOT_APPROVE = 'You asked for this release, so another AMLA sanctions holder must approve it.';

    public function __construct(
        private readonly HoldDesk $holds,
        private readonly AmlaLog $log,
        private readonly AmlaAccess $access,
        private readonly SavingsLocks $locks,
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
            // The accounts first, in id order, then the order row: the lock order a payout into savings uses.
            $this->locks->accounts($holds->pluck('account_id')->map(fn ($id) => (int) $id)->unique()->values());

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
     * Asks for an order's release (AMLA-B5 review: lifting takes two people),
     * with the reason and the lifting order's reference. Nothing is lifted
     * until a different `amla:sanctions` holder approves. One waiting request
     * per order.
     *
     * @param  array{reason: string, release_reference: string}  $input
     *
     * @throws ValidationException 422 when one is already waiting
     */
    public function requestRelease(User $user, int $orderId, array $input): AmlaOrderReleaseRequest
    {
        return DB::transaction(function () use ($user, $orderId, $input) {
            $order = $this->lockedActive($orderId);
            $waiting = ValidationException::withMessages(['order' => 'A release of this order is already waiting for approval.']);

            if (AmlaOrderReleaseRequest::query()->where('pending_key', $order->id)->exists()) {
                throw $waiting;
            }

            try {
                $request = AmlaOrderReleaseRequest::query()->create([
                    'order_id' => $order->id,
                    'status' => AmlaOrderReleaseRequest::PENDING,
                    'pending_key' => $order->id,
                    'reason' => trim($input['reason']),
                    'release_reference' => trim($input['release_reference']),
                    'requested_by' => $user->id,
                ]);
            } catch (QueryException $exception) {
                throw ($exception->errorInfo[1] ?? null) === 1062 ? $waiting : $exception;
            }

            $this->log->record('order_release_requested', $user, 'order', $order->id, ['order_id' => $order->id, 'release_request_id' => $request->id]);

            return $request;
        });
    }

    /**
     * Approves the waiting release: lifts every hold the order placed, then
     * records the release, once. The asker never approves (403, logged),
     * super_admin included.
     */
    public function approveRelease(User $checker, int $orderId, ?string $note): AmlaOrder
    {
        $this->refuseTheAsker($checker, $orderId, self::ASKER_CANNOT_APPROVE);

        return $this->approveReleaseLocked($checker, $orderId, $note);
    }

    /**
     * The approval under its locks: the order's savings accounts first (in id
     * order, as a time deposit payout into savings locks its account before
     * the order), then the order, then the waiting request. The asker check is
     * repeated here, under the lock, so a self-approval racing the first check
     * still answers 403.
     */
    public function approveReleaseLocked(User $checker, int $orderId, ?string $note): AmlaOrder
    {
        return DB::transaction(function () use ($checker, $orderId, $note) {
            $lockedAccounts = AmlaOrderHold::query()->where('order_id', $orderId)->pluck('account_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $this->locks->accounts($lockedAccounts);
            $order = $this->lockedActive($orderId);
            $request = $this->lockedWaiting($order);

            abort_if($request->requested_by === $checker->id, 403, self::ASKER_CANNOT_APPROVE);

            /*
             * Re-read the links under the order lock: a payout that linked a
             * new hold between the first read and the lock is seen here. If it
             * names an account this release didn't lock first, stop with 409
             * rather than leave that hold behind or lock out of order.
             */
            $links = AmlaOrderHold::query()->where('order_id', $order->id)->orderBy('account_id')->orderBy('id')->lockForUpdate()->get();

            abort_if(
                $links->pluck('account_id')->map(fn ($id) => (int) $id)->diff($lockedAccounts)->isNotEmpty(),
                409,
                'The order\'s holds changed while it was being released. Approve it again.',
            );

            foreach ($links as $link) {
                $hold = SavingsHold::query()->find($link->savings_hold_id);

                if ($hold !== null && $hold->status === SavingsHold::STATUS_ACTIVE) {
                    $this->holds->releaseForOrder($hold, $checker);
                }
            }

            $this->decide($request, AmlaOrderReleaseRequest::APPROVED, $checker, $note);

            $order->forceFill([
                'status' => AmlaOrder::RELEASED,
                'release_requested_by' => $request->requested_by,
                'released_by' => $checker->id,
                'released_at' => now(),
                'release_reason' => $request->reason,
                'release_reference' => $request->release_reference,
            ])->save();

            $this->log->record('order_released', $checker, 'order', $order->id, ['order_id' => $order->id, 'release_request_id' => $request->id]);

            return $order;
        });
    }

    /** Rejects the waiting release, with a note. The asker withdraws instead (403, logged). */
    public function rejectRelease(User $checker, int $orderId, string $note): AmlaOrder
    {
        $this->refuseTheAsker($checker, $orderId, 'You asked for this release, so withdraw it instead of rejecting it.');

        return DB::transaction(function () use ($checker, $orderId, $note) {
            $order = $this->lockedActive($orderId);
            $request = $this->lockedWaiting($order);
            $this->decide($request, AmlaOrderReleaseRequest::REJECTED, $checker, $note);
            $this->log->record('order_release_rejected', $checker, 'order', $order->id, ['order_id' => $order->id, 'release_request_id' => $request->id]);

            return $order;
        });
    }

    /** Withdraws the waiting release: its asker only (403, logged). */
    public function withdrawRelease(User $asker, int $orderId): AmlaOrder
    {
        $waiting = AmlaOrderReleaseRequest::query()->where('pending_key', $orderId)->first();

        if ($waiting !== null && $waiting->requested_by !== $asker->id) {
            $this->access->refuse($asker, 'not_the_asker', 'Only the person who asked for this release can withdraw it.');
        }

        return DB::transaction(function () use ($asker, $orderId) {
            $order = $this->lockedActive($orderId);
            $request = $this->lockedWaiting($order);
            $this->decide($request, AmlaOrderReleaseRequest::WITHDRAWN, $asker, null);
            $this->log->record('order_release_withdrawn', $asker, 'order', $order->id, ['order_id' => $order->id, 'release_request_id' => $request->id]);

            return $order;
        });
    }

    /** The waiting release's asker gets a logged 403; checked before any transaction, so the log row stays. */
    private function refuseTheAsker(User $user, int $orderId, string $message): void
    {
        $waiting = AmlaOrderReleaseRequest::query()->where('pending_key', $orderId)->first();

        if ($waiting !== null && $waiting->requested_by === $user->id) {
            $this->access->refuse($user, 'asker_cannot_decide', $message);
        }
    }

    private function lockedActive(int $orderId): AmlaOrder
    {
        $order = AmlaOrder::query()->lockForUpdate()->findOrFail($orderId);

        abort_unless($order->status === AmlaOrder::ACTIVE, 409, 'This order is already released.');

        return $order;
    }

    private function lockedWaiting(AmlaOrder $order): AmlaOrderReleaseRequest
    {
        $request = AmlaOrderReleaseRequest::query()->where('pending_key', $order->id)->lockForUpdate()->first();

        abort_if($request === null, 409, 'No release of this order is waiting for approval.');

        return $request;
    }

    private function decide(AmlaOrderReleaseRequest $request, string $status, User $user, ?string $note): void
    {
        $request->forceFill([
            'status' => $status,
            'pending_key' => null,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'decision_note' => filled($note) ? trim((string) $note) : null,
        ])->save();
    }
}
