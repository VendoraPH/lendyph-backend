<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 422 when any request input is not valid UTF-8.
 *
 * Form-encoded bodies, multipart text fields and query strings can carry any
 * bytes (`name=Admin%FF`). Left alone, those bytes reach MySQL, which refuses
 * them as an "Incorrect string value", and the error renderer then fails to
 * JSON-encode the message that quotes them: a 500. `/u` patterns also stop
 * matching them silently. So every string in the query and the parsed body,
 * keys included, is checked once here, before any controller sees it.
 *
 * Uploaded files live in the request's file bag and a raw body (octet-stream,
 * a webhook payload) is never parsed into input, so neither is read. A JSON
 * body cannot carry invalid UTF-8 at all: json_decode refuses it.
 *
 * The 422 is answered here as JSON, in the framework's validation shape,
 * whatever the Accept header: a ValidationException would redirect a caller
 * that did not ask for JSON, and this API has nowhere to redirect to.
 */
class RejectInvalidUtf8
{
    public function handle(Request $request, Closure $next): Response
    {
        $messages = [];

        $this->collect($request->query->all(), '', $messages);
        $this->collect($request->request->all(), '', $messages);

        if ($messages === []) {
            return $next($request);
        }

        $exception = ValidationException::withMessages($messages);

        return new JsonResponse([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], 422);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, list<string>>  $messages
     */
    private function collect(array $input, string $prefix, array &$messages): void
    {
        foreach ($input as $key => $value) {
            $keyIsValid = ! is_string($key) || mb_check_encoding($key, 'UTF-8');
            $path = $prefix.mb_scrub((string) $key, 'UTF-8');

            if (! $keyIsValid) {
                $messages[$path][] = 'The field name '.$path.' must be valid UTF-8 text.';

                continue;
            }

            if (is_array($value)) {
                $this->collect($value, $path.'.', $messages);
            } elseif (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $messages[$path][] = 'The '.str_replace('_', ' ', $path).' must be valid UTF-8 text.';
            }
        }
    }
}
