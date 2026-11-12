<?php

namespace App\Services\Fees;

use App\Services\FeeAmount;
use Brick\Math\BigRational;

/**
 * An expression fee worked out on one loan ({@see FeeAmount::forExpression()}).
 *
 * - `raw`: the exact result.
 * - `rawAmount`: it cut to 6 decimals, half up (the snapshot's `raw_amount`).
 * - `roundedAmount`: it rounded half up to the centavo, before the bounds.
 * - `amount`: the figure charged, after `min_amount` and `max_amount`.
 * - `clamped`: "min" or "max" when a bound moved it, else null.
 */
final readonly class FeeFormulaResult
{
    public function __construct(
        public BigRational $raw,
        public string $rawAmount,
        public string $roundedAmount,
        public string $amount,
        public ?string $clamped,
    ) {}

    /** The charged figure as the deduction item's `amount` number. */
    public function amountAsFloat(): float
    {
        return (float) $this->amount;
    }
}
