<?php

namespace Tests\Feature\Cda\Reports;

use App\Services\Cda\Reports\CalcEngine;
use App\Services\Cda\Reports\CanonicalJson;
use App\Services\Cda\Reports\Rational;
use Tests\TestCase;

/**
 * The structured calculation engine (design §2): ops are data, never parsed
 * text; numbers are exact rationals (money in centavos), ratio and div give
 * null on a zero divisor, null flows through, and results are rounded half up
 * only when stored. Plus the canonical JSON the snapshot checksum is taken of.
 */
class CdaCalcEngineTest extends TestCase
{
    private function calc(array $expression, array $values): ?Rational
    {
        return app(CalcEngine::class)->evaluate($expression, fn (string $ref) => $values[$ref] ?? null);
    }

    public function test_the_operations(): void
    {
        $values = ['a' => '1000.10', 'b' => 4, 'c' => '0.05', 'zero' => 0, 'none' => null, 'rows' => [['amount' => '1.10'], ['amount' => '2.25'], ['amount' => null]]];

        $this->assertSame('1004.10', $this->calc(['op' => 'add', 'args' => ['a', 'b']], $values)->toFixed(2));
        $this->assertSame('996.10', $this->calc(['op' => 'sub', 'args' => ['a', 'b']], $values)->toFixed(2));
        $this->assertSame('50.01', $this->calc(['op' => 'mul', 'args' => ['a', 'c']], $values)->toFixed(2));
        $this->assertSame('250.03', $this->calc(['op' => 'div', 'args' => ['a', 'b']], $values)->toFixed(2));
        $this->assertSame('25002.50', $this->calc(['op' => 'ratio', 'args' => ['a', 'b']], $values)->toFixed(2), 'ratio is a percent');
        $this->assertNull($this->calc(['op' => 'ratio', 'args' => ['a', 'zero']], $values));
        $this->assertNull($this->calc(['op' => 'div', 'args' => ['a', 'zero']], $values));
        $this->assertNull($this->calc(['op' => 'add', 'args' => ['a', 'none']], $values));
        $this->assertSame('1000.10', $this->calc(['op' => 'coalesce', 'args' => ['none', 'a', 'b']], $values)->toFixed(2));
        $this->assertSame('3.35', $this->calc(['op' => 'sum_column', 'args' => ['rows', 'amount']], $values)->toFixed(2));
        $this->assertSame('1000.1', $this->calc(['op' => 'round_half_up', 'args' => ['a', 1]], $values)->toFixed(1));
        $this->assertSame('0.13', $this->calc(['op' => 'round_half_up', 'args' => [['op' => 'div', 'args' => [1, 8]], 2]], $values)->toFixed(2));
        $this->assertSame('-0.13', $this->calc(['op' => 'round_half_up', 'args' => [['op' => 'div', 'args' => [-1, 8]], 2]], $values)->toFixed(2));
        $this->assertSame('7.5', $this->calc(['op' => 'add', 'args' => [2.5, 5]], $values)->toFixed(1));
    }

    public function test_rationals_are_exact(): void
    {
        $third = Rational::of(1)->divide(Rational::of(3));
        $this->assertSame('1.00', $third->add($third)->add($third)->toFixed(2));
        $this->assertTrue(Rational::of('0.1')->add(Rational::of('0.2'))->equals(Rational::of('0.3')));
        $this->assertSame(150050, Rational::of('1500.50')->toCentavos());
        $this->assertSame('-12.05', Rational::fromCentavos(-1205)->toFixed(2));
        $this->assertSame(-1, Rational::of('1.005')->compare(Rational::of('1.01')));
    }

    public function test_canonical_json_sorts_keys_and_keeps_unicode(): void
    {
        $this->assertSame('{"a":[3,1],"b":{"x":"₱1.00","y":null},"c":true}', CanonicalJson::encode(['c' => true, 'b' => ['y' => null, 'x' => '₱1.00'], 'a' => [3, 1]]));
        $this->assertSame(CanonicalJson::encode(['b' => 1, 'a' => 2]), CanonicalJson::encode(['a' => 2, 'b' => 1]));
    }
}
