<?php

namespace Tests\Feature\Cda\Outputs;

use App\Services\Cda\Pesos\PesosEngine;
use Tests\TestCase;

/**
 * The Enhanced PESOS engine (CDA design §8) is pure and table-driven: the
 * template's indicators, bands and rating bands go in with the resolved
 * numerators and denominators, and the scores come out, every one marked
 * "to verify" against the official MC 2025-12 tool.
 *
 * Band edges go to the better band: higher_better bands take min ≤ v < max,
 * lower_better bands take min < v ≤ max, on the value rounded half up to two
 * decimals (the one shown).
 */
class PesosEngineTest extends TestCase
{
    private function definition(array $indicator = []): array
    {
        return [
            'components' => [['key' => 'quality', 'label' => 'Portfolio quality', 'weight' => 100]],
            'indicators' => [array_merge([
                'key' => 'par',
                'component' => 'quality',
                'label' => 'Portfolio at risk',
                'formula_key' => 'ratio',
                'numerator' => ['key' => 'loans.past_due'],
                'denominator' => ['key' => 'loans.outstanding'],
                'unit' => 'percent',
                'direction' => 'lower_better',
                'weight' => 100,
                'bands' => [
                    ['min' => null, 'max' => '5.00', 'points' => 100, 'recommendation' => 'Keep it up.'],
                    ['min' => '5.00', 'max' => '10.00', 'points' => 50],
                    ['min' => '10.00', 'max' => null, 'points' => 0],
                ],
            ], $indicator)],
            'rating_bands' => [
                ['min' => '90.00', 'label' => 'Very Satisfactory'],
                ['min' => '60.00', 'label' => 'Fair'],
                ['min' => '0.00', 'label' => 'Needs Improvement'],
            ],
        ];
    }

    private function score(array $definition, ?string $numerator, ?string $denominator, ?string $opening = null): array
    {
        $key = $definition['indicators'][0]['key'];

        return (new PesosEngine)->evaluate($definition, [$key => [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'denominator_opening' => $opening,
        ]]);
    }

    private function indicator(array $result): array
    {
        return $result['indicators'][0];
    }

    public function test_a_lower_better_edge_goes_to_the_better_band(): void
    {
        $exact = $this->indicator($this->score($this->definition(), '5.00', '100.00'));
        $this->assertSame('5.00', $exact['value']);
        $this->assertSame('100.00', $exact['score']);
        $this->assertSame('Keep it up.', $exact['recommendation']);

        $above = $this->indicator($this->score($this->definition(), '5.01', '100.00'));
        $this->assertSame('5.01', $above['value']);
        $this->assertSame('50.00', $above['score']);

        $ten = $this->indicator($this->score($this->definition(), '10.00', '100.00'));
        $this->assertSame('50.00', $ten['score']);
    }

    public function test_a_higher_better_edge_goes_to_the_better_band(): void
    {
        $definition = $this->definition([
            'direction' => 'higher_better',
            'bands' => [
                ['min' => '100.00', 'max' => null, 'points' => 100],
                ['min' => '50.00', 'max' => '100.00', 'points' => 50],
                ['min' => null, 'max' => '50.00', 'points' => 0],
            ],
        ]);

        $this->assertSame('100.00', $this->indicator($this->score($definition, '100.00', '100.00'))['score']);
        $this->assertSame('50.00', $this->indicator($this->score($definition, '99.99', '100.00'))['score']);
        $this->assertSame('50.00', $this->indicator($this->score($definition, '50.00', '100.00'))['score']);
        $this->assertSame('0.00', $this->indicator($this->score($definition, '49.99', '100.00'))['score']);
    }

    public function test_the_band_is_chosen_on_the_value_rounded_half_up_to_two_decimals(): void
    {
        $rounded = $this->indicator($this->score($this->definition(), '49.95', '1000.00'));

        $this->assertSame('5.00', $rounded['value']);
        $this->assertSame('100.00', $rounded['score'], 'The value shown, 5.00, is on the better side of the edge.');
    }

    public function test_a_zero_denominator_is_not_computable_and_leaves_the_rating_open(): void
    {
        $result = $this->score($this->definition(), '5.00', '0.00');
        $row = $this->indicator($result);

        $this->assertNull($row['value']);
        $this->assertNull($row['score']);
        $this->assertSame('not_computable', $row['status']);
        $this->assertStringContainsString('zero', $row['note']);
        $this->assertFalse($result['complete']);
        $this->assertNull($result['rating']);
    }

    public function test_coverage_with_nothing_required_is_fully_covered(): void
    {
        $definition = $this->definition([
            'formula_key' => 'coverage',
            'direction' => 'higher_better',
            'bands' => [['min' => '100.00', 'max' => null, 'points' => 100], ['min' => null, 'max' => '100.00', 'points' => 0]],
        ]);

        $row = $this->indicator($this->score($definition, '0.00', '0.00'));
        $this->assertSame('100.00', $row['value']);
        $this->assertSame('100.00', $row['score']);
        $this->assertSame('scored', $row['status']);

        $this->assertSame('150.00', $this->indicator($this->score($definition, '150.00', '100.00'))['value']);
    }

    public function test_growth_and_the_average_ratio(): void
    {
        $growth = $this->definition(['formula_key' => 'growth', 'direction' => 'higher_better', 'bands' => [['min' => null, 'max' => null, 'points' => 1]]]);
        $this->assertSame('12.50', $this->indicator($this->score($growth, '112.50', '100.00'))['value']);
        $this->assertSame('-20.00', $this->indicator($this->score($growth, '80.00', '100.00'))['value']);
        $this->assertSame('not_computable', $this->indicator($this->score($growth, '80.00', '0.00'))['status']);

        $average = $this->definition(['formula_key' => 'ratio_avg', 'direction' => 'higher_better', 'bands' => [['min' => null, 'max' => null, 'points' => 1]]]);
        $this->assertSame('10.00', $this->indicator($this->score($average, '15.00', '200.00', '100.00'))['value'], '15 ÷ ((100 + 200) ÷ 2)');
        $this->assertSame('missing_data', $this->indicator($this->score($average, '15.00', '200.00', null))['status']);
    }

    public function test_missing_data_is_not_scored(): void
    {
        $result = $this->score($this->definition(), null, '100.00');

        $this->assertSame('missing_data', $this->indicator($result)['status']);
        $this->assertNull($this->indicator($result)['score']);
        $this->assertNull($result['score']);
        $this->assertFalse($result['complete']);
    }

    public function test_the_total_component_scores_and_rating_add_up_and_everything_is_to_verify(): void
    {
        $definition = $this->definition();
        $definition['components'][] = ['key' => 'solvency', 'label' => 'Solvency', 'weight' => 50];
        $definition['indicators'][] = [
            'key' => 'capital', 'component' => 'solvency', 'label' => 'Equity to assets', 'formula_key' => 'ratio',
            'numerator' => ['key' => 'fs.total_equity'], 'denominator' => ['key' => 'fs.total_assets'],
            'unit' => 'percent', 'direction' => 'higher_better', 'weight' => 50,
            'bands' => [['min' => '20.00', 'max' => null, 'points' => '12.5'], ['min' => null, 'max' => '20.00', 'points' => 0]],
        ];

        $result = (new PesosEngine)->evaluate($definition, [
            'par' => ['numerator' => '2.00', 'denominator' => '100.00'],
            'capital' => ['numerator' => '30.00', 'denominator' => '100.00'],
        ]);

        $this->assertTrue($result['complete']);
        $this->assertSame('112.50', $result['score']);
        $this->assertSame('Very Satisfactory', $result['rating']);
        $this->assertSame(['quality' => '100.00', 'solvency' => '12.50'], array_column($result['components'], 'score', 'key'));
        $this->assertFalse($result['verified']);
        $this->assertCount(11, $result['to_verify']);
        $this->assertTrue($result['indicators'][0]['to_verify']);
        $this->assertSame('Past due loans ÷ Loans outstanding × 100', $result['indicators'][0]['formula']);
    }

    public function test_the_rating_follows_the_highest_band_reached(): void
    {
        $result = (new PesosEngine)->evaluate($this->definition([
            'bands' => [['min' => null, 'max' => null, 'points' => '89.99']],
        ]), ['par' => ['numerator' => '1.00', 'denominator' => '100.00']]);

        $this->assertSame('Fair', $result['rating']);
    }
}
