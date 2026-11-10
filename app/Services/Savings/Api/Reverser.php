<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reversals (design §2, §8). A mistaken deposit, withdrawal or transfer is
 * corrected by a linked `reversal` row, never by editing it: the original row
 * stays exactly as it was, and its journal is reversed (dated today).
 *
 * - **Always through a checker:** a request opens a `reversal` approval at any
 *   amount (202), keyed "rev:{row}" so a row has one live request (409 for a
 *   second, and 409 once it is reversed).
 * - **What can be reversed:** deposits, withdrawals and transfers. Interest,
 *   tax, closing and time deposit rows, and reversals themselves, have their
 *   own journals and are refused (422).
 * - **Transfers:** both legs are reversed together, so the member's two
 *   accounts stay whole. The request is anchored on the incoming leg, whose
 *   pending reversal reserves the money on the account that received it.
 * - **Money:** reversing money in (a deposit or a transfer in) needs that
 *   money still there, less holds and other pending requests; the maintaining
 *   balance doesn't stop a correction.
 * - **Dates:** the reversal row is dated today and keeps the original row's
 *   value date, so interest counts as if the original had never posted.
 * - **Refused (422):** a leg on a closed or frozen account, on an account
 *   in an interest run not yet posted (draft or approved), or one already
 *   counted in credited interest (its value date on or before the account's
 *   `interest_credited_through`): reverse that interest run first.
 */
class Reverser
{
    public const REVERSIBLE = ['deposit', 'withdrawal', 'transfer_in', 'transfer_out'];

    private const MONEY_IN = ['deposit', 'transfer_in'];

    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly SavingsLedger $ledger,
        private readonly AutomaticPoster $poster,
        private readonly ApprovalGate $gate,
        private readonly Teller $teller,
    ) {}

    /** The row a reversal request is keyed on: a transfer's incoming leg, otherwise the row itself. */
    public static function anchorIdOf(SavingsTransaction $row, ?int $counterpartId): int
    {
        return $row->type === 'transfer_out' && $counterpartId !== null ? $counterpartId : $row->id;
    }

    /** @throws ValidationException */
    public function request(SavingsTransaction $target, string $reason, User $user): SavingsApproval
    {
        if (! in_array($target->type, self::REVERSIBLE, true) || $target->time_deposit_id !== null || $target->interest_run_id !== null) {
            Refuse::field('transaction', 'Only deposits, withdrawals and transfers can be reversed. Interest, tax, closing, time deposit and reversal lines carry their own entries.');
        }

        $legs = $this->legs($target);
        $anchor = $legs->first();
        $accounts = $this->locks->accounts($legs->pluck('account_id'));

        $this->assertNotReversed($legs);
        $this->assertNoLiveRequest($anchor);

        foreach ($legs as $leg) {
            $account = $accounts[$leg->account_id];
            $this->assertLegCanBeReversed($leg, $account);

            if (in_array($leg->type, self::MONEY_IN, true)) {
                $this->teller->assertAvailable($account, SavingsMath::centavos($leg->credit), 0, false);
            }
        }

        return $this->gate->open('reversal', $this->amountOf($anchor), [
            'account_id' => $anchor->account_id,
            'target_transaction_id' => $anchor->id,
            'payload' => ['reason' => $reason],
            'live_key' => "rev:{$anchor->id}",
        ], $user->id);
    }

    /**
     * The accounts an approved reversal moves money on.
     *
     * @return list<int>
     */
    public function accountIds(SavingsApproval $approval): array
    {
        $anchor = SavingsTransaction::query()->find($approval->target_transaction_id);

        return $anchor === null ? [] : $this->legs($anchor)->pluck('account_id')->unique()->values()->all();
    }

    /**
     * Post the reversal rows (the accounts are locked by the desk) and reverse
     * the journals. Returns the anchor's reversal row.
     *
     * @throws ValidationException
     */
    public function execute(SavingsApproval $approval, User $checker): SavingsTransaction
    {
        $anchor = SavingsTransaction::query()->find($approval->target_transaction_id)
            ?? Refuse::field('transaction', 'The line this request reverses no longer exists.');
        $legs = $this->legs($anchor);
        $reason = (string) ($approval->payload['reason'] ?? 'Reversal');

        $reversals = [];

        $accounts = $legs->mapWithKeys(fn (SavingsTransaction $leg) => [$leg->id => SavingsAccount::query()->findOrFail($leg->account_id)]);

        foreach ($legs as $leg) {
            $this->assertLegCanBeReversed($leg, $accounts[$leg->id]);
        }

        foreach ($legs as $leg) {
            $account = $accounts[$leg->id];
            $amount = $this->amountOf($leg);
            $attributes = [
                'date' => now()->toDateString(),
                'value_date' => $leg->value_date->toDateString(),
                'method' => $leg->method,
                'remarks' => $reason,
                'reverses_transaction_id' => $leg->id,
                'approval_id' => $approval->id,
                'created_by' => $approval->requested_by,
            ];

            if (in_array($leg->type, self::MONEY_IN, true)) {
                $own = $leg->id === $anchor->id ? SavingsMath::centavos($approval->amount) : 0;
                $this->teller->assertAvailable($account, $amount, $own, false);
                $reversals[$leg->id] = $this->ledger->debit($account, 'reversal', $amount, $attributes);
            } else {
                $reversals[$leg->id] = $this->ledger->credit($account, 'reversal', $amount, $attributes);
            }

            if (in_array($leg->type, ['deposit', 'withdrawal'], true)) {
                $this->poster->reverseFor($leg, 'savings', $checker->id, "Reversed by {$reversals[$leg->id]->reference}: {$reason}");
            }
        }

        return $reversals[$anchor->id];
    }

    /**
     * The row and, for a transfer, its other leg: the incoming leg first.
     *
     * @return Collection<int, SavingsTransaction>
     */
    private function legs(SavingsTransaction $row): Collection
    {
        $other = match ($row->type) {
            'transfer_in' => $row->counterpart_transaction_id === null ? null : SavingsTransaction::query()->find($row->counterpart_transaction_id),
            'transfer_out' => SavingsTransaction::query()->where('counterpart_transaction_id', $row->id)->first(),
            default => null,
        };

        if ($other === null) {
            return collect([$row]);
        }

        return $row->type === 'transfer_in' ? collect([$row, $other]) : collect([$other, $row]);
    }

    /**
     * A leg can be reversed only on an open, unfrozen account (no money moves
     * on a frozen one, reversals included), and only while no interest has
     * been credited over it: interest already credited on the row would stay
     * paid, so that interest run has to be reversed first. Checked on every
     * leg, at request and again at approval.
     *
     * @throws ValidationException
     */
    private function assertLegCanBeReversed(SavingsTransaction $leg, SavingsAccount $account): void
    {
        if ($account->isClosed()) {
            Refuse::field('transaction', "{$account->account_number} is closed, so {$leg->reference} can't be reversed.");
        }

        if ($account->status === SavingsAccount::STATUS_FROZEN) {
            Refuse::field('transaction', "{$account->account_number} is frozen: no money moves on it, reversals included, until it is unfrozen.");
        }

        if (UnpostedInterestRun::holds($account)) {
            Refuse::field('transaction', 'This account is in an interest run waiting to be posted. Post or reject that run first.');
        }

        $credited = $account->interest_credited_through?->toDateString();

        if ($credited !== null && $leg->value_date->toDateString() <= $credited) {
            Refuse::field('transaction', 'Interest has already been credited on this. Reverse that interest run first, then this, then run the period again.');
        }
    }

    /**
     * 409 at request time for a row already reversed. (When an approved
     * reversal posts, SavingsLedger refuses a second one itself.)
     *
     * @param  Collection<int, SavingsTransaction>  $legs
     */
    private function assertNotReversed(Collection $legs): void
    {
        $done = SavingsTransaction::query()->whereIn('reverses_transaction_id', $legs->pluck('id'))->value('reference');

        if ($done !== null) {
            Refuse::conflict("This line has already been reversed by {$done}.");
        }
    }

    /**
     * A live request on this row answers 409. One left pending past its day
     * no longer counts: it is marked expired (as the nightly job would) so a
     * new one can be made.
     */
    private function assertNoLiveRequest(SavingsTransaction $anchor): void
    {
        $stale = SavingsApproval::query()->where('live_key', "rev:{$anchor->id}")->lockForUpdate()->first();

        if ($stale === null || $stale->status !== 'pending') {
            return;
        }

        if ($stale->expires_at !== null && $stale->expires_at->lessThanOrEqualTo(now())) {
            $stale->update(['status' => 'expired', 'live_key' => null]);

            return;
        }

        Refuse::conflict('A reversal of this line is already waiting for approval.');
    }

    private function amountOf(SavingsTransaction $row): int
    {
        return max(SavingsMath::centavos($row->credit), SavingsMath::centavos($row->debit));
    }
}
