<?php

namespace App\Services\Savings\Api;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\SavingsNumbers;
use Illuminate\Validation\ValidationException;

/**
 * Opens a savings account (design §2): for an active member, on an active
 * regular product, with an opening deposit of at least the product's minimum
 * (none when the minimum is ₱0.00). One open account per member per product.
 *
 * Runs inside IdempotentWrite's transaction. Locks the member row first (lock
 * step 2), so two openings for one member queue and the second sees the
 * first's account (409) instead of colliding on `open_key`; the account
 * number is the next one (step 6, retried on a collision); the opening
 * deposit goes through SavingsLedger and its journal is posted last.
 */
class AccountOpener
{
    public function __construct(
        private readonly SavingsNumbers $numbers,
        private readonly SavingsLedger $ledger,
        private readonly AutomaticPoster $poster,
    ) {}

    /**
     * @param  array{borrower_id: int|string, product_id: int|string, opened_at: string, initial_deposit: string, payment_method?: string|null, reference?: string|null, remarks?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function open(array $data, User $user): SavingsAccount
    {
        $member = Borrower::query()->whereKey((int) $data['borrower_id'])->lockForUpdate()->firstOrFail();
        $product = SavingsProduct::query()->findOrFail((int) $data['product_id']);
        $deposit = SavingsMath::centavos($data['initial_deposit']);

        if ($member->status !== 'active') {
            Refuse::field('borrower_id', "{$member->full_name} is not an active member, so a savings account can't be opened for them.");
        }

        if ($member->branch_id === null) {
            Refuse::field('borrower_id', "{$member->full_name} has no branch yet. Assign the member to a branch first.");
        }

        if ($product->kind !== SavingsProduct::KIND_REGULAR) {
            Refuse::field('product_id', "{$product->name} is a time deposit product. Place a time deposit instead.");
        }

        if (! $product->is_active) {
            Refuse::field('product_id', "{$product->name} is turned off, so no account can be opened on it.");
        }

        $minimum = SavingsMath::centavos($product->minimum_opening_balance);

        if ($deposit < $minimum) {
            Refuse::field('initial_deposit', "{$product->name} needs an opening deposit of at least ".Refuse::peso($minimum).'.');
        }

        if ($deposit > 0 && empty($data['payment_method'])) {
            Refuse::field('payment_method', 'Choose how the opening deposit was paid.');
        }

        $openKey = SavingsAccount::openKey($member->id, $product->id);

        if (SavingsAccount::query()->where('open_key', $openKey)->exists()) {
            Refuse::conflict("{$member->full_name} already has an open {$product->name} account. A member holds one open account per product.");
        }

        $account = SavingsAccount::query()->create([
            'account_number' => $this->numbers->accountNumber($data['opened_at']),
            'borrower_id' => $member->id,
            'product_id' => $product->id,
            'branch_id' => $member->branch_id,
            'status' => SavingsAccount::STATUS_ACTIVE,
            'ledger_balance' => '0.00',
            'opened_on' => $data['opened_at'],
            'last_member_activity_on' => $data['opened_at'],
            'open_key' => $openKey,
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $user->id,
        ]);

        if ($deposit > 0) {
            $row = $this->ledger->credit($account, 'deposit', $deposit, [
                'date' => $data['opened_at'],
                'method' => $data['payment_method'],
                'external_reference' => $data['reference'] ?? null,
                'remarks' => 'Opening deposit',
                'created_by' => $user->id,
            ]);
            $this->poster->savingsTransaction($row, $user->id);
        }

        return $account->refresh();
    }
}
