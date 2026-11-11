<?php

namespace App\Services\Cda\Pesos;

use App\Services\Cda\Sources\SourceCatalog;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use App\Services\Cda\Sources\SourceResult;
use App\Services\Cda\Sources\SourceUnavailable;
use InvalidArgumentException;

/**
 * One PESOS figure (a numerator, denominator or opening balance) through the
 * source registry (CDA design §8). A source that isn't available (not built,
 * unknown, bad params) or gives no number is missing data, never an error:
 * the reason is added to `$missing` and null comes back. PESOS never reads
 * its own result.
 *
 * Not final, so a test can stand in for the sources.
 */
class PesosFigures
{
    public function __construct(private readonly SourceRegistry $registry) {}

    /**
     * @param  array<string, mixed>|null  $spec  {key, params?}
     * @param  list<string>  $missing
     */
    public function resolve(?array $spec, SourceContext $context, array &$missing): ?SourceResult
    {
        $key = (string) ($spec['key'] ?? '');
        $label = SourceCatalog::KEYS[$key]['label'] ?? $key;

        if (! SourceCatalog::has($key) || SourceCatalog::family($key) === 'pesos') {
            $missing[] = "{$label} is not a figure PESOS can use";

            return null;
        }

        try {
            $result = $this->registry->resolve($key, (array) ($spec['params'] ?? []), $context);
        } catch (SourceUnavailable|InvalidArgumentException $e) {
            $missing[] = $e->getMessage();

            return null;
        }

        if ($result->value === null || (! is_int($result->value) && ! is_numeric($result->value))) {
            $missing[] = "{$result->label} has no figure";

            return null;
        }

        return $result;
    }
}
