<?php

namespace App\Services\Cda\Sources;

use Illuminate\Support\Str;

/**
 * Resolves a catalogue key (CDA design §2): checks and defaults the params,
 * finds the family's resolver by convention (`{Family}Source` in this
 * namespace) and memoizes the result per context and params.
 */
final class SourceRegistry
{
    /**
     * @param  array<string, mixed>  $params
     *
     * @throws \InvalidArgumentException for an unknown key or bad params
     * @throws SourceUnavailable when the family's resolver isn't built
     */
    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        $normalized = SourceCatalog::normalize($key, $params);
        ksort($normalized);

        return $context->remember($key.'|'.json_encode($normalized), function () use ($key, $normalized, $context): SourceResult {
            $class = self::resolverClass($key);

            if (! class_exists($class) || ! is_subclass_of($class, CdaSource::class)) {
                throw SourceUnavailable::noResolver($key, class_basename($class));
            }

            return app($class)->resolve($key, $normalized, $context);
        });
    }

    /** Whether the key's family has a resolver yet. */
    public function has(string $key): bool
    {
        $class = self::resolverClass($key);

        return SourceCatalog::has($key) && class_exists($class) && is_subclass_of($class, CdaSource::class);
    }

    /** @return class-string<CdaSource> */
    public static function resolverClass(string $key): string
    {
        return __NAMESPACE__.'\\'.Str::studly(SourceCatalog::family($key)).'Source';
    }
}
