<?php

namespace App\Http\Requests\Cic;

use App\Services\Cic\CicAccess;
use App\Services\Cic\CicSwitch;
use Illuminate\Validation\Validator;

/**
 * PUT /cic/settings. Partial. The provider code is CIC's 8 letters or digits
 * (format 1.0), stored in upper case; the format version can't be changed.
 */
class UpdateCicSettingsRequest extends CicFormRequest
{
    protected function permission(): string
    {
        return CicAccess::SETTINGS;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('provider_code'))) {
            $this->merge(['provider_code' => strtoupper(trim($this->input('provider_code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'provider_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{8}$/'],
            'is_mfi' => ['sometimes', 'boolean'],
            'institution_name' => ['sometimes', 'nullable', 'string', 'max:160', self::PLAIN_TEXT],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120', self::PLAIN_TEXT],
            'contact_email' => ['sometimes', 'nullable', 'string', 'max:120', 'email:rfc'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[0-9+()\- .]+$/'],
            'format_version' => ['sometimes', 'nullable'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->has('format_version') && (string) $this->input('format_version') !== CicSwitch::formatVersion()) {
                $validator->errors()->add('format_version', 'The format version is fixed at '.CicSwitch::formatVersion().' (CIC\'s 2016 CSDF) and can\'t be changed.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'provider_code.regex' => 'The provider code is the 8 letters or digits CIC assigned to the lender, with no spaces or symbols.',
            'contact_phone.regex' => 'The contact phone may hold only digits, spaces and + ( ) - . characters.',
        ];
    }

    public function attributes(): array
    {
        return [
            'provider_code' => 'provider code',
            'is_mfi' => 'MFI status',
            'institution_name' => 'institution name',
            'contact_name' => 'contact name',
            'contact_email' => 'contact email',
            'contact_phone' => 'contact phone',
        ];
    }

    /** @return array<string, mixed> the fields to save */
    public function changes(): array
    {
        $changes = $this->safe()->except('format_version');

        foreach (['institution_name', 'contact_name', 'contact_email', 'contact_phone', 'provider_code'] as $field) {
            if (array_key_exists($field, $changes) && is_string($changes[$field])) {
                $changes[$field] = trim($changes[$field]) === '' ? null : trim($changes[$field]);
            }
        }

        return $changes;
    }
}
