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
            // `mimes` reads the bytes; `extensions` the file's own name, so a
            // PDF named receipt.bat is refused. `jpe` and `jfif` are JPEG
            // names Windows often saves; the stored file takes the extension
            // its bytes read as. `bail` stops at the first refusal, so the
            // answer is one sentence, never "(and 1 more error)".
            'file' => ['bail', 'required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx', 'extensions:jpg,jpeg,jpe,jfif,png,pdf,doc,docx'],
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
     * Every refusal in words, so no Laravel default ("The file field must…")
     * reaches the screen.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to upload.',
            'file.file' => 'The file didn\'t upload. Try again with a file of at most 10 MB.',
            'file.uploaded' => 'The file didn\'t upload. Try again with a file of at most 10 MB.',
            'file.max' => 'The file can be at most 10 MB.',
            'file.mimes' => 'The file must be a picture (JPG or PNG), a PDF or a Word document.',
            'file.extensions' => 'The file\'s name must end in .jpg, .jpeg, .jpe, .jfif, .png, .pdf, .doc or .docx.',
            'type.required' => 'Choose the document type.',
            'type.string' => 'The document type must be text.',
            'type.max' => 'The document type can be at most 50 characters.',
            'type.regex' => 'The document type may only use letters, numbers, spaces, hyphens and underscores.',
            'label.string' => 'The label must be text.',
            'label.max' => 'The label can be at most 255 characters.',
        ];
    }
}
