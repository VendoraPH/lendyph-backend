<?php

namespace App\Http\Requests\Cic;

use App\Services\Cic\CicAccess;

/** POST /cic/public-keys/{key}/retire: why the key is retired. */
class RetireCicPublicKeyRequest extends CicFormRequest
{
    protected function permission(): string
    {
        return CicAccess::SETTINGS;
    }

    public function rules(): array
    {
        return ['reason' => self::reasonRules()];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Give a reason for retiring the key.'];
    }

    public function attributes(): array
    {
        return ['reason' => 'reason'];
    }
}
