<?php

namespace App\Services\Savings\Approvals;

use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\TimeDeposits\Quote;
use App\Services\Savings\TimeDeposits\Settlement;
use Illuminate\Validation\ValidationException;

/**
 * Carries out an approved pre-termination (`pretermination`, design §2): a
 * placement broken early whose cash payout was above tier 1. Under the
 * desk's locks it re-checks the placement is still running and that the
 * payout is still the approved amount, then breaks it as the checker. Its
 * own approval is no savings outflow, so no available-balance check is
 * involved.
 */
class PreterminationExecutor implements ApprovalExecutor
{
    public function __construct(
        private readonly Settlement $settlement,
        private readonly Quote $quote,
    ) {}

    public function accountIds(SavingsApproval $approval): array
    {
        if (($approval->payload['payout_method'] ?? null) !== 'savings') {
            return [];
        }

        $accountId = TimeDeposit::query()->whereKey($approval->time_deposit_id)->value('payout_account_id');

        return $accountId === null ? [] : [(int) $accountId];
    }

    public function timeDepositIds(SavingsApproval $approval): array
    {
        return $approval->time_deposit_id === null ? [] : [(int) $approval->time_deposit_id];
    }

    public function execute(SavingsApproval $approval, User $checker): ?SavingsTransaction
    {
        $deposit = TimeDeposit::query()->findOrFail($approval->time_deposit_id);
        $payload = $approval->payload ?? [];

        if ($deposit->status !== 'active') {
            throw ValidationException::withMessages(['approval' => [
                "{$deposit->certificate_number} is no longer running, so it can't be pre-terminated. Reject this request.",
            ]]);
        }

        $payout = $this->quote->pretermination($deposit, (string) $payload['date'])['payout'];

        if ($payout !== SavingsMath::centavos($approval->amount)) {
            throw ValidationException::withMessages(['approval' => [
                "{$deposit->certificate_number}'s payout is no longer the ₱".number_format(SavingsMath::centavos($approval->amount) / 100, 2).' approved. Reject this request and ask again.',
            ]]);
        }

        return $this->settlement->preterminate($deposit, (string) $payload['date'], (string) $payload['reason'], (string) $payload['payout_method'], $checker->id)['credit'];
    }
}
