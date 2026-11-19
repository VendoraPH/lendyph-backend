<?php

namespace App\Services\Amla\Detection;

/**
 * What a rule found for one trigger event: the window it covers (its key is
 * unique per rule and subject), the transactions behind it, the reason in
 * words and the risk indicators, each with "Data not available" where Lendy
 * holds no value.
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
    ) {}
}
