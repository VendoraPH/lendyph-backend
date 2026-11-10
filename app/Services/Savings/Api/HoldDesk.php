<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsHold;
use App\Models\User;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Illuminate\Validation\ValidationException;

/**
 * Holds (design §2). Every hold is written under its account's row lock, so
 * the available balance a withdrawal reads under that lock is exact.
 *
 * - **Place** a legal or staff hold on any account that isn't closed, up to
 *   the balance not already held. It comes off the available balance until
 *   it is released or passes `expires_on`.
 * - **Release** an active hold once, with a reason; anything else is 409.
 *
 * Both are audited (SavingsHold is Auditable).
 */
class HoldDesk
{
    public function __construct(private readonly SavingsLocks $locks) {}

    /**
     * @param  array{type: string, amount: string, reason: string, expires_on?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function place(int $accountId, array $data, User $user): SavingsHold
    {
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

    /** Release a hold: its account is locked first, then the hold (lock order). */
    public function release(SavingsHold $hold, string $reason, User $user): SavingsHold
    {
        $this->locks->account($hold->account_id);
        $locked = SavingsHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== SavingsHold::STATUS_ACTIVE) {
            $words = SavingsHold::STATUS_WORDS[$locked->status] ?? 'no longer active';
            Refuse::conflict("This hold is already {$words}.");
        }

        $locked->update([
            'status' => SavingsHold::STATUS_RELEASED,
            'released_on' => now()->toDateString(),
            'release_reason' => $reason,
            'released_by' => $user->id,
        ]);

        return $locked;
    }
}
