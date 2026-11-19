<?php

namespace App\Services\Amla\Cdd;

use App\Models\AmlaRiskRuleVersion;
use RuntimeException;

/**
 * The AML risk scoring rules and their versions (AMLA design §4.9). Version 1
 * holds the documented starting points, written by the CDD migration; a
 * change goes through an approved `risk_rules` change request and adds a
 * version. Every version is "to verify": starting points, not legal
 * thresholds.
 *
 * The factors are fixed by the code (RiskScorer reads each one); a version
 * sets their points. None reads gender, civil status, age, spouse fields or
 * an address below the country.
 */
final class RiskRules
{
    /** @var array<string, list<string>> each factor's values, as RiskScorer sets them */
    public const FACTOR_VALUES = [
        'customer_type' => ['member', 'walk_in'],
        'id_verification' => ['verified', 'not_verified', 'expired', 'failed'],
        'occupation' => ['provided', 'missing'],
        'source_of_funds' => ['salary', 'pension', 'savings', 'business', 'remittance', 'other', 'missing'],
        'expected_activity' => ['provided', 'missing'],
        'geography' => ['domestic', 'foreign', 'high_risk_country', 'missing'],
        'channel' => ['no_gcash', 'gcash'],
        'pep' => ['not_pep', 'pep', 'pep_family', 'pep_associate', 'missing'],
        'adverse_info' => ['none', 'flagged'],
    ];

    public const MAX_POINTS = 100;

    public const MAX_THRESHOLD = 1000;

    /** The newest version, in force now. */
    public function current(): AmlaRiskRuleVersion
    {
        return AmlaRiskRuleVersion::query()->orderByDesc('version')->first()
            ?? throw new RuntimeException('The AML risk rules have no version. The AMLA CDD migration writes version 1.');
    }

    /**
     * What is wrong with a proposed definition, by field, in words. Every
     * factor and value must be present with whole points from 0 to 100, the
     * thresholds must rise (medium below high), and the countries must be
     * two-letter codes.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public static function definitionErrors(array $definition): array
    {
        $errors = [];
        $factors = $definition['factors'] ?? null;

        if (! is_array($factors) || ! self::sameKeys($factors, array_keys(self::FACTOR_VALUES))) {
            $errors['definition.factors'] = 'List every risk factor, and only those: '.implode(', ', array_keys(self::FACTOR_VALUES)).'.';
        } else {
            foreach (self::FACTOR_VALUES as $factor => $values) {
                $points = $factors[$factor];

                if (! is_array($points) || ! self::sameKeys($points, $values)) {
                    $errors['definition.factors'] = "Give points for each value of \"{$factor}\": ".implode(', ', $values).'.';

                    break;
                }

                foreach ($points as $value) {
                    if (! is_int($value) || $value < 0 || $value > self::MAX_POINTS) {
                        $errors['definition.factors'] = 'Points are whole numbers from 0 to '.self::MAX_POINTS.'.';

                        break 2;
                    }
                }
            }
        }

        $thresholds = $definition['thresholds'] ?? null;

        if (! is_array($thresholds) || ! self::sameKeys($thresholds, ['medium', 'high'])
            || ! is_int($thresholds['medium']) || ! is_int($thresholds['high'])
            || $thresholds['medium'] < 1 || $thresholds['high'] > self::MAX_THRESHOLD
            || $thresholds['medium'] >= $thresholds['high']) {
            $errors['definition.thresholds'] = 'Give whole-number Medium and High thresholds, Medium below High, from 1 to '.self::MAX_THRESHOLD.'.';
        }

        $countries = $definition['high_risk_countries'] ?? null;

        if (! is_array($countries) || ! array_is_list($countries) || count($countries) !== count(array_unique($countries))
            || array_filter($countries, fn ($code) => ! is_string($code) || preg_match('/^[A-Z]{2}$/', $code) !== 1) !== []) {
            $errors['definition.high_risk_countries'] = 'List high-risk countries as distinct two-letter codes, such as KP.';
        }

        return $errors;
    }

    /**
     * A valid definition in the canonical order: factors and their values as
     * FACTOR_VALUES lists them, thresholds medium then high, countries sorted.
     *
     * @param  array<string, mixed>  $definition
     * @return array{factors: array<string, array<string, int>>, thresholds: array{medium: int, high: int}, high_risk_countries: list<string>}
     */
    public static function normalise(array $definition): array
    {
        $factors = [];

        foreach (self::FACTOR_VALUES as $factor => $values) {
            foreach ($values as $value) {
                $factors[$factor][$value] = (int) $definition['factors'][$factor][$value];
            }
        }

        $countries = array_values(array_unique(array_map('strval', $definition['high_risk_countries'])));
        sort($countries);

        return [
            'factors' => $factors,
            'thresholds' => ['medium' => (int) $definition['thresholds']['medium'], 'high' => (int) $definition['thresholds']['high']],
            'high_risk_countries' => $countries,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $array
     * @param  list<string>  $keys
     */
    private static function sameKeys(array $array, array $keys): bool
    {
        $given = array_map('strval', array_keys($array));
        sort($given);
        sort($keys);

        return $given === $keys;
    }

    /**
     * The top-level parts of a definition that differ from another: factors,
     * thresholds, high_risk_countries.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<string>
     */
    public static function changedSections(array $from, array $to): array
    {
        return array_values(array_filter(
            ['factors', 'thresholds', 'high_risk_countries'],
            fn (string $section) => ($from[$section] ?? null) != ($to[$section] ?? null),
        ));
    }
}
