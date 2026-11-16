<?php

namespace App\Services\Linda\Model;

/**
 * The tokens one model call used, and the model that actually answered it
 * (with a server-side fallback, not always the one asked). LindaPrices turns
 * it into ₱.
 */
final class LindaUsage
{
    public function __construct(
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
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
