<?php

namespace Tests\Unit\Linda\Model;

/**
 * The `config/linda.php` shape from the Linda v1 plan, set in each test so
 * the model layer's tests don't depend on the deployment's values.
 */
trait LindaConfig
{
    /**
     * @param  array<string, mixed>  $anthropic
     */
    protected function setLindaConfig(string $driver = 'fake', array $anthropic = []): void
    {
        config()->set('linda', [
            'enabled' => true,
            'driver' => $driver,
            'model' => 'claude-haiku-5-5',
            'anthropic' => array_merge([
                'api_key' => 'test-key-not-real',
                'workspace_id' => null,
                'effort' => 'low',
                'max_tokens' => 1024,
                'timeout' => 9.0,
                'max_retries' => 0,
            ], $anthropic),
            'models' => [
                'claude-haiku-5-5' => ['label' => 'Claude Haiku 5.5', 'description' => 'Fast and low cost.', 'input' => '0.10', 'output' => '0.50', 'cache_read' => '0.01', 'cache_write' => '0.125', 'fallbacks' => false],
                'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5', 'description' => 'Balanced.', 'input' => '2.00', 'output' => '10.00', 'cache_read' => '0.10', 'cache_write' => '2.50', 'fallbacks' => true],
                'claude-opus-5-5' => ['label' => 'Claude Opus 5.5', 'description' => 'Most capable.', 'input' => '4.00', 'output' => '20.00', 'cache_read' => '0.20', 'cache_write' => '5.00', 'fallbacks' => true],
            ],
            'usd_to_php' => '62.00',
            'monthly_cap' => '1000.00',
            'limits' => ['per_minute' => 10, 'per_day' => 100, 'question_max' => 500, 'history_max' => 5],
            'list_max' => 10,
            'retention_days' => 365,
            'typical_question' => ['cache_read' => 1500, 'input' => 500, 'output' => 300],
        ]);
    }
}
