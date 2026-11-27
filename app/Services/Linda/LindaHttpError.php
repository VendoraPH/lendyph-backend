<?php

namespace App\Services\Linda;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A Linda answer that isn't a reply: `{success: false, error_code, message}`
 * with the HTTP status the contract gives each code (Linda v1 plan, HTTP
 * contract). Rendered by Laravel through render(), so a throw anywhere on the
 * chat path answers in this shape.
 *
 * The message is fixed wording per code, never an exception's or a model's
 * text, so nothing from a question, an answer or an upstream error reaches the
 * response through here. ShouldntReport: an expected answer, never logged.
 */
final class LindaHttpError extends RuntimeException implements ShouldntReport
{
    public const LINDA_OFF = 'linda_off';

    public const RATE_LIMITED = 'rate_limited';

    public const DAILY_LIMIT = 'daily_limit';

    public const MONTHLY_LIMIT = 'monthly_limit';

    public const AI_UNAVAILABLE = 'ai_unavailable';

    public const DATA_UNAVAILABLE = 'data_unavailable';

    /**
     * @param  array<string, string|int>  $headers
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly string $text,
        public readonly array $headers = [],
    ) {
        parent::__construct($text);
    }

    public static function lindaOff(): self
    {
        return new self(self::LINDA_OFF, 501, 'Linda is turned off on this server.');
    }

    /** @param array<string, string|int> $headers the limiter's Retry-After and rate headers */
    public static function rateLimited(array $headers = []): self
    {
        return new self(self::RATE_LIMITED, 429, 'You\'ve asked Linda several questions in a short time. Please wait a minute and try again.', $headers);
    }

    /** @param array<string, string|int> $headers the limiter's Retry-After and rate headers */
    public static function dailyLimit(array $headers = []): self
    {
        $limit = (int) config('linda.limits.per_day');

        return new self(self::DAILY_LIMIT, 429, "You've reached Linda's limit of {$limit} questions in 24 hours. Please try again later.", $headers);
    }

    /** This month's AI cost reached `linda.monthly_cap`; matcher answers carry on. */
    public static function monthlyLimit(): self
    {
        return new self(self::MONTHLY_LIMIT, 429, 'Linda has reached this month\'s limit for questions that need the AI service. The questions Linda answers on its own still work.');
    }

    /** The model couldn't be asked (rate limit, overload, timeout, key, output). */
    public static function aiUnavailable(): self
    {
        return new self(self::AI_UNAVAILABLE, 503, 'Linda couldn\'t reach the AI service just now. Please try again in a moment.');
    }

    /** The figures couldn't be read. */
    public static function dataUnavailable(): self
    {
        return new self(self::DATA_UNAVAILABLE, 503, 'Linda couldn\'t read the figures just now. Please try again in a moment.');
    }

    public function render(): JsonResponse
    {
        return $this->toResponse();
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => $this->errorCode,
            'message' => $this->text,
        ], $this->status, $this->headers);
    }
}
