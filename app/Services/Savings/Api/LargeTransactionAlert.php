<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsAccount;
use App\Models\SavingsSetting;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\BranchAccess;
use App\Services\Savings\SavingsMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The large-transaction alert (Arvin's decision, S2 review): a posted deposit
 * or withdrawal of `savings_settings.large_transaction_alert` (₱500,000.00 by
 * default) or more sends one in-app staff notification,
 * `savings_large_transaction`, to every active user who holds
 * `savings:approve` and can see the account's branch. Never to the people
 * who made or approved it, never by SMS or email.
 *
 * Sent after the posting's transaction commits, through the same staff
 * notification the lending events use (StaffNotification, database channel
 * only). A failure to send is logged and never undoes the posting.
 */
class LargeTransactionAlert
{
    public const TYPE = 'savings_large_transaction';

    public function __construct(private readonly BranchAccess $branches) {}

    /**
     * @param  list<int|null>  $actorIds  who made or approved it (never told)
     */
    public function afterPosting(SavingsTransaction $row, array $actorIds): void
    {
        if (! in_array($row->type, ['deposit', 'withdrawal'], true)) {
            return;
        }

        $amount = max(SavingsMath::centavos($row->credit), SavingsMath::centavos($row->debit));

        if ($amount < SavingsMath::centavos(SavingsSetting::current()->large_transaction_alert)) {
            return;
        }

        $skip = array_values(array_unique(array_map('intval', array_filter($actorIds))));

        try {
            DB::afterCommit(function () use ($row, $amount, $skip): void {
                try {
                    $this->send($row, $amount, $skip);
                } catch (Throwable $e) {
                    $this->logFailure($row, $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFailure($row, $e);
        }
    }

    /** @param  list<int>  $skip */
    private function send(SavingsTransaction $row, int $amount, array $skip): void
    {
        $account = SavingsAccount::query()->with('borrower')->findOrFail($row->account_id);
        $recipients = $this->recipients($account, $skip);

        if ($recipients->isEmpty()) {
            return;
        }

        $what = $row->type === 'deposit' ? 'Deposit' : 'Withdrawal';
        $member = $account->borrower?->full_name ?? 'a member';

        Notification::send($recipients, new StaffNotification(
            self::TYPE,
            "Large savings {$row->type}",
            "{$what} of ".Refuse::peso($amount)." on {$account->account_number} ({$member}), reference {$row->reference}.",
            "/savings/accounts/{$account->id}",
            ['account_id' => $account->id, 'transaction_id' => $row->id, 'reference' => $row->reference],
        ));
    }

    /**
     * @param  list<int>  $skip
     * @return Collection<int, User>
     */
    private function recipients(SavingsAccount $account, array $skip): Collection
    {
        return User::query()->active()->with(['roles', 'permissions'])->whereNotIn('id', $skip)->orderBy('id')->get()
            ->filter(fn (User $user) => $user->can('savings:approve') && $this->branches->canSee($user, $account->branch_id))
            ->values();
    }

    private function logFailure(SavingsTransaction $row, Throwable $e): void
    {
        Log::error('The large savings transaction alert could not be sent; the posting was kept.', [
            'savings_transaction_id' => $row->id,
            'exception' => $e,
        ]);
    }
}
