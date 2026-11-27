<?php

namespace App\Services\Cda\Pesos;

use App\Services\Cda\Sources\SourceCatalog;

/**
 * The Enhanced PESOS engine (CDA design §8). Pure: the template's `pesos`
 * block and each indicator's resolved figures go in, the scores come out. It
 * reads nothing and writes nothing.
 *
 * Formulas (the template names one by key; the maths is code):
 * - `ratio`: numerator ÷ denominator × 100; a zero denominator can't be
 *   computed.
 * - `ratio_avg`: numerator ÷ the average of the denominator's opening and
 *   closing balances × 100.
 * - `growth`: (current − prior) ÷ |prior| × 100, the numerator being the
 *   current figure and the denominator the prior one.
 * - `coverage`: like `ratio`, but nothing to cover (a zero denominator) is
 *   fully covered, 100.00.
 *
 * Every figure is an integer in hundredths, so the ratio is an exact rational
 * and the result is rounded half up (away from zero) to two decimals once.
 *
 * Scoring v1 is by bands, on that rounded value (the one shown). An edge goes
 * to the better band: a `higher_better` band holds min ≤ v < max and a
 * `lower_better` band holds min < v ≤ max (null is unbounded); the first band
 * that holds the value scores its points. An indicator with missing data or
 * a figure that can't be computed scores nothing, and the rating stays open
 * (`complete: false`) until every indicator is scored. The rating is the
 * highest rating band whose minimum the total reaches.
 *
 * Everything here is a placeholder until checked against the official
 * MC 2025-12 tool: `verified` false, and the TO_VERIFY list.
 */
final class PesosEngine
{
    public const STATUS_SCORED = 'scored';

    public const STATUS_MISSING = 'missing_data';

    public const STATUS_NOT_COMPUTABLE = 'not_computable';

    /** @var list<string> design §8, what to check against the official tool */
    public const TO_VERIFY = [
        'The components and indicators',
        'Each numerator and denominator and the account lines behind it',
        'Whether a ratio uses opening, closing or average balances',
        'The score bands and their points',
        'The weights',
        'The adjectival rating bands',
        'Rounding',
        'Division by zero and missing data',
        'Limits by asset size or cooperative type',
        'The official sample inputs and outputs (the golden test)',
        'The disclosure wording',
    ];

    public const DISCLOSURE = 'Enhanced PESOS figures follow a placeholder template still to be checked against the official CDA MC 2025-12 tool. They are a guide only until a verified version is active.';

    /**
     * @param  array{components: list<array<string, mixed>>, indicators: list<array<string, mixed>>, rating_bands: list<array<string, mixed>>}  $pesos
     * @param  array<string, array{numerator?: string|int|null, denominator?: string|int|null, denominator_opening?: string|int|null, missing?: list<string>, sources?: array<string, string>}>  $inputs  per indicator key
     * @return array{verified: bool, complete: bool, score: string|null, rating: string|null, components: list<array<string, mixed>>, indicators: list<array<string, mixed>>, to_verify: list<string>, disclosure: string}
     */
    public function evaluate(array $pesos, array $inputs, bool $verified = false): array
    {
        $indicators = array_map(
            fn (array $indicator) => $this->indicator($indicator, $inputs[$indicator['key']] ?? []),
            array_values($pesos['indicators'] ?? []),
        );

        $complete = $indicators !== [] && array_all($indicators, fn (array $row) => $row['status'] === self::STATUS_SCORED);
        $scored = array_filter($indicators, fn (array $row) => $row['points'] !== null);
        $total = $scored === [] ? null : array_sum(array_column($scored, 'points'));

        $components = array_map(function (array $component) use ($indicators): array {
            $mine = array_filter($indicators, fn (array $row) => $row['component'] === $component['key'] && $row['points'] !== null);

            return [
                'key' => $component['key'],
                'label' => $component['label'],
                'weight' => self::format(self::hundredths($component['weight'])),
                'score' => $mine === [] ? null : self::format(array_sum(array_column($mine, 'points'))),
            ];
        }, array_values($pesos['components'] ?? []));

        return [
            'verified' => $verified,
            'complete' => $complete,
            'score' => $total === null ? null : self::format($total),
            'rating' => $complete && $total !== null ? $this->rating($pesos['rating_bands'] ?? [], $total) : null,
            'components' => $components,
            'indicators' => array_map(function (array $row): array {
                unset($row['points']);

                return $row;
            }, $indicators),
            'to_verify' => self::TO_VERIFY,
            'disclosure' => self::DISCLOSURE,
        ];
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function indicator(array $indicator, array $input): array
    {
        $formula = (string) $indicator['formula_key'];
        $numerator = self::hundredthsOrNull($input['numerator'] ?? null);
        $denominator = self::hundredthsOrNull($input['denominator'] ?? null);
        $opening = self::hundredthsOrNull($input['denominator_opening'] ?? null);
        $missing = $numerator === null || $denominator === null || ($formula === 'ratio_avg' && $opening === null);

        [$value, $note] = $missing
            ? [null, 'Missing data: '.implode('; ', $input['missing'] ?? ['a figure it needs is not available']).'.']
            : $this->compute($formula, $numerator, $denominator, $opening);

        $status = match (true) {
            $missing => self::STATUS_MISSING,
            $value === null => self::STATUS_NOT_COMPUTABLE,
            default => self::STATUS_SCORED,
        };

        $band = $value === null ? null : $this->band($indicator['bands'] ?? [], (string) $indicator['direction'], $value);
        $points = $value === null ? null : ($band === null ? 0 : self::hundredths($indicator['bands'][$band]['points']));

        if ($value !== null && $band === null) {
            $note = 'The value is outside every score band.';
        }

        return [
            'key' => $indicator['key'],
            'component' => $indicator['component'],
            'label' => $indicator['label'],
            'formula_key' => $formula,
            'formula' => $this->formulaText($formula, $indicator),
            'direction' => $indicator['direction'],
            'unit' => $indicator['unit'] ?? 'percent',
            'numerator' => $numerator === null ? null : self::format($numerator),
            'denominator' => $denominator === null ? null : self::format($denominator),
            'denominator_opening' => $opening === null ? null : self::format($opening),
            'sources' => $input['sources'] ?? [],
            'value' => $value === null ? null : self::format($value),
            'score' => $points === null ? null : self::format($points),
            'weight' => self::format(self::hundredths($indicator['weight'])),
            'band' => $band,
            'recommendation' => $band === null ? null : ($indicator['bands'][$band]['recommendation'] ?? null),
            'status' => $status,
            'note' => $note,
            'to_verify' => true,
            'points' => $points,
        ];
    }

    /**
     * The value in hundredths of a percent, rounded half up, or null with
     * the reason it can't be computed.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function compute(string $formula, int $numerator, int $denominator, ?int $opening): array
    {
        return match ($formula) {
            'ratio' => $denominator === 0
                ? [null, 'The denominator is zero, so the ratio can\'t be computed.']
                : [self::percentOf($numerator, $denominator), null],
            'coverage' => $denominator === 0
                ? [10_000, 'Nothing to cover: read as fully covered.']
                : [self::percentOf($numerator, $denominator), null],
            'ratio_avg' => $denominator + (int) $opening === 0
                ? [null, 'The average balance is zero, so the ratio can\'t be computed.']
                : [self::percentOf(2 * $numerator, $denominator + (int) $opening), null],
            'growth' => $denominator === 0
                ? [null, 'The prior figure is zero, so growth can\'t be computed.']
                : [self::percentOf($numerator - $denominator, abs($denominator)), null],
            default => [null, "The formula \"{$formula}\" is not known."],
        };
    }

    /** a ÷ b × 100 in hundredths, rounded half away from zero. */
    private static function percentOf(int $a, int $b): int
    {
        if ($b < 0) {
            [$a, $b] = [-$a, -$b];
        }

        $scaled = $a * 10_000;
        $quotient = intdiv(abs($scaled), $b);
        $remainder = abs($scaled) % $b;

        if (2 * $remainder >= $b) {
            $quotient++;
        }

        return $scaled < 0 ? -$quotient : $quotient;
    }

    /** @param  list<array<string, mixed>>  $bands */
    private function band(array $bands, string $direction, int $value): ?int
    {
        foreach ($bands as $index => $band) {
            $min = $band['min'] === null ? null : self::hundredths($band['min']);
            $max = $band['max'] === null ? null : self::hundredths($band['max']);

            $holds = $direction === 'lower_better'
                ? ($min === null || $value > $min) && ($max === null || $value <= $max)
                : ($min === null || $value >= $min) && ($max === null || $value < $max);

            if ($holds) {
                return $index;
            }
        }

        return null;
    }

    /** @param  list<array<string, mixed>>  $bands */
    private function rating(array $bands, int $total): ?string
    {
        usort($bands, fn (array $a, array $b) => self::hundredths($b['min']) <=> self::hundredths($a['min']));

        foreach ($bands as $band) {
            if ($total >= self::hundredths($band['min'])) {
                return (string) $band['label'];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $indicator */
    private function formulaText(string $formula, array $indicator): string
    {
        $name = fn (string $side): string => SourceCatalog::KEYS[$indicator[$side]['key'] ?? '']['label'] ?? 'its figure';
        $a = $name('numerator');
        $b = $name('denominator');

        return match ($formula) {
            'ratio' => "{$a} ÷ {$b} × 100",
            'coverage' => "{$a} ÷ {$b} × 100 (fully covered when nothing is required)",
            'ratio_avg' => "{$a} ÷ the average of opening and closing {$b} × 100",
            'growth' => "({$a} this year − last year) ÷ last year × 100",
            default => $formula,
        };
    }

    /** A decimal figure ("1500.25", 12, "4.995") in hundredths, rounded half up; null stays null. */
    private static function hundredthsOrNull(string|int|float|null $value): ?int
    {
        return $value === null ? null : self::hundredths($value);
    }

    private static function hundredths(string|int|float $value): int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        $text = is_float($value) ? sprintf('%.6F', $value) : trim($value);
        $negative = str_starts_with($text, '-');
        [$whole, $decimals] = array_pad(explode('.', ltrim($text, '+-'), 2), 2, '');
        $decimals = str_pad($decimals, 3, '0');
        $result = (int) $whole * 100 + (int) substr($decimals, 0, 2) + ((int) $decimals[2] >= 5 ? 1 : 0);

        return $negative ? -$result : $result;
    }

    /** Hundredths as a 2-decimal string. */
    private static function format(int $hundredths): string
    {
        return sprintf('%s%d.%02d', $hundredths < 0 ? '-' : '', intdiv(abs($hundredths), 100), abs($hundredths) % 100);
    }
}
