<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsInterestRunLine;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\PeriodGuard;
use App\Services\AuditLogService;
use App\Services\Savings\AccruedInterest;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closing an account (design §2).
 *
 * The quote is the server's: the balance, the interest accrued since the last
 * crediting through the day before the closing date (AccruedInterest, the
 * interest run's own calculation), its withholding tax, and what is paid out.
 * It can't close while the account is closed or frozen, holds money, has a
 * request waiting for approval, or sits in an interest run that hasn't posted.
 *
 * Closing credits the accrued interest and withholds its tax (one
 * `savings_interest` journal), pays the whole balance out (`closing`, a
 * `savings` journal), then marks the account closed and frees its `open_key`.
 * A payout above tier 1 waits for a checker (CloseExecutor), reserving it.
 */
class AccountCloser
{
    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly SavingsLedger $ledger,
        private readonly AutomaticPoster $poster,
        private readonly AccruedInterest $accrued,
        private readonly ApprovalGate $gate,
        private readonly PeriodGuard $periods,
    ) {}

    /**
     * @return array{balance: string, accrued_interest: string, withholding_tax: string, payout_amount: string, can_close: bool, blockers: list<string>}
     */
    public function quote(SavingsAccount $account, ?string $date = null, ?SavingsApproval $own = null): array
    {
        $figures = $this->figures($account, $date ?? now()->toDateString());
        $blockers = $this->blockers($account, $own);

        return [
            'balance' => SavingsMath::pesos($figures['balance']),
            'accrued_interest' => SavingsMath::pesos($figures['gross']),
            'withholding_tax' => SavingsMath::pesos($figures['tax']),
            'payout_amount' => SavingsMath::pesos($figures['payout']),
            'can_close' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Close at once, or (above tier 1) open the approval.
     *
     * @param  array{date: string, reason: string, payout_method: string}  $data
     * @return array{account: SavingsAccount|null, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function close(int $accountId, array $data, User $user): array
    {
        $account = $this->locks->account($accountId);
        $this->assertCanClose($account);
        $payout = $this->figures($account, $data['date'])['payout'];

        if ($this->gate->needsApproval($payout)) {
            $this->periods->assertOpen($data['date'], 'This closing');

            return ['account' => null, 'approval' => $this->gate->open('close', $payout, [
                'account_id' => $account->id,
                'payload' => ['date' => $data['date'], 'reason' => $data['reason'], 'payout_method' => $data['payout_method']],
            ], $user->id)];
        }

        $this->execute($account, $data, $user->id, $user->id, null);

        return ['account' => $account->refresh(), 'approval' => null];
    }

    /**
     * Carry the close out under the account lock (the caller holds it).
     * Returns the payout row, else the interest row, else null.
     *
     * @param  array{date: string, reason: string, payout_method: string}  $data
     *
     * @throws ValidationException
     */
    public function execute(SavingsAccount $account, array $data, int $closerId, int $posterId, ?SavingsApproval $approval): ?SavingsTransaction
    {
        $this->assertCanClose($account, $approval);

        $date = $data['date'];
        $interest = $this->accrued->for($account, $date);
        $attributes = ['date' => $date, 'created_by' => $closerId, 'approval_id' => $approval?->id];
        $result = null;

        if ($interest['gross'] > 0) {
            $credit = $this->ledger->credit($account, 'interest', $interest['gross'], [
                ...$attributes, 'value_date' => $interest['to'], 'remarks' => 'Interest credited at closing',
            ]);
            $tax = $interest['tax'] > 0
                ? $this->ledger->debit($account, 'withholding_tax', $interest['tax'], [
                    ...$attributes, 'value_date' => $interest['to'], 'remarks' => 'Tax withheld from the interest credited at closing',
                ])
                : null;
            $this->poster->savingsInterestCredit($credit, $tax, $posterId);
            $result = $credit;
        }

        $balance = SavingsMath::signedCentavos($account->ledger_balance);

        if ($balance > 0) {
            $result = $this->ledger->debit($account, 'closing', $balance, [
                ...$attributes, 'method' => $data['payout_method'], 'remarks' => $data['reason'],
            ]);
            $this->poster->savingsTransaction($result, $posterId);
        }

        $before = $account->only(['status', 'closed_on', 'close_reason', 'closed_by', 'open_key', 'interest_credited_through']);
        $changes = [
            'status' => SavingsAccount::STATUS_CLOSED,
            'closed_on' => $date,
            'close_reason' => $data['reason'],
            'closed_by' => $closerId,
            'open_key' => null,
            'updated_at' => now(),
        ];

        if ($interest['days'] > 0) {
            $changes['interest_credited_through'] = $interest['to'];
        }

        DB::table('savings_accounts')->where('id', $account->id)->update($changes);
        $account->refresh();

        AuditLogService::log(
            'savings_account_closed',
            $account,
            $this->auditValues($before),
            $this->auditValues($account->only(array_keys($before))),
            "Savings account {$account->account_number} closed: {$data['reason']}. Paid out ".Refuse::peso($balance).'.',
        );

        return $result;
    }

    /**
     * Why it can't close now, in words (empty when it can).
     *
     * @return list<string>
     */
    public function blockers(SavingsAccount $account, ?SavingsApproval $own = null): array
    {
        if ($account->isClosed()) {
            return ['This account is already closed.'];
        }

        $blockers = [];

        if ($account->status === SavingsAccount::STATUS_FROZEN) {
            $blockers[] = 'The account is frozen. Unfreeze it before closing it.';
        }

        $held = SavingsHold::query()->where('account_id', $account->id)->holding(now()->toDateString())->sum('amount');

        if (SavingsMath::centavos($held) > 0) {
            $blockers[] = 'It has an active hold of '.Refuse::peso(SavingsMath::centavos($held)).'. Release the hold first.';
        }

        $waiting = SavingsApproval::query()
            ->where('account_id', $account->id)->where('status', 'pending')->where('expires_at', '>', now())
            ->when($own !== null, fn ($q) => $q->whereKeyNot($own->id))
            ->count();

        if ($waiting > 0) {
            $blockers[] = $waiting === 1
                ? 'A request on it is waiting for approval. Approve, reject or cancel it first.'
                : "{$waiting} requests on it are waiting for approval. Approve, reject or cancel them first.";
        }

        $inRun = SavingsInterestRunLine::query()
            ->where('account_id', $account->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['draft', 'approved']))
            ->exists();

        if ($inRun) {
            $blockers[] = 'It is in an interest run that has not posted yet. Post or reject the run first.';
        }

        return $blockers;
    }

    /** @throws ValidationException */
    private function assertCanClose(SavingsAccount $account, ?SavingsApproval $own = null): void
    {
        $blockers = $this->blockers($account, $own);

        if ($blockers !== []) {
            Refuse::field('account', implode(' ', $blockers));
        }
    }

    /**
     * @return array{balance: int, gross: int, tax: int, payout: int}
     */
    private function figures(SavingsAccount $account, string $date): array
    {
        $balance = SavingsMath::signedCentavos($account->ledger_balance);

        if ($account->isClosed()) {
            return ['balance' => $balance, 'gross' => 0, 'tax' => 0, 'payout' => 0];
        }

        $interest = $this->accrued->for($account, CarbonImmutable::parse($date)->toDateString());

        return [
            'balance' => $balance,
            'gross' => $interest['gross'],
            'tax' => $interest['tax'],
            'payout' => $balance + $interest['gross'] - $interest['tax'],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditValues(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $values);
    }
}
