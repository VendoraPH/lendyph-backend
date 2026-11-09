<?php

namespace App\Services\CreditScoring;

use App\Models\CreditScorecardRevision;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * A scorecard revision's rules, read for scoring: the categories, the active
 * factors with their point bands, the risk bands and the policy rules.
 *
 * Bands are tried in order. `at_least` takes the first band whose threshold
 * the value reaches, `at_most` the first whose threshold the value does not
 * exceed, and a value no band takes earns the factor's `otherwise` points.
 *
 * A category's weight scales its factors: each factor's points count
 * weight / (the category's active factor points). Weights are read in
 * hundredths of a percent, never rounded.
 */
final class FactorTable
{
    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $factors
     * @param  list<array<string, mixed>>  $riskBands
     * @param  list<array<string, mixed>>  $policyRules
     */
    public function __construct(
        public readonly int $revisionId,
        public readonly string $version,
        private readonly array $categories,
        private readonly array $factors,
        private readonly array $riskBands,
        private readonly array $policyRules,
    ) {}

    public static function fromRevision(CreditScorecardRevision $revision): self
    {
        return new self(
            (int) $revision->id,
            (string) $revision->version,
            $revision->categories ?? [],
            $revision->factors ?? [],
            $revision->risk_bands ?? [],
            $revision->policy_rules ?? [],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(): array
    {
        return array_values($this->categories);
    }

    /**
     * The points of the category's active factors, all of them, with data
     * or not: what its weight is spread over.
     */
    public function categoryFactorPoints(string $categoryKey): int
    {
        return (int) array_sum(array_map(
            fn (array $factor) => (int) $factor['points'],
            array_filter($this->factors(), fn (array $factor) => $factor['category_key'] === $categoryKey),
        ));
    }

    /**
     * The category's weight in hundredths of a percent, or null when the
     * revision has no such category.
     */
    public function weightHundredths(string $categoryKey): ?int
    {
        foreach ($this->categories as $category) {
            if ($category['key'] === $categoryKey) {
                return self::hundredths($category['weight_percent']);
            }
        }

        return null;
    }

    /**
     * A percent with at most two decimals, in hundredths, read from its
     * shortest decimal form so 16.67 is 1667 exactly. More decimals are an
     * error: a weight is never rounded.
     */
    public static function hundredths(int|float|string $percent): int
    {
        $text = is_string($percent) ? trim($percent) : json_encode($percent, JSON_PRESERVE_ZERO_FRACTION);

        if (! is_string($text) || preg_match('/^(\d+)(?:\.(\d{1,2})0*)?$/', $text, $parts) !== 1) {
            throw new UnexpectedValueException("A weight must be a number with at most two decimals, not '{$text}'.");
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    /**
     * The active factors, in the revision's order.
     *
     * @return list<array<string, mixed>>
     */
    public function factors(): array
    {
        return array_values(array_filter($this->factors, fn (array $factor) => (bool) ($factor['is_active'] ?? true)));
    }

    /**
     * Points for a raw value. `$defaulted` applies the factor's
     * `default_points` first, where it has one.
     *
     * @param  array<string, mixed>  $factor
     */
    public function points(array $factor, int|float $value, bool $defaulted = false): int
    {
        if ($defaulted && array_key_exists('default_points', $factor)) {
            return (int) $factor['default_points'];
        }

        $rule = $factor['rule'] ?? null;

        foreach ($factor['bands'] ?? [] as $band) {
            $threshold = (float) $band['threshold'];

            $takes = match ($rule) {
                'at_least' => $value >= $threshold,
                'at_most' => $value <= $threshold,
                default => throw new InvalidArgumentException("Unknown factor rule '{$rule}' on factor '{$factor['key']}'."),
            };

            if ($takes) {
                return (int) $band['points'];
            }
        }

        return (int) ($factor['otherwise'] ?? 0);
    }

    /**
     * The band a score falls in: the highest band whose minimum it reaches.
     *
     * @return array<string, mixed>
     */
    public function bandFor(int $score): array
    {
        $bands = $this->riskBands;
        usort($bands, fn (array $a, array $b) => $b['min_score'] <=> $a['min_score']);

        foreach ($bands as $band) {
            if ($score >= (int) $band['min_score']) {
                return $band;
            }
        }

        return end($bands) ?: ['key' => 'very_high', 'label' => 'Very High Risk', 'min_score' => 0, 'recommendation' => ''];
    }

    /**
     * Whether a policy rule is switched on in this revision. A rule the
     * revision doesn't list is off.
     */
    public function policyRuleIsActive(string $key): bool
    {
        foreach ($this->policyRules as $rule) {
            if (($rule['key'] ?? null) === $key) {
                return (bool) ($rule['is_active'] ?? true);
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function policyRule(string $key): ?array
    {
        foreach ($this->policyRules as $rule) {
            if (($rule['key'] ?? null) === $key) {
                return $rule;
            }
        }

        return null;
    }
}
