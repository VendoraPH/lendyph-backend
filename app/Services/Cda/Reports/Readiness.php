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

    /**
     * A locked report's readiness with some checks read live (its documents
     * needed at filing): the frozen figures, with each live check that now
     * differs from how it was counted moved between passed and failed, in
     * the totals, its component and its section, and the percents worked
     * out again. A check counts as frozen-failed when its id is in the
     * breakdown's `failed` list. A `covered` check (one evaluate() no longer
     * counts) is taken into account only when it was counted as failed. A
     * breakdown without its check list, or a check whose component wasn't
     * counted, is left as it is. Nothing is written.
     *
     * @param  array<string, mixed>  $breakdown  the frozen breakdown
     * @param  list<array{id: string, component: string, section: string|null, passed: bool, covered: bool}>  $live
     * @return array{readiness: int, breakdown: array<string, mixed>}
     */
    public static function withLiveChecks(int $readiness, array $breakdown, array $live): array
    {
        if (! isset($breakdown['failed'], $breakdown['passed'], $breakdown['total']) || ! is_array($breakdown['failed'])) {
            return ['readiness' => $readiness, 'breakdown' => $breakdown];
        }

        $failed = array_values($breakdown['failed']);
        $changed = false;

        foreach ($live as $check) {
            $wasFailed = in_array($check['id'], $failed, true);

            if (($check['covered'] && ! $wasFailed) || $wasFailed !== $check['passed'] || ! isset($breakdown['by_component'][$check['component']])) {
                continue;
            }

            $step = $check['passed'] ? 1 : -1;
            $breakdown['passed'] += $step;
            $breakdown['by_component'][$check['component']]['passed'] += $step;

            if ($check['section'] !== null && isset($breakdown['by_section'][$check['section']])) {
                $breakdown['by_section'][$check['section']]['passed'] += $step;
            }

            $failed = $check['passed']
                ? array_values(array_filter($failed, fn (string $id) => $id !== $check['id']))
                : [...$failed, $check['id']];
            $changed = true;
        }

        if (! $changed) {
            return ['readiness' => $readiness, 'breakdown' => $breakdown];
        }

        $breakdown['by_component'] = self::withPercent($breakdown['by_component']);
        $breakdown['by_section'] = self::withPercent($breakdown['by_section'] ?? []);
        $breakdown['failed'] = $failed;

        return ['readiness' => self::percent((int) $breakdown['passed'], (int) $breakdown['total']), 'breakdown' => $breakdown];
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
