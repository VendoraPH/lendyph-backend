<?php

namespace App\Services\Linda\Model;

use Anthropic\Beta\AnthropicBeta;
use Anthropic\Beta\Messages\BetaFallbackBlock;
use Anthropic\Beta\Messages\BetaFallbackMessageIterationUsage;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaUsage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\RequestTimeoutException;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Anthropic\RequestOptions;
use App\Services\Diagnostics\ErrorDigest;
use App\Services\Linda\LindaIntent;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Support\Facades\Log;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * The `anthropic` driver: Claude, through the official PHP SDK
 * (anthropic-ai/sdk), on the server only.
 *
 * ## The call
 * One Messages call per question, with the model the request names:
 *  - the system prompt (LindaPrompt::system()) as one text block marked for
 *    the prompt cache;
 *  - one user message holding the staff text as JSON data;
 *  - structured output (LindaPrompt::schema()) at low effort;
 *  - no thinking, no temperature, top_p or top_k, and no prefill.
 * Models whose config sets `fallbacks` (Sonnet 5.5, Opus 5.5) go through the
 * beta Messages API with Anthropic's server-side refusal fallback
 * (`fallbacks: "default"`); Haiku has none and uses the plain API.
 *
 * ## Limits
 * The SDK leaves the timeout to the HTTP client, so the Guzzle client handed
 * to it carries the configured timeout per attempt; the SDK's own retries
 * follow `max_retries`, and LindaHttpGuard caps the wait the server asks for
 * between attempts at 2 s and refuses redirects, so a worker is held about
 * 20 s at worst (worstCaseSeconds()). The key comes only from
 * `config('linda.anthropic')`, never from the SDK's ANTHROPIC_* environment
 * variables, and the base URL is fixed, so the key can't be sent anywhere
 * else. ANTHROPIC_CUSTOM_HEADERS, which the SDK would add to every request,
 * refuses the call (`custom_headers`) before anything is sent.
 *
 * ## Failures
 * Every failure is a LindaModelUnavailable with a fixed reason code, logged
 * once as a warning with ErrorDigest::context() and the code: never the
 * provider's message, the prompt or the question. One that was answered but
 * can't be used (`max_tokens`, `bad_output`, including a period or branch
 * over MAX_FIELD_LENGTH characters) carries the usage it was billed.
 */
final class AnthropicModel implements LindaModel
{
    private const BASE_URL = 'https://api.anthropic.com';

    /** The longest a connection may take to open, within the attempt's timeout. */
    private const CONNECT_TIMEOUT = 5.0;

    /** The stop reasons after which the answer is complete. */
    private const COMPLETE = ['end_turn', 'stop_sequence'];

    /** The longest period words or branch name read from the model. */
    public const MAX_FIELD_LENGTH = 100;

    /** The SDK reads this to add headers to every request; Linda never sends them. */
    private const CUSTOM_HEADERS_ENV = 'ANTHROPIC_CUSTOM_HEADERS';

    /**
     * @param  list<callable>  $middleware  SDK middleware, for tests
     */
    public function __construct(
        #[\SensitiveParameter] private readonly ?string $apiKey,
        private readonly ?string $workspaceId,
        private readonly string $effort,
        private readonly int $maxTokens,
        private readonly int $maxRetries,
        private readonly array $middleware = [],
        private readonly ?ClientInterface $transporter = null,
    ) {}

    /**
     * Built from `config('linda.anthropic')`.
     *
     * @param  list<callable>  $middleware  SDK middleware, for tests
     */
    public static function fromConfig(array $middleware = [], ?ClientInterface $transporter = null): self
    {
        $config = (array) config('linda.anthropic', []);
        $workspace = trim((string) ($config['workspace_id'] ?? ''));

        return new self(
            apiKey: isset($config['api_key']) ? trim((string) $config['api_key']) : null,
            workspaceId: $workspace === '' ? null : $workspace,
            effort: (string) ($config['effort'] ?? 'low'),
            maxTokens: (int) ($config['max_tokens'] ?? 512),
            maxRetries: (int) ($config['max_retries'] ?? 1),
            middleware: $middleware,
            transporter: $transporter,
        );
    }

    /**
     * The HTTP client's timeouts, from `config('linda.anthropic.timeout')`.
     *
     * @return array{timeout: float, connect_timeout: float}
     */
    public static function httpOptions(): array
    {
        $timeout = (float) config('linda.anthropic.timeout', 9.0);

        return ['timeout' => $timeout, 'connect_timeout' => min($timeout, self::CONNECT_TIMEOUT)];
    }

    /**
     * The longest a question can hold a PHP worker, in seconds: every attempt
     * at its full timeout, plus the capped wait before each retry.
     */
    public static function worstCaseSeconds(): float
    {
        $retries = max(0, (int) config('linda.anthropic.max_retries', 1));

        return self::httpOptions()['timeout'] * ($retries + 1) + LindaHttpGuard::MAX_RETRY_AFTER_SECONDS * $retries;
    }

    public function understand(#[\SensitiveParameter] LindaModelRequest $request): LindaUnderstanding
    {
        try {
            return $this->ask($request);
        } catch (LindaModelUnavailable $e) {
            Log::warning('Linda model call failed.', ['reason' => $e->reason]);

            throw $e;
        } catch (AnthropicException $e) {
            $reason = self::reasonFor($e);
            Log::warning('Linda model call failed.', ['reason' => $reason] + ErrorDigest::context($e));

            throw new LindaModelUnavailable($reason);
        }
    }

    /**
     * @throws LindaModelUnavailable
     * @throws AnthropicException
     */
    private function ask(#[\SensitiveParameter] LindaModelRequest $request): LindaUnderstanding
    {
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            throw new LindaModelUnavailable('no_key');
        }

        $model = config("linda.models.{$request->model}");

        if (! LindaPrices::knows($request->model) || ! is_array($model)) {
            throw new LindaModelUnavailable('no_prices');
        }

        if (self::customHeadersSet()) {
            throw new LindaModelUnavailable('custom_headers');
        }

        $client = $this->client();
        $arguments = [
            'maxTokens' => $this->maxTokens,
            'messages' => [['role' => 'user', 'content' => LindaPrompt::userMessage($request->question, $request->earlier)]],
            'model' => $request->model,
            'outputConfig' => ['effort' => $this->effort, 'format' => ['type' => 'json_schema', 'schema' => LindaPrompt::schema()]],
            'system' => [['type' => 'text', 'text' => LindaPrompt::system(), 'cacheControl' => ['type' => 'ephemeral']]],
            'workspaceID' => $this->workspaceId,
        ];

        try {
            $message = ($model['fallbacks'] ?? false) === true
                ? $client->beta->messages->create(...$arguments, fallbacks: 'default', betas: [AnthropicBeta::SERVER_SIDE_FALLBACK_2026_07_01])
                : $client->messages->create(...$arguments);
        } catch (JsonException) {
            // A 200 whose body isn't JSON.
            throw new LindaModelUnavailable('bad_output');
        }

        try {
            return $this->read($message, $request->model);
        } catch (LindaModelUnavailable $e) {
            throw $e;
        } catch (Exception) {
            // The SDK raises a plain Exception for a response field of the
            // wrong type: the response isn't one we can read.
            throw new LindaModelUnavailable('bad_output');
        }
    }

    private function client(): Client
    {
        return new Client(
            apiKey: $this->apiKey,
            baseUrl: self::BASE_URL,
            requestOptions: RequestOptions::with(
                timeout: self::httpOptions()['timeout'],
                maxRetries: $this->maxRetries,
                middleware: [new LindaHttpGuard, ...$this->middleware],
                transporter: $this->transporter ?? new GuzzleClient(self::httpOptions()),
            ),
            openTelemetry: false,
        );
    }

    /**
     * The understanding in a Messages response (plain or beta).
     *
     * @throws LindaModelUnavailable
     */
    private function read(Message|BetaMessage $message, string $requested): LindaUnderstanding
    {
        $usage = self::usage($message, $requested);
        $stop = $message->stopReason;

        if ($stop === 'refusal') {
            return LindaUnderstanding::refusal($usage);
        }

        try {
            if ($stop === 'max_tokens') {
                throw new LindaModelUnavailable('max_tokens');
            }

            if (! in_array($stop, self::COMPLETE, true)) {
                throw new LindaModelUnavailable('bad_output');
            }

            $answer = self::answer(self::text($message));
        } catch (LindaModelUnavailable $e) {
            throw new LindaModelUnavailable($e->reason, $usage);
        } catch (Exception) {
            // The SDK raises a plain Exception for a response field of the
            // wrong type: the answer can't be read, but it was billed.
            throw new LindaModelUnavailable('bad_output', $usage);
        }

        return LindaUnderstanding::fromAnswer($answer['intent'], $answer['period'], $answer['branch'], $answer['follow_up'], $usage);
    }

    /** Whether ANTHROPIC_CUSTOM_HEADERS is set anywhere the SDK or PHP reads it. */
    private static function customHeadersSet(): bool
    {
        return getenv(self::CUSTOM_HEADERS_ENV) !== false
            || array_key_exists(self::CUSTOM_HEADERS_ENV, $_ENV)
            || array_key_exists(self::CUSTOM_HEADERS_ENV, $_SERVER);
    }

    /**
     * The answer's text: the first text block after the last fallback block,
     * so a declined model's partial output is never read.
     *
     * @throws LindaModelUnavailable
     */
    private static function text(Message|BetaMessage $message): string
    {
        $text = null;

        foreach ($message->content as $block) {
            if ($block instanceof BetaFallbackBlock) {
                $text = null;
            } elseif (($block instanceof TextBlock || $block instanceof BetaTextBlock) && $text === null) {
                $text = $block->text;
            }
        }

        if ($text === null || trim($text) === '') {
            throw new LindaModelUnavailable('bad_output');
        }

        return $text;
    }

    /**
     * The model's JSON, checked against the schema.
     *
     * @return array{intent: LindaIntent, period: ?string, branch: ?string, follow_up: bool}
     *
     * @throws LindaModelUnavailable
     */
    private static function answer(string $text): array
    {
        try {
            $data = json_decode($text, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LindaModelUnavailable('bad_output');
        }

        $keys = ['intent', 'period', 'branch', 'follow_up'];

        if (! is_array($data) || array_is_list($data) || array_diff(array_keys($data), $keys) !== [] || array_diff($keys, array_keys($data)) !== []) {
            throw new LindaModelUnavailable('bad_output');
        }

        $intent = is_string($data['intent']) ? LindaIntent::tryFrom(strtolower(trim($data['intent']))) : null;

        if ($intent === null
            || ! (is_string($data['period']) || $data['period'] === null)
            || ! (is_string($data['branch']) || $data['branch'] === null)
            || mb_strlen((string) $data['period']) > self::MAX_FIELD_LENGTH
            || mb_strlen((string) $data['branch']) > self::MAX_FIELD_LENGTH
            || ! is_bool($data['follow_up'])) {
            throw new LindaModelUnavailable('bad_output');
        }

        return ['intent' => $intent, 'period' => $data['period'], 'branch' => $data['branch'], 'follow_up' => $data['follow_up']];
    }

    /**
     * The tokens used and the model that answered: with a server-side
     * fallback, the model of the `fallback_message` iteration.
     *
     * How a fallback is priced (security review S7): when the usage lists its
     * iterations, each is priced at its own model (the requested one unless
     * the iteration names another). When a fallback happened but no
     * iterations are listed, every token is priced at the higher of the
     * requested and answering models' prices.
     */
    private static function usage(Message|BetaMessage $message, string $requested): LindaUsage
    {
        $usage = $message->usage;
        $model = $message->model !== '' ? $message->model : $requested;
        $parts = [];
        $fellBack = false;

        if ($usage instanceof BetaUsage) {
            foreach ($usage->iterations ?? [] as $iteration) {
                $iterationModel = isset($iteration->model) && $iteration->model !== '' ? $iteration->model : $requested;

                if ($iteration instanceof BetaFallbackMessageIterationUsage) {
                    $model = $iteration->model;
                    $fellBack = true;
                }

                $parts[] = new LindaUsage(
                    $iterationModel,
                    inputTokens: (int) ($iteration->inputTokens ?? 0),
                    outputTokens: (int) ($iteration->outputTokens ?? 0),
                    cacheReadTokens: (int) ($iteration->cacheReadInputTokens ?? 0),
                    cacheWriteTokens: (int) ($iteration->cacheCreationInputTokens ?? 0),
                );
            }
        }

        foreach ($message->content as $block) {
            $fellBack = $fellBack || $block instanceof BetaFallbackBlock;
        }

        $fellBack = $fellBack || $model !== $requested;

        return new LindaUsage(
            $model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadTokens: $usage->cacheReadInputTokens ?? 0,
            cacheWriteTokens: $usage->cacheCreationInputTokens ?? 0,
            parts: $fellBack ? $parts : [],
            priceAtHighestOf: $fellBack && $parts === [] && $model !== $requested ? [$requested] : [],
        );
    }

    /** The reason code for an SDK failure, most specific first. */
    private static function reasonFor(AnthropicException $e): string
    {
        return match (true) {
            $e instanceof RateLimitException => 'rate_limited',
            $e instanceof InternalServerException => 'overloaded',
            $e instanceof APITimeoutException, $e instanceof RequestTimeoutException => 'timeout',
            $e instanceof APIConnectionException => self::timedOut($e) ? 'timeout' : 'connection',
            $e instanceof AuthenticationException, $e instanceof PermissionDeniedException => 'auth',
            $e instanceof APIStatusException && $e->status !== null && $e->status >= 500 => 'overloaded',
            default => 'bad_request',
        };
    }

    /** Whether a connection failure was the HTTP client's timeout. */
    private static function timedOut(Throwable $e): bool
    {
        for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectTimeoutException || $cause instanceof NetworkTimeoutException || $cause instanceof ResponseTimeoutException) {
                return true;
            }
        }

        return false;
    }
}
