<?php

namespace Tests\Unit\Linda\Model;

use App\Services\Linda\Model\LindaModelUnavailable;
use App\Services\Linda\Model\LindaPrices;
use App\Services\Linda\Model\LindaUsage;
use Tests\Unit\Linda\LindaTestCase;

/**
 * A question's cost in ₱, from the configured USD list prices per million
 * tokens × `usd_to_php`, in exact maths at 6 decimal places.
 */
class LindaPricesTest extends LindaTestCase
{
    use LindaConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setLindaConfig();
    }

    private function usage(string $model): LindaUsage
    {
        return new LindaUsage($model, inputTokens: 1000, outputTokens: 100, cacheReadTokens: 2000, cacheWriteTokens: 500);
    }

    public function test_the_cost_is_exact_at_six_decimals(): void
    {
        // (1000 × 0.10 + 100 × 0.50 + 2000 × 0.01 + 500 × 0.125) ÷ 1,000,000 × 62.00
        $this->assertSame('0.014415', LindaPrices::cost($this->usage('claude-haiku-5-5')));
        // (1000 × 2.00 + 100 × 10.00 + 2000 × 0.10 + 500 × 2.50) ÷ 1,000,000 × 62.00
        $this->assertSame('0.275900', LindaPrices::cost($this->usage('claude-sonnet-5-5')));
    }

    public function test_the_cost_rounds_half_up_at_the_sixth_decimal(): void
    {
        // 3 × 0.10 ÷ 1,000,000 × 62 = 0.0000186
        $this->assertSame('0.000019', LindaPrices::cost(new LindaUsage('claude-haiku-5-5', inputTokens: 3)));
        // 1 × 0.10 ÷ 1,000,000 × 62 = 0.0000062
        $this->assertSame('0.000006', LindaPrices::cost(new LindaUsage('claude-haiku-5-5', inputTokens: 1)));
        $this->assertSame('0.000000', LindaPrices::cost(new LindaUsage('claude-haiku-5-5')));
    }

    public function test_the_cost_uses_the_model_that_answered(): void
    {
        $this->assertNotSame(
            LindaPrices::cost($this->usage('claude-haiku-5-5')),
            LindaPrices::cost($this->usage('claude-opus-5-5')),
        );
        // (1000 × 4.00 + 100 × 20.00 + 2000 × 0.20 + 500 × 5.00) ÷ 1,000,000 × 62.00
        $this->assertSame('0.551800', LindaPrices::cost($this->usage('claude-opus-5-5')));
    }

    public function test_a_dated_model_id_takes_its_alias_price(): void
    {
        $this->assertSame('0.275900', LindaPrices::cost($this->usage('claude-sonnet-5-5-20261101')));
    }

    public function test_an_unknown_model_is_priced_at_the_highest_configured_price(): void
    {
        $this->assertSame('0.551800', LindaPrices::cost($this->usage('claude-mystery-9')));
        $this->assertSame('0.551800', LindaPrices::cost($this->usage('claude-haiku-5-55')));
    }

    public function test_the_exchange_rate_comes_from_config(): void
    {
        config()->set('linda.usd_to_php', '58.25');

        // 232.5 ÷ 1,000,000 × 58.25 = 0.0135431…
        $this->assertSame('0.013543', LindaPrices::cost($this->usage('claude-haiku-5-5')));
    }

    public function test_the_price_per_question_is_two_decimals_half_up_at_the_typical_size(): void
    {
        // (1500 × 0.01 + 500 × 0.10 + 300 × 0.50) ÷ 1,000,000 × 62 = 0.01333
        $this->assertSame('0.01', LindaPrices::perQuestion('claude-haiku-5-5'));
        // (1500 × 0.10 + 500 × 2.00 + 300 × 10.00) ÷ 1,000,000 × 62 = 0.2573
        $this->assertSame('0.26', LindaPrices::perQuestion('claude-sonnet-5-5'));
        // (1500 × 0.20 + 500 × 4.00 + 300 × 20.00) ÷ 1,000,000 × 62 = 0.5146
        $this->assertSame('0.51', LindaPrices::perQuestion('claude-opus-5-5'));
    }

    public function test_the_price_per_million_reads_in_pesos(): void
    {
        $this->assertSame(['input' => '6.20', 'output' => '31.00', 'cache_read' => '0.62', 'cache_write' => '7.75'], LindaPrices::perMillion('claude-haiku-5-5'));
    }

    public function test_knows_only_the_configured_models(): void
    {
        $this->assertTrue(LindaPrices::knows('claude-haiku-5-5'));
        $this->assertTrue(LindaPrices::knows('claude-opus-5-5'));
        $this->assertFalse(LindaPrices::knows('claude-haiku-5-5-20261001'));
        $this->assertFalse(LindaPrices::knows('gpt-5'));
        $this->assertFalse(LindaPrices::knows(''));
    }

    public function test_no_prices_fails_closed(): void
    {
        config()->set('linda.models', []);

        $this->expectExceptionObject(new LindaModelUnavailable('no_prices'));

        LindaPrices::cost($this->usage('claude-haiku-5-5'));
    }

    public function test_a_price_that_is_not_a_number_fails_closed(): void
    {
        config()->set('linda.models.claude-haiku-5-5.input', 'ten cents');

        try {
            LindaPrices::cost($this->usage('claude-haiku-5-5'));
            $this->fail('A price that is not a number was accepted.');
        } catch (LindaModelUnavailable $e) {
            $this->assertSame('no_prices', $e->reason);
        }

        config()->set('linda.models.claude-haiku-5-5.input', '0.10');
        config()->set('linda.usd_to_php', '');

        $this->expectExceptionObject(new LindaModelUnavailable('no_prices'));
        LindaPrices::cost($this->usage('claude-haiku-5-5'));
    }
}
