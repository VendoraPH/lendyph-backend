<?php

namespace App\Services\Approvals;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * A loan approval step's optional minimum principal (Phase 6 MB-BE6,
 * multi-branch decision 10): `min_principal`, in pesos with two decimals, at
 * least ₱0.01, no default. A loan whose principal is below it has the step
 * left out when its chain is seeded (submit and re-submit).
 *
 * The Submit step, the first approval step and the Release step never carry
 * one, so every loan keeps at least one approval. Worked out in exact
 * decimals, never floats.
 */
final class StepMinimum
{
    public const KEY = 'min_principal';

    private const PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    /**
     * The minimum as stored ("50000.00"), or null when the value isn't a peso
     * amount of at least ₱0.01.
     */
    public static function normalise(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        $amount = BigDecimal::of($value)->toScale(2);

        return $amount->isLessThan(BigDecimal::of('0.01')) ? null : (string) $amount;
    }

    /**
     * Why a step at this position can't carry a minimum, in words, or null
     * when it can.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    public static function refusalFor(array $steps, int $index): ?string
    {
        $step = $steps[$index];
        $name = (string) ($step['name'] ?? 'A step');

        return match (true) {
            ($step['kind'] ?? null) === 'submit' => "Step \"{$name}\" can't have a minimum principal: the Submit step always applies.",
            ($step['kind'] ?? null) === 'release' => "Step \"{$name}\" can't have a minimum principal: the Release step always applies.",
            $index === self::firstApprovalIndex($steps) => "Step \"{$name}\" can't have a minimum principal: the first approval step always applies, so every loan keeps at least one approval.",
            default => null,
        };
    }

    /**
     * Whether the step applies to a loan with this principal.
     *
     * @param  array<string, mixed>  $step
     */
    public static function applies(array $step, string $principal): bool
    {
        $minimum = $step[self::KEY] ?? null;

        if ($minimum === null) {
            return true;
        }

        try {
            return ! BigDecimal::of($minimum)->isGreaterThan(BigDecimal::of($principal));
        } catch (MathException) {
            return true;
        }
    }

    /**
     * An amount in words for audit rows and refusals: "₱50,000.00".
     */
    public static function pesos(string $amount): string
    {
        $scaled = (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        [$whole, $cents] = explode('.', $scaled);

        return '₱'.number_format((int) $whole).'.'.$cents;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private static function firstApprovalIndex(array $steps): ?int
    {
        foreach ($steps as $index => $step) {
            if (($step['kind'] ?? null) === 'approve') {
                return $index;
            }
        }

        return null;
    }
}
