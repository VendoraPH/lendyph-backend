<?php

namespace App\Services\Savings\Approvals;

use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Portal\Requests\WithdrawalRequests;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\Api\Teller;
use App\Services\Savings\ProductRules;
use App\Services\Savings\SavingsMath;

/**
 * An approved withdrawal (slice S2): re-checks, under the account lock, that
 * the account is still active and the money is still there (leaving out the
 * amount and fee this request itself reserves), then posts it as the maker's
 * withdrawal with its journal, linked to the approval. The product rules are
 * checked again, and the excess-withdrawal fee worked out again without this
 * request in the month's count: a fee other than the one it reserved answers
 * 409 in words. One a member asked
 * for in the portal is paid only while that member's portal account and
 * membership are still active.
 */
class WithdrawalExecutor implements ApprovalExecutor
{
    public function __construct(
        private readonly Teller $teller,
        private readonly ProductRules $rules,
    ) {}

    public function accountIds(SavingsApproval $approval): array
    {
        return array_values(array_filter([$approval->account_id]));
    }

    public function timeDepositIds(SavingsApproval $approval): array
    {
        return [];
    }

    public function execute(SavingsApproval $approval, User $checker): ?SavingsTransaction
    {
        $account = SavingsAccount::query()->findOrFail($approval->account_id);
        $payload = $approval->payload ?? [];
        $centavos = SavingsMath::centavos($approval->amount);

        if (empty($payload['date']) || empty($payload['payment_method'])) {
            Refuse::field('approval', 'This request is missing its date or payment method. Reject it and make a new one.');
        }

        $this->assertMemberRequestStillPayable($approval);
        $this->teller->assertCanPayOut($account);

        $reservedFee = SavingsMath::centavos($approval->fee_amount ?? 0);
        $fee = $this->rules->assertCanWithdraw($account, $centavos, (string) $payload['date'], $approval->id);

        if ($fee !== $reservedFee) {
            Refuse::conflict(Teller::feeChanged($fee, $reservedFee));
        }

        $this->teller->assertAvailable($account, $centavos + $fee, $centavos + $reservedFee);

        return $this->teller->postWithdrawal($account, $centavos, $payload, $approval->requested_by, $approval->id, $checker->id, [$approval->first_checker_id], $reservedFee);
    }

    /**
     * A withdrawal a member asked for in the portal is paid only while their
     * portal account and membership are both still active (portal design §1):
     * a bulk update that ended them fires no events, so nothing cancelled
     * this approval. 409 in words; the checker rejects it instead.
     */
    private function assertMemberRequestStillPayable(SavingsApproval $approval): void
    {
        $request = PortalRequest::query()->where('savings_approval_id', $approval->id)->first();

        if ($request !== null) {
            WithdrawalRequests::assertStillAMember($request);
        }
    }
}
