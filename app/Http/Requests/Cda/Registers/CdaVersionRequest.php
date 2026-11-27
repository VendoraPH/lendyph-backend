<?php

namespace App\Http\Requests\Cda\Registers;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Services\Cda\CdaAccess;

/**
 * POST /cda/documents/{id}/versions (`cda:upload`), multipart: a new file,
 * never replacing an older one, with an optional label and notes; `final`
 * makes it the document's one final version.
 */
class CdaVersionRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::UPLOAD;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => CdaUploadRules::file(), ...self::versionRules()];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...CdaUploadRules::messages('file'), ...self::versionMessages()];
    }

    /** @return array<string, list<string>> */
    public static function versionRules(): array
    {
        return [
            'version_label' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'final' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function versionMessages(): array
    {
        return [
            'version_label.max' => 'The version label can be at most 60 characters.',
            'notes.max' => 'The notes can be at most 2,000 characters.',
            'final.boolean' => 'Say whether this is the final version (yes or no).',
        ];
    }
}
