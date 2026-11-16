<?php

namespace App\Services\Linda\Model;

/**
 * The tokens one model call used, and the model that actually answered it
 * (with a server-side fallback, not always the one asked). LindaPrices turns
 * it into ₱.
 */
final class LindaUsage
{
    /**
     * @param  list<LindaUsage>  $parts  with a server-side fallback, each sampling
     *                                   iteration on its own model: the cost is
     *                                   their sum
     * @param  list<string>  $priceAtHighestOf  other models these tokens may have
     *                                          run on (a fallback that lists no
     *                                          iterations): every token is priced
     *                                          at the highest price of each kind
     *                                          among them and `model`
     */
    public function __construct(
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly array $parts = [],
        public readonly array $priceAtHighestOf = [],
    ) {}

    /**
     * @return array{model: string, input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ];
    }
}
