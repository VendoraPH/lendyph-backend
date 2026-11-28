<?php

namespace App\Http\Requests\Cic;

use App\Services\Cic\CicAccess;

/** POST /cic/public-keys/{key}/verify: the fingerprint checked with CIC. */
class VerifyCicPublicKeyRequest extends CicFormRequest
{
    protected function permission(): string
    {
        return CicAccess::SETTINGS;
    }

    public function rules(): array
    {
        return ['fingerprint' => ['required', 'string', 'max:200']];
    }

    public function messages(): array
    {
        return ['fingerprint.required' => 'Enter the fingerprint you checked with CIC.'];
    }

    public function attributes(): array
    {
        return ['fingerprint' => 'fingerprint'];
    }
}
