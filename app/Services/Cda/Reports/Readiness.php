<?php

namespace App\Services\Cda\Reports;

use App\Services\Savings\SavingsMath;

/**
 * Readiness (design §4): equal-weighted checks. Each required field, each
 * rule, each required document and the one approval check counts once; a
 * check passes when it raised no Critical or Warning (an Info rule always
 * passes). Readiness = round half up (100 × passed ÷ total), with the same
 * figure by component and by section.
 */
final class Readiness
{
    public const LABEL = 'Lendy CDA Readiness';

    /**
     * @param  list<array{id: string, component: string, section: string|null, passed: bool}>  $checks
     * @return array{readiness: int, breakdown: array<string, mixed>}
     */
    public static function of(array $checks): array
    {
        $byComponent = [];
        $bySection = [];

        foreach ($checks as $check) {
            self::tally($byComponent, $check['component'], $check['passed']);

            if ($check['section'] !== null) {
                self::tally($bySection, $check['section'], $check['passed']);
            }
        }

        $passed = count(array_filter($checks, fn (array $check) => $check['passed']));
        $total = count($checks);

        return [
            'readiness' => self::percent($passed, $total),
            'breakdown' => [
                'passed' => $passed,
                'total' => $total,
                'by_component' => self::withPercent($byComponent),
                'by_section' => self::withPercent($bySection),
                'failed' => array_values(array_map(fn (array $check) => $check['id'], array_filter($checks, fn (array $check) => ! $check['passed']))),
            ],
        ];
    }

    /** The period's readiness: the mean of the applicable reports' readiness, half up. */
    public static function mean(array $readiness): int
    {
        return $readiness === [] ? 0 : SavingsMath::roundHalfUp(array_sum($readiness), count($readiness));
    }

    public static function percent(int $passed, int $total): int
    {
        return $total === 0 ? 0 : SavingsMath::roundHalfUp(100 * $passed, $total);
    }

    private static function tally(array &$buckets, string $key, bool $passed): void
    {
        $buckets[$key] ??= ['passed' => 0, 'total' => 0];
        $buckets[$key]['total']++;
        $buckets[$key]['passed'] += $passed ? 1 : 0;
    }

    private static function withPercent(array $buckets): array
    {
        return array_map(fn (array $bucket) => [...$bucket, 'percent' => self::percent($bucket['passed'], $bucket['total'])], $buckets);
    }
}
