<?php

namespace App\Services\Linda\Model;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * What a model question costs, in ₱.
 *
 * The prices are the configured USD list prices per million tokens
 * (`config('linda.models')`, strings) × `config('linda.usd_to_php')`, worked
 * out in exact decimals (Brick\Math), never floats. A question costs a
 * fraction of a centavo, so cost() keeps 6 decimal places; what staff see is
 * rounded half up to two.
 *
 * The price is the one of the model that answered. A dated id
 * ("claude-sonnet-5-5-20261101") takes its alias's price. Any other model is
 * priced at the highest configured price of each kind, so an unknown model
 * can only overstate the month's cost against the cap, never understate it.
 * Missing or unreadable prices fail closed (`no_prices`).
 */
final class LindaPrices
{
    /** The token kinds, as named in the price table. */
    private const KINDS = ['input', 'output', 'cache_read', 'cache_write'];

    private const COST_SCALE = 6;

    private const MILLION = 1_000_000;

    /** Whether a model id is one of the configured models (exactly). */
    public static function knows(string $model): bool
    {
        $models = config('linda.models');

        return $model !== '' && is_array($models) && is_array($models[$model] ?? null);
    }

    /**
     * The cost of one call in ₱, at 6 decimal places, half up.
     *
     * @throws LindaModelUnavailable no_prices
     */
    public static function cost(LindaUsage $usage): string
    {
        return self::pesos($usage->model, [
            'input' => $usage->inputTokens,
            'output' => $usage->outputTokens,
            'cache_read' => $usage->cacheReadTokens,
            'cache_write' => $usage->cacheWriteTokens,
        ])->toScale(self::COST_SCALE, RoundingMode::HalfUp)->toString();
    }

    /**
     * About what one question costs in ₱, two decimals half up, at the
     * typical size in `config('linda.typical_question')`.
     *
     * @throws LindaModelUnavailable no_prices
     */
    public static function perQuestion(string $model): string
    {
        $typical = (array) config('linda.typical_question', []);

        return self::pesos($model, [
            'input' => (int) ($typical['input'] ?? 0),
            'output' => (int) ($typical['output'] ?? 0),
            'cache_read' => (int) ($typical['cache_read'] ?? 0),
            'cache_write' => (int) ($typical['cache_write'] ?? 0),
        ])->toScale(2, RoundingMode::HalfUp)->toString();
    }

    /**
     * The model's prices per million tokens in ₱, two decimals half up.
     *
     * @return array{input: string, output: string, cache_read: string, cache_write: string}
     *
     * @throws LindaModelUnavailable no_prices
     */
    public static function perMillion(string $model): array
    {
        $rate = self::rate();
        $prices = self::prices($model);
        $result = [];

        foreach (self::KINDS as $kind) {
            $result[$kind] = $prices[$kind]->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp)->toString();
        }

        /** @var array{input: string, output: string, cache_read: string, cache_write: string} $result */
        return $result;
    }

    /**
     * @param  array<string, int>  $tokens
     *
     * @throws LindaModelUnavailable no_prices
     */
    private static function pesos(string $model, array $tokens): BigDecimal
    {
        $prices = self::prices($model);
        $usd = BigDecimal::zero();

        foreach (self::KINDS as $kind) {
            $usd = $usd->plus($prices[$kind]->multipliedBy(max(0, $tokens[$kind])));
        }

        return $usd->multipliedBy(self::rate())->dividedByExact(self::MILLION);
    }

    /**
     * The USD prices per million tokens that apply to a model.
     *
     * @return array<string, BigDecimal>
     *
     * @throws LindaModelUnavailable no_prices
     */
    private static function prices(string $model): array
    {
        $table = self::table();

        if (isset($table[$model])) {
            return $table[$model];
        }

        foreach (self::aliasesLongestFirst($table) as $alias) {
            if (str_starts_with($model, $alias.'-') && ctype_digit(substr($model, strlen($alias) + 1))) {
                return $table[$alias];
            }
        }

        $highest = [];
        foreach (self::KINDS as $kind) {
            $highest[$kind] = BigDecimal::max(...array_column($table, $kind));
        }

        return $highest;
    }

    /**
     * Every configured model's prices, read exactly.
     *
     * @return non-empty-array<string, array<string, BigDecimal>>
     *
     * @throws LindaModelUnavailable no_prices
     */
    private static function table(): array
    {
        $models = config('linda.models');

        if (! is_array($models) || $models === []) {
            throw new LindaModelUnavailable('no_prices');
        }

        $table = [];
        foreach ($models as $id => $entry) {
            if (! is_array($entry)) {
                throw new LindaModelUnavailable('no_prices');
            }

            foreach (self::KINDS as $kind) {
                $table[(string) $id][$kind] = self::decimal($entry[$kind] ?? null);
            }
        }

        return $table;
    }

    /**
     * @throws LindaModelUnavailable no_prices
     */
    private static function rate(): BigDecimal
    {
        $rate = self::decimal(config('linda.usd_to_php'));

        if (! $rate->isPositive()) {
            throw new LindaModelUnavailable('no_prices');
        }

        return $rate;
    }

    /**
     * A price as written in config: a plain non-negative decimal.
     *
     * @throws LindaModelUnavailable no_prices
     */
    private static function decimal(mixed $value): BigDecimal
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new LindaModelUnavailable('no_prices');
        }

        $value = trim((string) $value);

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            throw new LindaModelUnavailable('no_prices');
        }

        try {
            return BigDecimal::of($value);
        } catch (MathException) {
            throw new LindaModelUnavailable('no_prices');
        }
    }

    /**
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    private static function aliasesLongestFirst(array $table): array
    {
        $aliases = array_map('strval', array_keys($table));
        usort($aliases, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $aliases;
    }
}
