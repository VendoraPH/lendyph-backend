<?php

namespace App\Http\Requests\Document;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($this->route('loan')) {
            return $user->can('loans:update');
        }

        return $user->can('borrowers:update');
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx'],
            // The type becomes part of the stored path, so letters, digits,
            // spaces, `_` and `-` only.
            'type' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9][A-Za-z0-9 _-]*$/',
                // A valid ID has its own upload, which needs its number and
                // pairs its sides (ValidIdService). No screen sends one here.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && preg_replace('/[^a-z0-9]/', '', strtolower($value)) === 'validid') {
                        $fail('Upload a valid ID through its own form.');
                    }
                },
            ],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['type.regex' => 'The document type may only use letters, numbers, spaces, hyphens and underscores.'];
    }
}
