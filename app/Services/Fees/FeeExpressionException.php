<?php

namespace App\Services\Fees;

use RuntimeException;

/**
 * Why a fee formula can't be used, in words.
 *
 * - `INVALID`: the formula itself is refused (at save, and by
 *   POST /fees/formula-check). The message is the one shown on
 *   `formula_expression`.
 * - `DIVIDES_BY_ZERO`: on a real loan, a divisor worked out to zero.
 *   `detail` names the variable when the divisor is one ("Term in Days is
 *   0").
 * - `NEGATIVE`: on a real loan, the result is below ₱0.00.
 *
 * The callers that charge a fee turn the last two into a 422 on `fees`,
 * naming the fee ({@see self::messageForFee()}).
 */
final class FeeExpressionException extends RuntimeException
{
    public const INVALID = 'invalid';

    public const DIVIDES_BY_ZERO = 'divides_by_zero';

    public const NEGATIVE = 'negative';

    private function __construct(public readonly string $reason, string $message, public readonly ?string $detail = null)
    {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message);
    }

    public static function dividesByZero(?string $detail): self
    {
        return new self(self::DIVIDES_BY_ZERO, 'divides by zero'.($detail === null ? '' : " ({$detail})"), $detail);
    }

    public static function negative(): self
    {
        return new self(self::NEGATIVE, 'works out below ₱0.00');
    }

    /**
     * The 422 message on `fees` for a fee that can't be charged on a loan.
     */
    public function messageForFee(string $feeName): string
    {
        return match ($this->reason) {
            self::NEGATIVE => "The fee \"{$feeName}\" works out below ₱0.00 for this loan.",
            self::DIVIDES_BY_ZERO => "The fee \"{$feeName}\" can't be worked out for this loan: it {$this->getMessage()}.",
            default => "The fee \"{$feeName}\" can't be worked out for this loan: {$this->getMessage()}",
        };
    }

    /**
     * The 422 message on `sample` for POST /fees/formula-check.
     */
    public function messageForSample(): string
    {
        return match ($this->reason) {
            self::NEGATIVE => 'On the sample loan the formula works out below ₱0.00.',
            self::DIVIDES_BY_ZERO => 'On the sample loan the formula '.$this->getMessage().'.',
            default => $this->getMessage(),
        };
    }
}
