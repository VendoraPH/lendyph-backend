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
use App\Services\Savings\WithdrawalFee;

/**
 * An approved withdrawal (slice S2): re-checks, under the account lock, that
 * the account is still active and the money is still there (leaving out the
 * amount and fee this request itself reserves), then posts it as the maker's
 * withdrawal with its journal, linked to the approval. It charges the
 * excess-withdrawal fee decided when it was requested, never counting again;
 * the product's switch and limits are checked again, and a free count or fee
 * changed since the request answers 409 in words. One a member asked
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

        $decided = WithdrawalFee::storedOn($approval, (string) $payload['date']);
        $this->rules->assertStillDecided($account, $centavos, $decided);
        $this->teller->assertAvailable($account, $centavos + $decided->fee, $centavos + $decided->fee);

        return $this->teller->postWithdrawal($account, $centavos, $payload, $decided, $approval->requested_by, $approval->id, $checker->id, [$approval->first_checker_id]);
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
