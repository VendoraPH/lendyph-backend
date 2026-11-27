<?php

namespace App\Http\Requests\Linda;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /linda/chat` (`linda:use`): one question of at most 500 characters,
 * and the conversation so far, at most 10 turns. Assistant turns carry an
 * intent; their content is accepted as "", null or text and never read
 * (ConvertEmptyStringsToNull turns "" into null, so null passes). Text that
 * isn't valid UTF-8 is refused (422), so no question reaches the parser
 * half-readable.
 */
class LindaChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('linda:use') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $questionMax = (int) config('linda.limits.question_max');

        return [
            'message' => ['required', 'string', 'max:'.$questionMax, self::validUtf8()],
            'history' => ['nullable', 'array', 'list', 'max:10'],
            'history.*' => ['array'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['nullable', 'string', 'max:'.$questionMax, self::validUtf8()],
            'history.*.intent' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** A rule refusing text that isn't valid UTF-8. */
    private static function validUtf8(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail('The :attribute must be valid text.');
            }
        };
    }

    /** The question, trimmed. */
    public function question(): string
    {
        return trim((string) $this->validated('message'));
    }
}
