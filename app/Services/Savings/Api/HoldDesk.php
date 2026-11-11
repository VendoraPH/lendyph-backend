<?php

namespace App\Services\Savings\Api;

use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Models\User;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Holds (design §2). Every hold is written under its account's row lock, so
 * the available balance a withdrawal reads under that lock is exact.
 *
 * - **Place** a legal or staff hold on any account that isn't closed, up to
 *   the balance not already held. It comes off the available balance until
 *   it is released or passes `expires_on`.
 * - **Release** an active hold once, with a reason; anything else is 409.
 *   A `member_request` hold is refused here (422): it belongs to a member's
 *   portal request, and deciding the request is what releases it.
 * - **Place and release for a portal request** (portal design §6): the
 *   `member_request` hold a withdrawal request reserves its money with, placed
 *   by nobody on staff, and released by the deciding staff user, or by nobody
 *   (the member cancelling, or the system when access ends).
 *
 * All are audited (SavingsHold is Auditable).
 */
class HoldDesk
{
    /** The hold types staff place by hand. */
    public const STAFF_TYPES = ['legal', 'manual'];

    public function __construct(private readonly SavingsLocks $locks) {}

    /**
     * @param  array{type: string, amount: string, reason: string, expires_on?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function place(int $accountId, array $data, User $user): SavingsHold
    {
        if (! in_array($data['type'], self::STAFF_TYPES, true)) {
            throw new LogicException("Staff place only legal or manual holds, never \"{$data['type']}\".");
        }

        $account = $this->locks->account($accountId);

        if ($account->isClosed()) {
            Refuse::field('account', "{$account->account_number} is closed; nothing can be held on it.");
        }

        $today = now()->toDateString();
        $held = SavingsMath::centavos(SavingsHold::query()->where('account_id', $account->id)->holding($today)->sum('amount'));
        $free = max(0, SavingsMath::signedCentavos($account->ledger_balance) - $held);
        $amount = SavingsMath::centavos($data['amount']);

        if ($amount > $free) {
            Refuse::field('amount', Refuse::peso($amount).' is more than the '.Refuse::peso($free)." on {$account->account_number} not already held.");
        }

        return SavingsHold::query()->create([
            'account_id' => $account->id,
            'type' => $data['type'],
            'amount' => SavingsMath::pesos($amount),
            'reason' => $data['reason'],
            'placed_on' => $today,
            'expires_on' => $data['expires_on'] ?? null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    /**
     * Release a hold: its account is locked first, then the hold (lock order).
     * A member's request hold is refused: decide the request instead.
     *
     * @throws ValidationException
     */
    public function release(SavingsHold $hold, string $reason, User $user): SavingsHold
    {
        return $this->releaseLocked($hold, $reason, $user->id, forRequest: false);
    }

    /**
     * Reserve a portal withdrawal request's amount on its account, already
     * locked by the caller (portal design §6, lock step 2). The caller has
     * checked the amount against the available balance under that lock; the
     * balance not already held is checked again here.
     *
     * @throws ValidationException
     */
    public function placeForRequest(SavingsAccount $account, PortalRequest $request, int $centavos, string $expiresOn): SavingsHold
    {
        $locked = $this->locks->account($account->id);

        if ($locked->isClosed()) {
            Refuse::field('account', "{$locked->account_number} is closed; nothing can be held on it.");
        }

        $today = now()->toDateString();
        $held = SavingsMath::centavos(SavingsHold::query()->where('account_id', $locked->id)->holding($today)->sum('amount'));

        if ($centavos > max(0, SavingsMath::signedCentavos($locked->ledger_balance) - $held)) {
            Refuse::field('amount', Refuse::peso($centavos)." is more than the balance on {$locked->account_number} not already held.");
        }

        return SavingsHold::query()->create([
            'account_id' => $locked->id,
            'type' => SavingsHold::TYPE_MEMBER_REQUEST,
            'amount' => SavingsMath::pesos($centavos),
            'reason' => "Member withdrawal request {$request->reference}",
            'placed_on' => $today,
            'expires_on' => $expiresOn,
            'status' => SavingsHold::STATUS_ACTIVE,
            'source_type' => $request->getMorphClass(),
            'source_id' => $request->getKey(),
            'created_by' => null,
        ]);
    }

    /**
     * Release a portal request's hold, by the deciding staff user, or by
     * nobody (`$user` null: the member cancelling, or the system).
     */
    public function releaseForRequest(SavingsHold $hold, string $reason, ?User $user): SavingsHold
    {
        if ($hold->type !== SavingsHold::TYPE_MEMBER_REQUEST) {
            throw new LogicException("Hold #{$hold->id} is a {$hold->type} hold, not a member request's.");
        }

        return $this->releaseLocked($hold, $reason, $user?->id, forRequest: true);
    }

    private function releaseLocked(SavingsHold $hold, string $reason, ?int $userId, bool $forRequest): SavingsHold
    {
        $this->locks->account($hold->account_id);
        $locked = SavingsHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

        if (! $forRequest && $locked->type === SavingsHold::TYPE_MEMBER_REQUEST) {
            Refuse::field('hold', 'This hold reserves a member\'s withdrawal request from the portal. Decide the member\'s request instead; that releases it.');
        }

        if ($locked->status !== SavingsHold::STATUS_ACTIVE) {
            $words = SavingsHold::STATUS_WORDS[$locked->status] ?? 'no longer active';
            Refuse::conflict("This hold is already {$words}.");
        }

        $locked->update([
            'status' => SavingsHold::STATUS_RELEASED,
            'released_on' => now()->toDateString(),
            'release_reason' => $reason,
            'released_by' => $userId,
        ]);

        return $locked;
    }
}
