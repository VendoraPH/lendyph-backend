<?php

namespace App\Services\Portal\Requests;

use App\Http\Requests\Savings\SavingsRules;
use App\Models\Borrower;
use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Portal\PortalSettings;
use App\Services\Savings\Api\HoldDesk;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\Api\Teller;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\AvailableBalance;
use App\Services\Savings\ProductRules;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\WithdrawalFee;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A member's savings withdrawal request (portal design §6). It reuses
 * Savings: no second ledger and no second approval engine.
 *
 * 1. **Submit** (the member, inside IdempotentWrite's portal store): lock the
 *    account, check it pays out, the product's withdrawal rules and the
 *    month's free count (ProductRules, gap round S1), and that the amount
 *    plus any excess-withdrawal fee is within what can be withdrawn now
 *    (AvailableBalance, read under the lock). The fee is decided here, once,
 *    and stored on the request (`fee_amount`, `counted_on` and the product's
 *    rule then); insert the request
 *    (`live_key` `w:{account}`), then place a `member_request` hold
 *    (HoldDesk::placeForRequest) for the amount plus the fee, which reserves
 *    the money at once.
 * 2. **Approve** (staff, inside the savings IdempotentWrite): lock the
 *    account, then the request (status re-checked), release the hold and
 *    withdraw through the Teller as the staff user, under that same lock, so
 *    the reservation never lapses in between. Up to tier 1 it posts with its
 *    journal (completed); above, it opens a savings approval that reserves the
 *    money (approved), and the existing checkers decide it (maker ≠ checker,
 *    the tiers).
 * 3. **Reject, cancel, expiry**: the hold is released (or expired); no money
 *    moves.
 *
 * Lock order: idempotency row, savings account, portal request, member,
 * portal account, hold, then the Teller's approval or transaction, numbers
 * and journal (savings §4).
 */
final class WithdrawalRequests
{
    /** The portal's payout methods, and the savings payment method each one is (staff choose the actual one at payout). */
    public const METHODS = ['cash_pickup' => 'cash', 'bank_transfer' => 'bank_transfer', 'gcash' => 'gcash'];

    public const NOT_A_MEMBER_NOW = 'This member\'s portal access or membership isn\'t active, so the request can\'t be paid. Reject it instead.';

    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly HoldDesk $holds,
        private readonly Teller $teller,
        private readonly AvailableBalance $available,
        private readonly PortalSettings $settings,
        private readonly RequestTimeline $timeline,
        private readonly RequestNumbers $numbers,
        private readonly ApprovalGate $gate,
        private readonly ProductRules $rules,
    ) {}

    /**
     * The member's details rules, before any lock.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(PortalUser $portalUser): array
    {
        $methods = array_values(array_intersect(array_keys(self::METHODS), $this->settings->current()->withdrawal_methods ?? array_keys(self::METHODS)));

        return [
            'details.account_id' => ['required', 'integer', Rule::exists('savings_accounts', 'id')->where('borrower_id', $portalUser->borrower_id)],
            'details.amount' => ['required', ...SavingsRules::money('amount'), 'gt:0'],
            'details.method' => ['required', 'string', Rule::in($methods)],
            'details.bank_name' => ['exclude_unless:details.method,bank_transfer', 'required', 'string', 'max:100'],
            'details.account_name' => ['exclude_unless:details.method,bank_transfer', 'required', 'string', 'max:150'],
            'details.account_number' => ['exclude_unless:details.method,bank_transfer', 'required', 'string', 'regex:/^[0-9][0-9 \-]{3,33}$/'],
            'details.gcash_number' => ['exclude_unless:details.method,gcash', 'required', 'string', 'regex:/^(\+?63|0)9\d{9}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages(['details.amount' => 'amount']),
            'details.account_id.required' => 'Choose the savings account to withdraw from.',
            'details.account_id.integer' => 'Choose one of your own savings accounts.',
            'details.account_id.exists' => 'Choose one of your own savings accounts.',
            'details.amount.required' => 'Enter the amount to withdraw.',
            'details.amount.gt' => 'The amount must be above ₱0.00.',
            'details.method.required' => 'Choose how you want to receive the money.',
            'details.method.in' => 'Choose one of the payout methods your cooperative offers.',
            'details.bank_name.required' => 'Enter the bank\'s name.',
            'details.account_name.required' => 'Enter the name on the bank account.',
            'details.account_number.required' => 'Enter the bank account number.',
            'details.account_number.regex' => 'Enter the bank account number in digits.',
            'details.gcash_number.required' => 'Enter your GCash number.',
            'details.gcash_number.regex' => 'Enter your GCash number, like 09171234567.',
        ];
    }

    /**
     * Submit, inside the caller's transaction (the portal IdempotentWrite).
     *
     * @param  array<string, mixed>  $details  validated
     *
     * @throws ValidationException
     */
    public function submit(PortalUser $portalUser, array $details): PortalRequest
    {
        $account = $this->locks->account((int) $details['account_id']);
        $centavos = SavingsMath::centavos($details['amount']);

        $this->assertMemberCanWithdraw($account);

        $liveKey = 'w:'.$account->id;

        if (PortalRequest::query()->where('live_key', $liveKey)->exists()) {
            Refuse::conflict("You already have an open withdrawal request on {$account->account_number}. Cancel it, or wait for your branch's decision.");
        }

        $decided = $this->rules->decideWithdrawal($account, $centavos, now()->toDateString(), 'details.amount');
        $fee = $decided->fee;
        $free = $this->available->for($account);

        if ($centavos + $fee > $free) {
            Refuse::field('details.amount', $fee === 0
                ? Refuse::peso($centavos).' is more than the '.Refuse::peso($free)." you can withdraw from {$account->account_number} now."
                : Refuse::peso($centavos).' plus the '.Refuse::peso($fee).' excess-withdrawal fee is more than the '.Refuse::peso($free)." you can withdraw from {$account->account_number} now.");
        }

        $expiresOn = now()->addDays(max(1, (int) ($this->settings->current()->withdrawal_hold_days ?? 7)))->toDateString();

        $request = RequestDesk::insertOpen([
            'reference' => $this->numbers->next(),
            'portal_user_id' => $portalUser->id,
            'borrower_id' => $portalUser->borrower_id,
            'branch_id' => $account->branch_id,
            'type' => PortalRequest::TYPE_SAVINGS_WITHDRAWAL,
            'status' => PortalRequest::STATUS_SUBMITTED,
            'details' => $this->storedDetails($details),
            'amount' => SavingsMath::pesos($centavos),
            ...$decided->columns(),
            'savings_account_id' => $account->id,
            'expires_on' => $expiresOn,
            'live_key' => $liveKey,
        ], "You already have an open withdrawal request on {$account->account_number}.");

        $hold = $this->holds->placeForRequest($account, $request, $centavos + $fee, $expiresOn);
        $request->forceFill(['savings_hold_id' => $hold->id])->save();

        return $request;
    }

    /**
     * Approve as `$staff`, inside the savings IdempotentWrite's transaction.
     *
     * @param  array{date: string, method: string, reference?: string|null}  $payout
     * @return array{request: PortalRequest, transaction: SavingsTransaction|null, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function approve(PortalRequest $request, array $payout, User $staff, ?string $message = null): array
    {
        $account = $this->locks->account((int) $request->savings_account_id);
        $locked = RequestDesk::lockOpen($request->id, [PortalRequest::STATUS_SUBMITTED, PortalRequest::STATUS_UNDER_REVIEW, PortalRequest::STATUS_NEED_INFO]);
        self::assertStillAMember($locked);
        $hold = $this->activeHold($locked);

        // Above tier 1 nothing is paid yet: the savings approval the Teller
        // opens reserves the money as a pending outflow, under this same
        // account lock, so the hold hands over to it (keeping both would
        // reserve the money twice). The reason says which happened.
        $reason = $this->gate->needsApprovalFor($account, SavingsMath::centavos($locked->amount))
            ? "Moved to its second approval on request {$locked->reference}; the savings approval now reserves the money"
            : "Paid out on request {$locked->reference}";
        $this->holds->releaseForRequest($hold, $reason, $staff);

        $result = $this->teller->withdraw($account->id, [
            'amount' => (string) $locked->amount,
            'date' => $payout['date'],
            'payment_method' => $payout['method'],
            'reference' => $payout['reference'] ?? null,
            'remarks' => "Member portal request {$locked->reference}",
        ], $staff, WithdrawalFee::storedOn($locked, $locked->created_at?->toDateString() ?? $payout['date']));

        if ($result['transaction'] !== null) {
            $this->timeline->move($locked, PortalRequest::STATUS_COMPLETED, PortalRequestEvent::ACTOR_STAFF, $staff->id, $message, [
                'savings_transaction_id' => $result['transaction']->id,
                'decided_by' => $staff->id,
                'decided_at' => now(),
            ]);
        } else {
            $this->timeline->move($locked, PortalRequest::STATUS_APPROVED, PortalRequestEvent::ACTOR_STAFF, $staff->id, $message, [
                'savings_approval_id' => $result['approval']->id,
                'decided_by' => $staff->id,
                'decided_at' => now(),
            ]);
        }

        return ['request' => $locked, 'transaction' => $result['transaction'], 'approval' => $result['approval']];
    }

    /**
     * Release a request's hold when it closes without a payout: by staff
     * (reject), or by nobody (the member cancelling, the system). The account
     * is locked first. A hold already released or expired is left as it is.
     */
    public function releaseHold(PortalRequest $request, string $reason, ?User $staff): ?SavingsHold
    {
        if ($request->savings_hold_id === null) {
            return null;
        }

        $this->locks->account((int) $request->savings_account_id);
        $hold = SavingsHold::query()->whereKey($request->savings_hold_id)->lockForUpdate()->first();

        if ($hold === null || $hold->status !== SavingsHold::STATUS_ACTIVE) {
            return null;
        }

        return $this->holds->releaseForRequest($hold, $reason, $staff);
    }

    /**
     * No payout once the member's portal access or membership has ended,
     * even where a bulk update skipped the events that cancel the request:
     * the portal account and the member must both still be active (409).
     * Read from the rows themselves, never a model event, at staff approval
     * and again at the savings second check (WithdrawalExecutor).
     *
     * The member and the portal account are both read under their row
     * locks, member first, so a payout waits for a status change in flight
     * (inactive, blacklisted, withdrawn, deceased: MembershipStatus::end, a
     * deactivation, PortalAccess::disable()) and then sees it. Lock order:
     * the savings account and the request (the caller's), then the member,
     * then the portal account, then the hold. Nothing takes them the other
     * way round: a membership change locks the member, then the portal
     * account (the Borrower observer), and code issuing does the same;
     * disable() locks only the portal account; each ends the requests only
     * after it commits.
     */
    public static function assertStillAMember(PortalRequest $request): void
    {
        $memberStatus = Borrower::query()->whereKey($request->borrower_id)->lockForUpdate()->value('status');
        $portalUser = PortalUser::query()->whereKey($request->portal_user_id)->lockForUpdate()->first(['id', 'status']);

        if ($portalUser?->status !== PortalUser::STATUS_ACTIVE || $memberStatus !== 'active') {
            Refuse::conflict(self::NOT_A_MEMBER_NOW);
        }
    }

    /** The request's hold, still holding money today; 409 otherwise. */
    private function activeHold(PortalRequest $request): SavingsHold
    {
        $hold = SavingsHold::query()->whereKey($request->savings_hold_id)->lockForUpdate()->first();

        if ($hold === null || $hold->status !== SavingsHold::STATUS_ACTIVE
            || ($hold->expires_on !== null && $hold->expires_on->toDateString() < now()->toDateString())) {
            Refuse::conflict("The money request {$request->reference} reserved is no longer held (its hold ended). It can't be paid out; the member can send a new request.");
        }

        return $hold;
    }

    /** @throws ValidationException */
    private function assertMemberCanWithdraw(SavingsAccount $account): void
    {
        $product = $account->product;

        if ($product === null || ! (bool) $product->portal_withdrawal_requests) {
            Refuse::field('details.account_id', 'Withdrawals from '.($product?->name ?? 'this account').' can\'t be requested in the portal. Please visit your branch.');
        }

        $message = match ($account->status) {
            SavingsAccount::STATUS_ACTIVE => null,
            SavingsAccount::STATUS_DORMANT, SavingsAccount::STATUS_FROZEN => "{$account->account_number} is {$account->status}. Please visit your branch to withdraw from it.",
            default => "{$account->account_number} is closed.",
        };

        if ($message !== null) {
            Refuse::field('details.account_id', $message);
        }
    }

    /**
     * What the request keeps: the payout method and its account details. The
     * amount and account have their own columns.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function storedDetails(array $details): array
    {
        $kept = ['method' => $details['method']];

        foreach (['bank_name', 'account_name', 'account_number', 'gcash_number'] as $field) {
            if (isset($details[$field])) {
                $kept[$field] = trim((string) $details[$field]);
            }
        }

        return $kept;
    }
}
