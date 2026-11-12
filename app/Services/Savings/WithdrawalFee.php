<?php

namespace App\Services\Savings;

use App\Models\PortalRequest;
use App\Models\SavingsApproval;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A withdrawal's excess-withdrawal fee, decided once, when the withdrawal is
 * requested (gap round S1), by ProductRules::decideWithdrawal():
 *
 * - `fee`: centavos (0 within the free withdrawals). The payout charges it,
 *   or less when something counted before it has fallen away since
 *   (ProductRules::payoutFee()); never more;
 * - `countedOn`: the date whose Manila month the withdrawal counts in, once
 *   (the request's date; it goes on the posted row as `counted_on`);
 * - `requestedAt`: when it was asked for (`Y-m-d H:i:s`, Manila), which
 *   orders the count at payout;
 * - `freeWithdrawals` and `excessFee`: the product's rule then (null =
 *   unlimited, or no fee), so a payout can tell whether the rule changed;
 * - `source` and `sourceId`: the approval or portal request it is stored
 *   on, left out of its own recount (null for a counter withdrawal decided
 *   and posted at once).
 *
 * Approvals and portal requests store it in `fee_amount`, `counted_on`,
 * `requested_at`, `rule_free_withdrawals` and `rule_excess_fee`.
 */
final readonly class WithdrawalFee
{
    public const SOURCE_APPROVAL = 'approval';

    public const SOURCE_PORTAL_REQUEST = 'portal_request';

    public function __construct(
        public int $fee,
        public string $countedOn,
        public string $requestedAt,
        public ?int $freeWithdrawals,
        public ?int $excessFee,
        public ?string $source = null,
        public ?int $sourceId = null,
    ) {}

    /**
     * The decision stored on an approval or a portal request. A row stored
     * before the columns existed counts on `$fallbackDate`, from its
     * `created_at`, with no rule.
     */
    public static function storedOn(SavingsApproval|PortalRequest $row, string $fallbackDate): self
    {
        $rule = $row->getAttribute('rule_excess_fee');

        return new self(
            SavingsMath::centavos($row->getAttribute('fee_amount') ?? 0),
            self::day($row->getAttribute('counted_on')) ?? $fallbackDate,
            self::moment($row->getAttribute('requested_at') ?? $row->getAttribute('created_at')) ?? now()->format('Y-m-d H:i:s'),
            $row->getAttribute('rule_free_withdrawals') === null ? null : (int) $row->getAttribute('rule_free_withdrawals'),
            $rule === null ? null : SavingsMath::centavos($rule),
            $row instanceof SavingsApproval ? self::SOURCE_APPROVAL : self::SOURCE_PORTAL_REQUEST,
            (int) $row->getKey(),
        );
    }

    /** The same decision, stored on `$row` (an approval it moves to). */
    public function on(Model $row): self
    {
        return new self($this->fee, $this->countedOn, $this->requestedAt, $this->freeWithdrawals, $this->excessFee,
            $row instanceof SavingsApproval ? self::SOURCE_APPROVAL : self::SOURCE_PORTAL_REQUEST, (int) $row->getKey());
    }

    /** The same decision charging `$fee` (never more than decided). */
    public function charging(int $fee): self
    {
        return new self(min($fee, $this->fee), $this->countedOn, $this->requestedAt, $this->freeWithdrawals, $this->excessFee, $this->source, $this->sourceId);
    }

    /**
     * The columns an approval or a portal request stores it in.
     *
     * @return array{fee_amount: string, counted_on: string, requested_at: string, rule_free_withdrawals: int|null, rule_excess_fee: string|null}
     */
    public function columns(): array
    {
        return [
            'fee_amount' => SavingsMath::pesos($this->fee),
            'counted_on' => $this->countedOn,
            'requested_at' => $this->requestedAt,
            'rule_free_withdrawals' => $this->freeWithdrawals,
            'rule_excess_fee' => $this->excessFee === null ? null : SavingsMath::pesos($this->excessFee),
        ];
    }

    private static function day(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof CarbonInterface => $value->toDateString(),
            default => substr((string) $value, 0, 10),
        };
    }

    private static function moment(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof CarbonInterface => $value->format('Y-m-d H:i:s'),
            default => substr((string) $value, 0, 19),
        };
    }
}
