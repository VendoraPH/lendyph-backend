<?php

namespace App\Services\Savings\TimeDeposits;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Savings\AvailableBalance;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\SavingsNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Placing a time deposit (design §2, §4, §5), inside the caller's
 * transaction (IdempotentWrite's), in the design's lock order: the savings
 * accounts it touches (funding and payout) in one id-ordered statement, then
 * the generated certificate number, then the journal last.
 *
 * - Members only, and only an active one, in the caller's branches (the
 *   request's `exists` rule); the placement sits in the member's branch.
 * - The figures are {@see Quote::figures()}, so the quote equals the
 *   placement; the rates and the tax rate are snapshotted on the row.
 * - The placement date is today or yesterday (back-dating only until the
 *   next morning, decision 8).
 * - Funded in cash, by bank, GCash or Maya (Dr that money account), or from
 *   the member's own active savings account in the same branch (Dr savings
 *   deposits), never above that account's available balance (422).
 * - A rollover ({@see self::rollover()}) is a new placement funded by the
 *   one it came from: no money moves and it posts no journal of its own,
 *   because the settlement credits the rolled-over amount to time deposits.
 */
class Placement
{
    /** The `funding_method` stored on a rollover's own placement. */
    public const ROLLOVER_FUNDING = 'rollover';

    public function __construct(
        private readonly Quote $quote,
        private readonly SavingsLocks $locks,
        private readonly SavingsLedger $ledger,
        private readonly AvailableBalance $available,
        private readonly SavingsNumbers $numbers,
        private readonly AutomaticPoster $poster,
    ) {}

    /**
     * @param  array{borrower_id: int, product_id: int, principal: string, term_days: int, placement_date: string, maturity_instruction: string,
     *               payment_method: string, reference?: string|null, funding_account_id?: int|null, payout_account_id?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function place(array $data, User $user): TimeDeposit
    {
        $this->assertInTransaction();

        $member = Borrower::query()->findOrFail((int) $data['borrower_id']);

        if ($member->status !== 'active') {
            $this->refuse('borrower_id', "{$member->full_name} is not an active member, so they can't place a time deposit.");
        }

        $date = $this->placementDate((string) $data['placement_date']);
        $product = SavingsProduct::query()->findOrFail((int) $data['product_id']);
        $principal = SavingsMath::centavos($data['principal']);
        $figures = $this->quote->figures($product, $principal, (int) $data['term_days'], $date);

        $fromSavings = $data['payment_method'] === 'savings';
        $fundingId = $fromSavings ? ($data['funding_account_id'] ?? null) : null;
        $payoutId = $data['payout_account_id'] ?? null;

        if ($fromSavings && $fundingId === null) {
            $this->refuse('funding_account_id', 'Pick the savings account that pays for this placement.');
        }

        $accounts = $this->locks->accounts(array_filter([$fundingId, $payoutId]));
        $funding = $fundingId === null ? null : $this->ownAccount($accounts->get((int) $fundingId), $member, 'funding_account_id');
        $payout = $payoutId === null ? null : $this->ownAccount($accounts->get((int) $payoutId), $member, 'payout_account_id');

        if ($funding !== null) {
            $this->assertCanFund($funding, $principal);
        }

        if ($payout !== null) {
            $this->assertCanReceive($payout, 'payout_account_id');
        }

        $deposit = TimeDeposit::query()->create([
            ...$this->contract($figures),
            'certificate_number' => $this->numbers->certificateNumber($date),
            'borrower_id' => $member->id,
            'product_id' => $product->id,
            'branch_id' => $member->branch_id,
            'maturity_instruction' => $data['maturity_instruction'],
            'funding_method' => $data['payment_method'],
            'payout_account_id' => $payout?->id,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        if ($funding !== null) {
            $row = $this->ledger->debit($funding, 'withdrawal', $principal, [
                'date' => $date,
                'method' => 'savings',
                'external_reference' => $data['reference'] ?? null,
                'remarks' => "Placed in time deposit {$deposit->certificate_number}",
                'time_deposit_id' => $deposit->id,
                'created_by' => $user->id,
            ]);
            $deposit->forceFill(['funding_transaction_id' => $row->id])->saveQuietly();
        }

        $this->poster->timeDepositPlacement($deposit, $user->id);

        return $deposit;
    }

    /**
     * Place again what a matured placement rolls over (design §5): the same
     * product, term and payout account, at the rate, tax rate and
     * pre-termination rate in force on its maturity date. It starts on the
     * day it is settled ($placementDate), the day the settlement journal
     * moves the money into it, so time deposits in the books and in the
     * subledger agree every day. The next instruction is staff's choice at
     * settlement, else the old placement's. Refused (422) when the product is
     * turned off, no longer offers the term, or has no rate for it.
     *
     * @throws ValidationException
     */
    public function rollover(TimeDeposit $from, int $principal, ?int $userId, string $placementDate, ?string $nextInstruction = null): TimeDeposit
    {
        $this->assertInTransaction();

        $product = SavingsProduct::query()->findOrFail($from->product_id);
        $figures = $this->quote->figures($product, $principal, $from->term_days, $placementDate, rollover: true, ratesOn: $from->maturity_date->toDateString());

        return TimeDeposit::query()->create([
            ...$this->contract($figures),
            'certificate_number' => $this->numbers->certificateNumber($placementDate),
            'borrower_id' => $from->borrower_id,
            'product_id' => $from->product_id,
            'branch_id' => $from->branch_id,
            'maturity_instruction' => $nextInstruction ?? $from->maturity_instruction,
            'funding_method' => self::ROLLOVER_FUNDING,
            'payout_account_id' => $from->payout_account_id,
            'status' => 'active',
            'rolled_over_from_id' => $from->id,
            'created_by' => $userId,
        ]);
    }

    /**
     * Refuses an account that can't receive a time deposit's payout: one that
     * is closed or frozen.
     *
     * @throws ValidationException
     */
    public function assertCanReceive(SavingsAccount $account, string $field): void
    {
        if (in_array($account->status, [SavingsAccount::STATUS_CLOSED, SavingsAccount::STATUS_FROZEN], true)) {
            $this->refuse($field, "{$account->account_number} is {$account->status}, so it can't receive the payout.");
        }
    }

    /**
     * The snapshotted contract columns.
     *
     * @param  array{principal: int, annual_rate: string, pretermination_rate: string|null, withholding_tax_rate: string, term_days: int,
     *               placement_date: string, maturity_date: string, gross: int, tax: int, net: int, maturity: int}  $figures
     * @return array<string, mixed>
     */
    private function contract(array $figures): array
    {
        return [
            'principal' => SavingsMath::pesos($figures['principal']),
            'annual_rate' => $figures['annual_rate'],
            'pretermination_rate' => $figures['pretermination_rate'],
            'withholding_tax_rate' => $figures['withholding_tax_rate'],
            'term_days' => $figures['term_days'],
            'placement_date' => $figures['placement_date'],
            'maturity_date' => $figures['maturity_date'],
            'gross_interest' => SavingsMath::pesos($figures['gross']),
            'withholding_tax' => SavingsMath::pesos($figures['tax']),
            'net_interest' => SavingsMath::pesos($figures['net']),
            'maturity_amount' => SavingsMath::pesos($figures['maturity']),
        ];
    }

    /** @throws ValidationException */
    private function placementDate(string $value): string
    {
        $today = CarbonImmutable::parse(now()->toDateString());
        $date = CarbonImmutable::parse($value)->startOfDay();

        if ($date->greaterThan($today)) {
            $this->refuse('placement_date', "The placement date can't be after today ({$today->format('M j, Y')}).");
        }

        if ($date->lessThan($today->subDay())) {
            $this->refuse('placement_date', 'A placement can be back-dated only to yesterday ('.$today->subDay()->format('M j, Y').').');
        }

        return $date->toDateString();
    }

    /**
     * The member's own account in their own branch; anything else reads as
     * unknown, like a missing one.
     *
     * @throws ValidationException
     */
    private function ownAccount(?SavingsAccount $account, Borrower $member, string $field): SavingsAccount
    {
        if ($account === null || $account->borrower_id !== $member->id) {
            $this->refuse($field, "That savings account isn't one of {$member->full_name}'s.");
        }

        if ($account->branch_id !== $member->branch_id) {
            $this->refuse($field, "{$account->account_number} is in another branch. A time deposit can only move money within the member's own branch.");
        }

        return $account;
    }

    /** @throws ValidationException */
    private function assertCanFund(SavingsAccount $account, int $principal): void
    {
        if ($account->status !== SavingsAccount::STATUS_ACTIVE) {
            $this->refuse('funding_account_id', "{$account->account_number} is {$account->status}; only an active account can pay for a placement.");
        }

        $available = $this->available->for($account);

        if ($principal > $available) {
            $this->refuse('principal', "The savings account's available balance is ".$this->peso($available).', short of the '.$this->peso($principal).' to place.');
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A time deposit is placed only inside a transaction.');
        }
    }

    private function peso(int $centavos): string
    {
        return '₱'.number_format($centavos / 100, 2);
    }

    /** @throws ValidationException */
    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
