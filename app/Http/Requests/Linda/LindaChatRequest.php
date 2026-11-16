<?php

namespace App\Http\Requests\Linda;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /linda/chat` (`linda:use`): one question of at most 500 characters,
 * and the conversation so far, at most 10 turns. Assistant turns carry an
 * intent; their content is accepted as "", null or text and never read
 * (ConvertEmptyStringsToNull turns "" into null, so null passes).
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
            'message' => ['required', 'string', 'max:'.$questionMax],
            'history' => ['nullable', 'array', 'list', 'max:10'],
            'history.*' => ['array'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['nullable', 'string', 'max:'.$questionMax],
            'history.*.intent' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** The question, trimmed. */
    public function question(): string
    {
        return trim((string) $this->validated('message'));
    }
}
