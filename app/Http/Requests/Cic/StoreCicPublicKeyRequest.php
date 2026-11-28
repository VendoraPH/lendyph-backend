<?php

namespace App\Http\Requests\Cic;

use App\Services\Cic\CicAccess;
use App\Services\Cic\Settings\CicKeyInspector;

/**
 * POST /cic/public-keys: CIC's public key, as its armored text (`armored`)
 * or the .asc file (`file`), at most 64 KB.
 */
class StoreCicPublicKeyRequest extends CicFormRequest
{
    protected function permission(): string
    {
        return CicAccess::SETTINGS;
    }

    public function rules(): array
    {
        return [
            'armored' => ['required_without:file', 'nullable', 'string', 'max:'.CicKeyInspector::MAX_BYTES],
            'file' => ['required_without:armored', 'nullable', 'file', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'armored.required_without' => 'Paste CIC\'s public key, or choose the .asc file CIC sent.',
            'file.required_without' => 'Paste CIC\'s public key, or choose the .asc file CIC sent.',
            'armored.max' => CicKeyInspector::TOO_LARGE,
            'file.max' => CicKeyInspector::TOO_LARGE,
        ];
    }

    public function attributes(): array
    {
        return ['armored' => 'key', 'file' => 'key file'];
    }

    /** The field the key came in, for the refusal. */
    public function keyField(): string
    {
        return $this->hasFile('file') ? 'file' : 'armored';
    }

    public function keyText(): string
    {
        return $this->hasFile('file')
            ? (string) file_get_contents((string) $this->file('file')->getRealPath())
            : (string) $this->input('armored');
    }
}
