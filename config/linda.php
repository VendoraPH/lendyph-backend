<?php

/*
|--------------------------------------------------------------------------
| Linda, the staff assistant
|--------------------------------------------------------------------------
|
| Off by default (`LINDA_ENABLED`), and with no model (`LINDA_DRIVER=none`):
| the deterministic matcher answers its questions and nothing leaves the
| server. `fake` is for tests and local runs; `anthropic` needs a key. The key,
| the monthly cap and the switch stay on the server; Settings → Linda only
| picks one of `models`.
|
| Prices are USD list prices per million tokens, kept as strings so the cost
| is worked out in exact decimals, then turned into pesos at `usd_to_php`.
*/

return [
    'enabled' => filter_var(env('LINDA_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Round 3: a question no fixed topic covers may be written by the model
    // from a pack of summary figures (LindaFacts). Off by default.
    'open_questions' => filter_var(env('LINDA_OPEN_QUESTIONS', false), FILTER_VALIDATE_BOOL),
    'driver' => env('LINDA_DRIVER', 'none'),
    'model' => env('LINDA_MODEL', 'claude-haiku-5-5'),
    'anthropic' => [
        'api_key' => env('LINDA_ANTHROPIC_API_KEY'),
        'workspace_id' => env('LINDA_ANTHROPIC_WORKSPACE_ID'),
        'effort' => 'low',
        'max_tokens' => 512,
        'write_max_tokens' => 600,
        'timeout' => 9.0,
        'max_retries' => 1,
    ],
    'models' => [
        'claude-haiku-5-5' => [
            'label' => 'Claude Haiku 5.5',
            'description' => 'Fastest and lowest cost. Suits Linda\'s job: sorting a question and reading its dates and branch.',
            'input' => '0.10', 'output' => '0.50', 'cache_read' => '0.01', 'cache_write' => '0.125',
            'fallbacks' => false,
        ],
        'claude-sonnet-5-5' => [
            'label' => 'Claude Sonnet 5.5',
            'description' => 'More capable, about 20 times the cost of Haiku.',
            'input' => '2.00', 'output' => '10.00', 'cache_read' => '0.10', 'cache_write' => '2.50',
            'fallbacks' => true,
        ],
        'claude-opus-5-5' => [
            'label' => 'Claude Opus 5.5',
            'description' => 'Most capable, about 40 times the cost of Haiku.',
            'input' => '4.00', 'output' => '20.00', 'cache_read' => '0.20', 'cache_write' => '5.00',
            'fallbacks' => true,
        ],
    ],
    'usd_to_php' => env('LINDA_USD_TO_PHP', '62.00'),
    'monthly_cap' => env('LINDA_MONTHLY_CAP', '1000.00'),
    'limits' => ['per_minute' => 10, 'per_day' => 100, 'question_max' => 500, 'history_max' => 5],
    'list_max' => 10,
    'retention_days' => 365,
    'typical_question' => ['cache_read' => 1500, 'input' => 500, 'output' => 300],
    // An open answer's write call: its prompt, and a facts pack of up to 3 topics with the question.
    'typical_write' => ['cache_read' => 600, 'input' => 1500],
];
