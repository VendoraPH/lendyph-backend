<?php

namespace App\Services\Amla\Detection;

/**
 * What a rule found for one trigger event: the window it covers (its key is
 * unique per rule and subject), the transactions behind it, the reason in
 * words and the risk indicators, each with "Data not available" where Lendy
 * holds no value. `countedCentavos` and `countedTransactions` are the figures
 * the reason prints (null when it prints none), set by the rule itself, so an
 * alert's counted amount always says what its reason says.
 */
final class Finding
{
    public const NOT_AVAILABLE = 'Data not available';

    /**
     * @param  list<int>  $eventIds
     * @param  list<array{label: string, value: string}>  $indicators
     */
    public function __construct(
        public readonly string $key,
        public readonly string $windowStart,
        public readonly string $windowEnd,
        public readonly array $eventIds,
        public readonly string $reason,
        public readonly array $indicators,
        public readonly bool $headOffice = false,
        public readonly ?int $countedCentavos = null,
        public readonly ?int $countedTransactions = null,
    ) {}
}
