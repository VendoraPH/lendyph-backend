<?php

namespace Tests\Unit\Linda\Model;

use App\Services\Linda\Model\AnthropicModel;
use App\Services\Linda\Model\LindaHttpGuard;
use App\Services\Linda\Model\LindaModelRequest;
use App\Services\Linda\Model\LindaModelUnavailable;
use App\Services\Linda\Model\LindaPrices;
use App\Services\Linda\Model\LindaUsage;
use Brick\Math\BigDecimal;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Unit\Linda\LindaTestCase;
use Throwable;

/**
 * The model call's limits after review (security review S1, S2, S5, S6, S7;
 * money review M2): a server-asked wait capped at 2 s, no redirects, no
 * custom headers from the environment, short output only, the usage of a
 * failed but answered call kept, and fallbacks priced conservatively.
 */
class AnthropicModelHardeningTest extends LindaTestCase
{
    use LindaConfig;

    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @var list<ResponseInterface|Throwable> */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->atManila('2026-11-16 09:00');
        $this->setLindaConfig('anthropic');
    }

    private function model(ResponseInterface|Throwable ...$replies): AnthropicModel
    {
        $this->replies = $replies;

        $stub = function (RequestInterface $request): ResponseInterface {
            $this->sent[] = $request;
            $reply = array_shift($this->replies) ?? throw new LogicException('No stubbed reply left.');

            if ($reply instanceof Throwable) {
                throw $reply;
            }

            return $reply;
        };

        $network = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new LogicException('A Linda test tried to reach the network.');
            }
        };

        return AnthropicModel::fromConfig(middleware: [$stub], transporter: $network);
    }

    private function request(string $model = 'claude-haiku-5-5'): LindaModelRequest
    {
        return new LindaModelRequest('Magkano ang nailabas last month?', [], $model);
    }

    /** @param array<string, mixed> $overrides */
    private function reply(string $text, string $stop = 'end_turn', string $model = 'claude-haiku-5-5', array $overrides = []): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(array_merge([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $model,
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stop, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 15],
        ], $overrides)));
    }

    private function good(): ResponseInterface
    {
        return $this->reply(json_encode(['intent' => 'disbursement_summary', 'period' => 'last month', 'branch' => null, 'follow_up' => false]));
    }

    /** @param array<string, string> $headers */
    private function limited(array $headers): ResponseInterface
    {
        return new Response(429, ['Content-Type' => 'application/json', ...$headers], json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']]));
    }

    private function unavailable(callable $call): LindaModelUnavailable
    {
        try {
            $call();
        } catch (LindaModelUnavailable $e) {
            return $e;
        }

        $this->fail('Expected the model to be unavailable.');
    }

    // -- S2 and M2: short output, and a failed answer keeps its usage ------

    public function test_max_tokens_is_512(): void
    {
        $this->assertSame(512, (require config_path('linda.php'))['anthropic']['max_tokens']);
    }

    public function test_max_tokens_keeps_the_usage_it_was_billed(): void
    {
        $e = $this->unavailable(fn () => $this->model($this->reply('{"intent": "coll', 'max_tokens'))->understand($this->request()));

        $this->assertSame('max_tokens', $e->reason);
        $this->assertInstanceOf(LindaUsage::class, $e->usage);
        $this->assertSame([120, 30, 900, 15], [$e->usage->inputTokens, $e->usage->outputTokens, $e->usage->cacheReadTokens, $e->usage->cacheWriteTokens]);
    }

    /** @return array<string, array{string}> */
    public static function badAnswered(): array
    {
        $long = str_repeat('a', 101);

        return [
            'not JSON' => ['Sure! The intent is collection_summary.'],
            'an unknown intent' => ['{"intent": "make_coffee", "period": null, "branch": null, "follow_up": false}'],
            'period over 100 characters' => ['{"intent": "collection_summary", "period": "'.$long.'", "branch": null, "follow_up": false}'],
            'branch over 100 characters' => ['{"intent": "collection_summary", "period": null, "branch": "'.$long.'", "follow_up": false}'],
        ];
    }

    #[DataProvider('badAnswered')]
    public function test_output_it_cant_use_is_bad_output_and_keeps_its_usage(string $text): void
    {
        $e = $this->unavailable(fn () => $this->model($this->reply($text))->understand($this->request()));

        $this->assertSame('bad_output', $e->reason);
        $this->assertSame(120, $e->usage?->inputTokens);
        $this->assertSame('claude-haiku-5-5', $e->usage?->model);
    }

    public function test_period_and_branch_of_100_characters_are_still_read(): void
    {
        $hundred = str_repeat('a', 100);

        $understanding = $this->model($this->reply(json_encode(['intent' => 'collection_summary', 'period' => null, 'branch' => $hundred, 'follow_up' => false])))
            ->understand($this->request());

        $this->assertSame($hundred, $understanding->branchName);
    }

    // -- S1: the wait before the retry ---------------------------------------

    public function test_a_long_retry_after_is_capped_at_two_seconds(): void
    {
        config()->set('linda.anthropic.max_retries', 1);
        $started = microtime(true);

        $this->model($this->limited(['retry-after' => '3600']), $this->good())->understand($this->request());

        $this->assertCount(2, $this->sent);
        $this->assertLessThan(LindaHttpGuard::MAX_RETRY_AFTER_SECONDS + 1.5, microtime(true) - $started);
    }

    public function test_a_long_retry_after_ms_is_capped_at_two_seconds(): void
    {
        config()->set('linda.anthropic.max_retries', 1);
        $started = microtime(true);

        $this->model($this->limited(['retry-after-ms' => '600000']), $this->good())->understand($this->request());

        $this->assertCount(2, $this->sent);
        $this->assertLessThan(LindaHttpGuard::MAX_RETRY_AFTER_SECONDS + 1.5, microtime(true) - $started);
    }

    public function test_the_guard_caps_seconds_milliseconds_and_dates_and_leaves_short_waits(): void
    {
        $guard = new LindaHttpGuard;
        $send = fn (array $headers) => $guard(new Request('POST', 'https://api.anthropic.com/v1/messages'), fn () => $this->limited($headers));

        $this->assertSame('2', $send(['retry-after' => '3600'])->getHeaderLine('retry-after'));
        $this->assertSame('2000', $send(['retry-after-ms' => '600000'])->getHeaderLine('retry-after-ms'));
        $this->assertSame('2', $send(['retry-after' => gmdate('D, d M Y H:i:s', time() + 3600).' GMT'])->getHeaderLine('retry-after'));
        $this->assertSame('1', $send(['retry-after' => '1'])->getHeaderLine('retry-after'));
        $this->assertSame('500', $send(['retry-after-ms' => '500'])->getHeaderLine('retry-after-ms'));
        $this->assertSame('false', $send(['retry-after' => '3600', 'x-should-retry' => 'false'])->getHeaderLine('x-should-retry'));
    }

    public function test_x_should_retry_false_is_still_honoured(): void
    {
        config()->set('linda.anthropic.max_retries', 1);

        $e = $this->unavailable(fn () => $this->model($this->limited(['retry-after' => '3600', 'x-should-retry' => 'false']), $this->good())->understand($this->request()));

        $this->assertSame('rate_limited', $e->reason);
        $this->assertCount(1, $this->sent);
    }

    public function test_the_worst_case_hold_stays_about_20_seconds(): void
    {
        config()->set('linda.anthropic', (require config_path('linda.php'))['anthropic']);

        $this->assertLessThanOrEqual(20.0, AnthropicModel::worstCaseSeconds());
    }

    // -- S5: no redirects ----------------------------------------------------

    /** @return array<string, array{int}> */
    public static function redirects(): array
    {
        return ['301' => [301], '302' => [302], '307' => [307], '308' => [308]];
    }

    #[DataProvider('redirects')]
    public function test_a_redirect_is_refused_and_the_question_is_never_re_sent(int $status): void
    {
        $e = $this->unavailable(fn () => $this->model(new Response($status, ['Location' => 'https://elsewhere.example/v1/messages']), $this->good())->understand($this->request()));

        $this->assertSame('redirect', $e->reason);
        $this->assertCount(1, $this->sent);
    }

    // -- S6: custom headers from the environment -----------------------------

    /** @return array<string, array{string}> */
    public static function headerSources(): array
    {
        return ['getenv' => ['getenv'], '$_ENV' => ['env'], '$_SERVER' => ['server']];
    }

    #[DataProvider('headerSources')]
    public function test_custom_headers_in_the_environment_refuse_the_call_before_sending(string $source): void
    {
        Log::spy();

        match ($source) {
            'getenv' => putenv('ANTHROPIC_CUSTOM_HEADERS=X-Test: 1'),
            'env' => $_ENV['ANTHROPIC_CUSTOM_HEADERS'] = 'X-Test: 1',
            'server' => $_SERVER['ANTHROPIC_CUSTOM_HEADERS'] = 'X-Test: 1',
        };

        try {
            $e = $this->unavailable(fn () => $this->model($this->good())->understand($this->request()));
        } finally {
            putenv('ANTHROPIC_CUSTOM_HEADERS');
            unset($_ENV['ANTHROPIC_CUSTOM_HEADERS'], $_SERVER['ANTHROPIC_CUSTOM_HEADERS']);
        }

        $this->assertSame('custom_headers', $e->reason);
        $this->assertSame([], $this->sent);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['reason'] === 'custom_headers');
    }

    // -- S7: fallbacks priced conservatively ---------------------------------

    public function test_a_fallback_listing_its_iterations_prices_each_at_its_own_model(): void
    {
        $reply = $this->reply(json_encode(['intent' => 'collection_summary', 'period' => 'today', 'branch' => null, 'follow_up' => false]), model: 'claude-sonnet-5-5', overrides: [
            'usage' => [
                'input_tokens' => 400, 'output_tokens' => 60, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0,
                'iterations' => [
                    ['type' => 'message', 'input_tokens' => 200, 'output_tokens' => 20, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
                    ['type' => 'fallback_message', 'model' => 'claude-opus-5-5', 'input_tokens' => 200, 'output_tokens' => 40, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_creation' => null],
                ],
            ],
        ]);

        $usage = $this->model($reply)->understand($this->request('claude-sonnet-5-5'))->usage;

        $expected = (string) BigDecimal::of(LindaPrices::cost(new LindaUsage('claude-sonnet-5-5', 200, 20)))
            ->plus(LindaPrices::cost(new LindaUsage('claude-opus-5-5', 200, 40)))
            ->toScale(6);
        $this->assertSame($expected, LindaPrices::cost($usage));
        // Sonnet: 200 × 2 + 20 × 10 = 600; Opus: 200 × 4 + 40 × 20 = 1,600; USD per million × 62.
        $this->assertSame('0.136400', LindaPrices::cost($usage));
    }

    public function test_a_fallback_with_no_iterations_is_priced_at_the_higher_of_the_two_models(): void
    {
        $reply = $this->reply(json_encode(['intent' => 'collection_summary', 'period' => 'today', 'branch' => null, 'follow_up' => false]), model: 'claude-opus-5-5', overrides: [
            'content' => [
                ['type' => 'fallback', 'from' => ['model' => 'claude-sonnet-5-5'], 'to' => ['model' => 'claude-opus-5-5'], 'trigger' => ['type' => 'refusal', 'category' => null]],
                ['type' => 'text', 'text' => json_encode(['intent' => 'collection_summary', 'period' => 'today', 'branch' => null, 'follow_up' => false])],
            ],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 100, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]);

        $usage = $this->model($reply)->understand($this->request('claude-sonnet-5-5'))->usage;

        $this->assertSame(LindaPrices::cost(new LindaUsage('claude-opus-5-5', 1000, 100)), LindaPrices::cost($usage));
    }

    public function test_an_answering_model_cheaper_than_the_requested_one_is_priced_at_the_requested_one(): void
    {
        $usage = new LindaUsage('claude-haiku-5-5', 1000, 100, priceAtHighestOf: ['claude-sonnet-5-5']);

        $this->assertSame(LindaPrices::cost(new LindaUsage('claude-sonnet-5-5', 1000, 100)), LindaPrices::cost($usage));
    }
}
