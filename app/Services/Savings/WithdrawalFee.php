<?php

namespace App\Services\Savings;

use Illuminate\Database\Eloquent\Model;

/**
 * A withdrawal's excess-withdrawal fee, decided once, when the withdrawal is
 * requested (gap round S1), by ProductRules::decideWithdrawal():
 *
 * - `fee`: centavos (0 within the free withdrawals);
 * - `countedOn`: the date whose Manila month the withdrawal counts in, once
 *   (the request's date; it goes on the posted row as `counted_on`);
 * - `freeWithdrawals` and `excessFee`: the product's rule then (null =
 *   unlimited, or no fee), so a payout can tell whether the rule changed
 *   since (409) without counting again.
 *
 * Approvals and portal requests store it in `fee_amount`, `counted_on`,
 * `rule_free_withdrawals` and `rule_excess_fee`.
 */
final readonly class WithdrawalFee
{
    public function __construct(
        public int $fee,
        public string $countedOn,
        public ?int $freeWithdrawals,
        public ?int $excessFee,
    ) {}

    /**
     * The decision stored on an approval or a portal request. A row stored
     * before the columns existed counts on `$fallbackDate` with no rule.
     */
    public static function storedOn(Model $row, string $fallbackDate): self
    {
        $countedOn = $row->getAttribute('counted_on');
        $rule = $row->getAttribute('rule_excess_fee');

        return new self(
            SavingsMath::centavos($row->getAttribute('fee_amount') ?? 0),
            $countedOn === null ? $fallbackDate : (is_string($countedOn) ? substr($countedOn, 0, 10) : $countedOn->toDateString()),
            $row->getAttribute('rule_free_withdrawals') === null ? null : (int) $row->getAttribute('rule_free_withdrawals'),
            $rule === null ? null : SavingsMath::centavos($rule),
        );
    }

    /**
     * The columns an approval or a portal request stores it in.
     *
     * @return array{fee_amount: string, counted_on: string, rule_free_withdrawals: int|null, rule_excess_fee: string|null}
     */
    public function columns(): array
    {
        return [
            'fee_amount' => SavingsMath::pesos($this->fee),
            'counted_on' => $this->countedOn,
            'rule_free_withdrawals' => $this->freeWithdrawals,
            'rule_excess_fee' => $this->excessFee === null ? null : SavingsMath::pesos($this->excessFee),
        ];
    }
}
